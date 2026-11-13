<?php

declare(strict_types=1);

namespace Cardapim\Controllers;

use Cardapim\Core\Database;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Core\View;
use Cardapim\Modules\Inventory\DisponibilidadeProdutos;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Services\Auth\AcessoRestaurante;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Caixa\CaixaService;
use Cardapim\Services\Caixa\ComandaService;
use Cardapim\Services\Caixa\MesaService;
use Cardapim\Services\Cardapio\CardapioPublico;
use Cardapim\Services\Garcom\ComandaPix;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use PDO;

/**
 * Painel do garcom (/{slug}/garcom), feito para o celular:
 * mesas -> comanda (lancar itens; uma mesa pode ter varias comandas, uma por pessoa) -> fechar conta (10% opcional, receber ou Pix com QR Code) e consulta do cardapio.
 * Acesso: garcom e gerente do restaurante (ou adm_master), conferido a cada requisicao. Todo POST com CSRF.
 * As regras de comanda e pagamento sao as mesmas do modo caixa (ComandaService).
 */
final class GarcomController
{
    private const MAX_PRODUTOS_POR_ENVIO = 40;

    private UsuarioLogado $usuario;
    /** @var array{id:int,nome:string,slug:string,status:string} */
    private array $restaurante;
    private PDO $db;

    public function mesas(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $mesas = array_values(array_filter(
            (new MesaService($this->db, $this->restaurante['id'], $this->usuario))->listar(),
            static fn (array $m): bool => (int) $m['ativo'] === 1,
        ));
        return $this->tela('garcom/mesas', 'mesas', 'Mesas', ['mesas' => $mesas]);
    }

    public function abrirMesa(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null ? $this->comandas()->abrirMesa((int) $id) : Resultado::falha('Mesa não encontrada.');
        if (!$res->ok || $res->id === null) {
            return $this->voltar($this->url(), 'erro', $res->mensagem);
        }
        return $this->voltar($this->url('comandas/' . $res->id), 'sucesso', '');
    }

    public function comanda(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $c = $this->detalhes($id);
        if ($c === null) {
            return $this->voltar($this->url(), 'erro', 'Conta não encontrada.');
        }
        if ($c['pedido']['status'] === 'cancelado') {
            return $this->voltar($this->url(), 'aviso', $c['rotulo'] . ': esta conta foi cancelada.');
        }
        if (!$c['aberta']) {
            return $this->voltar($this->url('comandas/' . (int) $c['pedido']['id'] . '/conta'), 'sucesso', '');
        }
        return $this->tela('garcom/comanda', 'mesas', $c['rotulo'], [
            'c' => $c,
            'cardapio' => $this->cardapio(),
            'voltar' => $this->url(),
            'subtitulo' => 'Pedido #' . (int) $c['pedido']['numero'],
        ]);
    }

    /** Lanca varios produtos de uma vez: qtd[id_produto] = quantidade, obs[id_produto] = observacao. */
    public function lancar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idComanda = self::id($id);
        $volta = $this->url('comandas/' . (int) $idComanda);
        $qtds = is_array($_POST['qtd'] ?? null) ? $_POST['qtd'] : [];
        $obs = is_array($_POST['obs'] ?? null) ? $_POST['obs'] : [];
        $pedidos = [];
        foreach ($qtds as $produto => $q) {
            $idProduto = self::id((string) $produto);
            $quantidade = is_string($q) && ctype_digit($q) ? (int) $q : 0;
            if ($idProduto !== null && $quantidade > 0) {
                $pedidos[$idProduto] = [min(99, $quantidade), is_string($obs[$produto] ?? null) ? (string) $obs[$produto] : ''];
            }
        }
        if ($idComanda === null || $pedidos === []) {
            return $this->voltar($volta, 'erro', 'Escolha pelo menos um produto.');
        }
        if (count($pedidos) > self::MAX_PRODUTOS_POR_ENVIO) {
            return $this->voltar($volta, 'erro', 'Lance até ' . self::MAX_PRODUTOS_POR_ENVIO . ' produtos diferentes de cada vez.');
        }
        $servico = $this->comandas();
        $lancados = 0;
        $falhas = [];
        foreach ($pedidos as $idProduto => [$quantidade, $observacao]) {
            $res = $servico->lancar($idComanda, $idProduto, $quantidade, $observacao);
            if ($res->ok) {
                $lancados += $quantidade;
            } else {
                $falhas[] = $res->mensagem;
            }
        }
        if ($falhas === []) {
            return $this->voltar($volta, 'sucesso', $lancados === 1 ? '1 item lançado.' : $lancados . ' itens lançados.');
        }
        $msg = ($lancados > 0 ? ($lancados === 1 ? '1 item lançado. ' : $lancados . ' itens lançados. ') : '') . implode(' ', array_slice($falhas, 0, 3));
        return $this->voltar($volta, $lancados > 0 ? 'aviso' : 'erro', $msg);
    }

    public function cancelarItem(string $slug, string $id, string $item): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null && self::id($item) !== null
            ? $this->comandas()->cancelarItem((int) $id, (int) $item, $this->post('motivo'))
            : Resultado::falha('Item não encontrado.');
        return $this->voltar($this->url('comandas/' . (int) $id), $res->ok ? 'sucesso' : 'erro', $res->mensagem);
    }

    /**
     * Cancela a comanda inteira (mesa aberta por engano, cliente foi embora...). Devolve o estoque e libera a mesa.
     * Conta com pagamento nao pode ser cancelada aqui: so o caixa remove pagamentos.
     */
    public function cancelarComanda(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $c = $this->detalhes($id);
        if ($c === null) {
            return $this->voltar($this->url(), 'erro', 'Conta não encontrada.');
        }
        $volta = $this->url('comandas/' . (int) $c['pedido']['id']);
        if (!$c['aberta']) {
            return $this->voltar($this->url(), 'erro', 'Esta conta já foi fechada.');
        }
        if ($c['pago'] > 0) {
            return $this->voltar($volta, 'erro', 'Esta conta já tem pagamento. Peça ao caixa para cancelar.');
        }
        $motivos = ['Mesa aberta por engano', 'Cliente foi embora', 'Cliente desistiu'];
        $motivo = in_array($this->post('motivo'), $motivos, true) ? $this->post('motivo') : 'Cancelada pelo garçom';
        $res = $this->comandas()->cancelar((int) $c['pedido']['id'], $motivo . ' (' . $this->usuario->nome . ')');
        if (!$res->ok) {
            return $this->voltar($volta, 'erro', $res->mensagem);
        }
        if ($res->id !== null) {
            // A mesa ainda tem outras comandas: vai direto para a proxima.
            return $this->voltar($this->url('comandas/' . $res->id), 'sucesso', $c['rotulo'] . ' cancelada.' . ($c['itens'] !== [] ? ' O estoque foi devolvido.' : '') . ' As outras comandas da mesa continuam abertas.');
        }
        return $this->voltar($this->url(), 'sucesso', $c['rotulo'] . ' cancelada. ' . ($c['itens'] !== [] ? 'O estoque foi devolvido e a mesa está livre.' : 'A mesa está livre.'));
    }

    /** Mais uma comanda na mesma mesa (quem chegou depois), com o nome da pessoa. */
    public function novaComanda(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null ? $this->comandas()->novaComanda((int) $id, $this->post('nome')) : Resultado::falha('Mesa não encontrada.');
        $volta = $this->post('voltar_comanda') !== '' && self::id($this->post('voltar_comanda')) !== null ? $this->url('comandas/' . (int) $this->post('voltar_comanda')) : $this->url();
        return $res->ok && $res->id !== null
            ? $this->voltar($this->url('comandas/' . $res->id), 'sucesso', $res->mensagem)
            : $this->voltar($volta, 'erro', $res->mensagem);
    }

    /** Da ou troca o nome da comanda (nome da pessoa). */
    public function renomear(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = self::id($id) !== null ? $this->comandas()->renomear((int) $id, $this->post('nome')) : Resultado::falha('Conta não encontrada.');
        return $this->voltar($this->url('comandas/' . (int) $id), $res->ok ? 'sucesso' : 'erro', $res->mensagem);
    }

    /** Fechar a conta: total, 10% e formas de pagamento. Conta ja paga = tela de "Pago, mesa liberada". */
    public function conta(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $c = $this->detalhes($id);
        if ($c === null) {
            return $this->voltar($this->url(), 'erro', 'Conta não encontrada.');
        }
        if ($c['pedido']['status'] === 'cancelado') {
            return $this->voltar($this->url(), 'aviso', $c['rotulo'] . ': esta conta foi cancelada.');
        }
        $erros = Session::flash('garcom_erros');
        $enviado = Session::flash('garcom_enviado');
        return $this->tela('garcom/conta', 'mesas', $c['rotulo'], [
            'c' => $c,
            'caixaAberto' => (new CaixaRepository($this->db))->aberto($this->restaurante['id']) !== null,
            'pixDisponivel' => $c['aberta'] && $this->pix()->disponivel(),
            'troco' => Session::flash('garcom_troco'),
            'erros' => is_array($erros) ? $erros : [],
            'enviado' => is_array($enviado) ? $enviado : [],
            'voltar' => $c['aberta'] ? $this->url('comandas/' . (int) $c['pedido']['id']) : $this->url(),
            'subtitulo' => $c['aberta'] ? 'Fechar conta' : 'Pedido #' . (int) $c['pedido']['numero'],
        ]);
    }

    /** Liga ou tira os 10% de servico (mantem o desconto que o caixa tiver dado). */
    public function servico(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $c = $this->detalhes($id);
        $volta = $this->url('comandas/' . (int) $id . '/conta');
        if ($c === null || !$c['aberta']) {
            return $this->voltar($volta, 'erro', 'Esta conta já foi fechada.');
        }
        $p = $c['pedido'];
        $ligar = $this->post('servico') === '1';
        $res = $this->comandas()->ajustar((int) $p['id'], $ligar, Numero::dinheiroCampo((string) $p['desconto']), (string) ($p['motivo_desconto'] ?? ''), $p['pessoas'] !== null ? (string) $p['pessoas'] : '');
        return $this->voltar($volta, $res->ok ? 'sucesso' : 'erro', $res->ok ? ($ligar ? '10% de serviço incluído.' : '10% de serviço retirado.') : $res->mensagem);
    }

    public function pagar(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idComanda = (int) self::id($id);
        $volta = $this->url('comandas/' . $idComanda . '/conta');
        $forma = $this->post('forma');
        $res = $this->comandas()->pagar($idComanda, $forma, $this->post('valor'), $forma === 'dinheiro' ? $this->post('recebido') : '');
        if (!$res->ok) {
            if ($res->erros !== []) {
                Session::set('garcom_erros', $res->erros);
                Session::set('garcom_enviado', ['forma' => $forma, 'valor' => mb_substr($this->post('valor'), 0, 20), 'recebido' => mb_substr($this->post('recebido'), 0, 20)]);
            }
            return $this->voltar($volta, 'erro', $res->mensagem);
        }
        $q = $this->db->prepare('SELECT troco, valor FROM pedido_pagamentos WHERE id_pedido = ? AND id_restaurante = ? ORDER BY id DESC LIMIT 1');
        $q->execute([$idComanda, $this->restaurante['id']]);
        $ultimo = $q->fetch(PDO::FETCH_ASSOC);
        if ($ultimo !== false && Numero::centavos((string) $ultimo['troco']) > 0) {
            Session::set('garcom_troco', ['troco' => Numero::centavos((string) $ultimo['troco']), 'recebido' => Numero::centavos((string) $ultimo['troco']) + Numero::centavos((string) $ultimo['valor'])]);
        }
        return $this->voltar($volta, 'sucesso', $res->mensagem);
    }

    /** Gera o Pix (QR Code) do que falta pagar na conta Asaas do restaurante. */
    public function gerarPix(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $c = $this->detalhes($id);
        $volta = $this->url('comandas/' . (int) $id . '/conta');
        if ($c === null) {
            return $this->voltar($this->url(), 'erro', 'Conta não encontrada.');
        }
        $res = $this->pix()->gerar($c, $this->usuario->id, $this->restaurante['nome']);
        if (!$res->ok || $res->id === null) {
            return $this->voltar($volta, 'erro', $res->mensagem);
        }
        return $this->voltar($this->url('pix/' . $res->id), 'sucesso', '');
    }

    /** Tela do QR Code. Com Accept: application/json devolve so a situacao (a tela consulta a cada 3 s). */
    public function verPix(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $pix = self::id($id) !== null ? $this->pix()->acompanhar((int) $id) : null;
        if ($pix === null) {
            return $json ? Response::json(['erro' => 'Pix não encontrado.'], 404, ['Cache-Control' => 'no-store']) : $this->voltar($this->url(), 'erro', 'Pix não encontrado.');
        }
        $c = $this->comandas()->detalhes((int) $pix['id_pedido']);
        $urlConta = $this->url('comandas/' . (int) $pix['id_pedido'] . '/conta');
        if ($json) {
            return Response::json([
                'status' => (string) $pix['status'],
                'conta_fechada' => $c !== null && !$c['aberta'],
                'url_conta' => $urlConta,
            ], 200, ['Cache-Control' => 'no-store']);
        }
        if ($pix['status'] !== 'pendente') {
            return $this->voltar($urlConta, $pix['status'] === 'pago' ? 'sucesso' : 'aviso',
                $pix['status'] === 'pago' ? 'Pix de ' . Numero::reais((string) $pix['valor']) . ' recebido.' : 'Este Pix não vale mais. Gere outro ou escolha outra forma.');
        }
        return $this->tela('garcom/pix', 'mesas', (string) ($c['rotulo'] ?? 'Mesa'), [
            'pix' => $pix,
            'c' => $c,
            'voltar' => $urlConta,
            'subtitulo' => 'QR Code Pix',
            'pageScripts' => ['vendor/qrcode-generator/qrcode.js'],
        ]);
    }

    public function cancelarPix(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $pix = self::id($id) !== null ? $this->pix()->buscar((int) $id) : null;
        if ($pix === null) {
            return $this->voltar($this->url(), 'erro', 'Pix não encontrado.');
        }
        $this->pix()->cancelar((int) $id);
        $atual = $this->pix()->buscar((int) $id);
        $volta = $this->url('comandas/' . (int) $pix['id_pedido'] . '/conta');
        if (($atual['status'] ?? '') === 'pago') {
            return $this->voltar($volta, 'sucesso', 'Este Pix já tinha sido pago.');
        }
        return $this->voltar($volta, 'sucesso', 'Pix cancelado. Escolha outra forma de pagamento.');
    }

    /** Consulta: preco, disponibilidade e quantas unidades ainda da para vender. */
    public function cardapioTela(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('garcom/cardapio', 'cardapio', 'Cardápio', ['cardapio' => $this->cardapio()]);
    }

    /* ---------- apoio ---------- */

    private function entrar(string $slug, bool $exigirCsrf = false): ?Response
    {
        $acesso = AcessoRestaurante::verificar($slug, 'garcom');
        if ($acesso instanceof Response) {
            return $acesso;
        }
        [$this->usuario, $this->restaurante] = $acesso;
        $this->db = Database::connect();
        \Cardapim\Services\Auditoria::noRestaurante($this->restaurante['nome']);
        if ($exigirCsrf && !Session::csrfValido($_POST['_csrf'] ?? null)) {
            return $this->voltar($this->url(), 'erro', 'Sua sessão expirou. Tente de novo.');
        }
        return null;
    }

    private function url(string $caminho = ''): string
    {
        return '/' . rawurlencode($this->restaurante['slug']) . '/garcom' . ($caminho !== '' ? '/' . ltrim($caminho, '/') : '');
    }

    private function voltar(string $url, string $tipo, string $mensagem): Response
    {
        if ($mensagem !== '') {
            Session::set('flash', ['tipo' => $tipo, 'mensagem' => $mensagem]);
        }
        return Response::redirect($url, 303);
    }

    /** @param array<string,mixed> $dados */
    private function tela(string $view, string $ativo, string $titulo, array $dados = []): Response
    {
        $flash = Session::flash('flash');
        return Guard::semCache(Response::html(View::render($view, [
            'pageTitle' => $titulo . ' · Garçom · ' . $this->restaurante['nome'] . ' | ' . marca(),
            'pageDescription' => 'Painel do garçom.',
            'noIndex' => true,
            'usuario' => $this->usuario,
            'csrf' => Session::tokenCsrf(),
            'restaurante' => $this->restaurante,
            'ativo' => $ativo,
            'tituloTela' => $titulo,
            'flash' => is_array($flash) ? $flash : null,
            'base' => $this->url(),
            'tempoReal' => TempoReal::ativo() ? '/api/v1/tempo-real/ticket?restaurante=' . rawurlencode($this->restaurante['slug']) : null,
            'voltar' => null,
            ...$dados,
        ], 'layouts/garcom')));
    }

    /** @return array<string,mixed>|null */
    private function detalhes(string $id): ?array
    {
        return self::id($id) !== null ? $this->comandas()->detalhes((int) $id) : null;
    }

    /**
     * Cardapio com disponibilidade e unidades restantes.
     * @return list<array{id:string,nome:string,produtos:list<array<string,mixed>>}>
     */
    private function cardapio(): array
    {
        $base = (new CardapioPublico($this->db))->categorias($this->restaurante['id']);
        $estoque = (new DisponibilidadeProdutos($this->db))->verificar($this->restaurante['id']);
        $categorias = [];
        foreach ($base['categorias'] as $cat) {
            foreach ($cat['produtos'] as &$p) {
                $d = $estoque[$p['id']] ?? null;
                $p['porcoes'] = $d['porcoes'] ?? null;
                $p['motivo'] = $d['motivo'] ?? null;
            }
            unset($p);
            $categorias[] = $cat;
        }
        return $categorias;
    }

    private function comandas(): ComandaService
    {
        return new ComandaService($this->db, $this->restaurante['id'], $this->usuario);
    }

    private function pix(): ComandaPix
    {
        return new ComandaPix($this->db, $this->restaurante['id']);
    }

    private function post(string $campo): string
    {
        $valor = $_POST[$campo] ?? '';
        return is_string($valor) ? trim($valor) : '';
    }

    private static function id(string $valor): ?int
    {
        return ctype_digit($valor) && strlen($valor) <= 18 && (int) $valor > 0 ? (int) $valor : null;
    }

    /** Valor em centavos -> "R$ 12,34" (atalho das views). */
    public static function reais(int $centavos): string
    {
        return Numero::reais(CaixaService::dec($centavos));
    }
}
