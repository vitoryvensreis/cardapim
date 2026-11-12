<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Repositories\Plataforma\RestauranteAdminRepository;
use Cardapim\Services\Plataforma\AssinaturaCobranca;
use Cardapim\Services\Plataforma\RestauranteAdminService;
use Cardapim\Shared\Validation\Documento;

/** Restaurantes da plataforma (/painel/restaurantes): lista, cadastrar, ver/editar, situacao, plano e excluir. */
final class RestaurantesController extends BasePlataforma
{
    public function lista(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new RestauranteAdminRepository($this->db);
        $busca = mb_substr(trim(is_string($_GET['busca'] ?? null) ? $_GET['busca'] : ''), 0, 80);
        $status = in_array($_GET['status'] ?? '', RestauranteAdminRepository::STATUS, true) ? $_GET['status'] : null;
        $plano = ctype_digit((string) ($_GET['plano'] ?? '')) ? (int) $_GET['plano'] : null;
        $pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
        $res = $repo->listar($busca, $status, $plano, $pagina);
        return $this->tela('plataforma/restaurantes/lista', 'restaurantes', 'Restaurantes', [
            'restaurantes' => $res['itens'],
            'total' => $res['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($res['total'] / RestauranteAdminRepository::POR_PAGINA)),
            'filtros' => ['busca' => $busca, 'status' => $status, 'plano' => $plano],
            'contagem' => $repo->contagemPorStatus(),
            'planos' => (new PlanoRepository($this->db))->listarTodos(),
            'pageStyles' => ['css/plataforma-gestao.css'],
        ]);
    }

    public function novo(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->formNovo([], []);
    }

    public function criar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $res = (new RestauranteAdminService($this->db, $this->usuario))->criar($_POST);
        if (!$res->ok) {
            $valores = array_map(static fn ($v): string => is_string($v) ? $v : '', array_diff_key($_POST, ['senha' => 1, '_csrf' => 1]));
            return $this->formNovo($valores, $res->erros, 422);
        }
        return $this->responder($res, '/painel/restaurantes/' . (int) $res->id);
    }

    public function ver(string $id): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $rest = $this->restaurante($id);
        if ($rest === null) {
            return $this->voltar('/painel/restaurantes', 'erro', 'Restaurante não encontrado.');
        }
        return $this->telaVer($rest, self::valoresDe($rest), []);
    }

    public function salvar(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $rest = $this->restaurante($id);
        if ($rest === null) {
            return $this->voltar('/painel/restaurantes', 'erro', 'Restaurante não encontrado.');
        }
        $res = (new RestauranteAdminService($this->db, $this->usuario))->salvarDados((int) $rest['id'], $_POST);
        if (!$res->ok) {
            $valores = array_map(static fn ($v): string => is_string($v) ? $v : '', $_POST);
            return $this->telaVer($rest, $valores, $res->erros, 422);
        }
        return $this->responder($res, '/painel/restaurantes/' . (int) $rest['id']);
    }

    public function status(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $rest = $this->restaurante($id);
        if ($rest === null) {
            return $this->voltar('/painel/restaurantes', 'erro', 'Restaurante não encontrado.');
        }
        $res = (new RestauranteAdminService($this->db, $this->usuario))->mudarStatus((int) $rest['id'], $this->post('status'), $this->post('motivo'));
        return $this->responder($res, '/painel/restaurantes/' . (int) $rest['id'] . '#situacao');
    }

    public function plano(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $rest = $this->restaurante($id);
        if ($rest === null) {
            return $this->voltar('/painel/restaurantes', 'erro', 'Restaurante não encontrado.');
        }
        $planoId = ctype_digit($this->post('plano_id')) ? (int) $this->post('plano_id') : 0;
        $res = (new RestauranteAdminService($this->db, $this->usuario))->trocarPlano((int) $rest['id'], $planoId);
        return $this->responder($res, '/painel/restaurantes/' . (int) $rest['id'] . '#plano');
    }

    public function excluir(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $rest = $this->restaurante($id);
        if ($rest === null) {
            return $this->voltar('/painel/restaurantes', 'erro', 'Restaurante não encontrado. Ele pode já ter sido excluído.');
        }
        $res = (new RestauranteAdminService($this->db, $this->usuario))->excluir((int) $rest['id'], $this->post('confirmacao'));
        return $this->responder($res, $res->ok ? '/painel/restaurantes' : '/painel/restaurantes/' . (int) $rest['id'] . '#excluir');
    }

    /** @param array<string,string> $valores @param array<string,string> $erros */
    private function formNovo(array $valores, array $erros, int $codigo = 200): Response
    {
        return $this->tela('plataforma/restaurantes/novo', 'restaurantes', 'Novo restaurante', [
            'valores' => $valores,
            'erros' => $erros,
            'planos' => array_values(array_filter((new PlanoRepository($this->db))->listarTodos(), static fn (array $p): bool => (int) $p['ativo'] === 1)),
            'asaasConectado' => (new AssinaturaCobranca($this->db))->conta() !== null,
            'pageStyles' => ['css/plataforma-gestao.css'],
            'pageScripts' => ['js/forms.js', 'js/equipe.js'],
        ], $codigo);
    }

    /** @param array<string,mixed> $rest @param array<string,string> $valores @param array<string,string> $erros */
    private function telaVer(array $rest, array $valores, array $erros, int $codigo = 200): Response
    {
        $repo = new RestauranteAdminRepository($this->db);
        $movimento = $repo->movimento((int) $rest['id']);
        return $this->tela('plataforma/restaurantes/ver', 'restaurantes', (string) $rest['nome'], [
            'rest' => $rest,
            'valores' => $valores,
            'erros' => $erros,
            'contas' => $repo->contas((int) $rest['id']),
            'movimento' => $movimento,
            'bloqueioExcluir' => RestauranteAdminService::motivoNaoExcluir($movimento),
            'assinatura' => (new AssinaturaRepository($this->db))->vigente((int) $rest['id']),
            'planos' => array_values(array_filter((new PlanoRepository($this->db))->listarTodos(), static fn (array $p): bool => (int) $p['ativo'] === 1)),
            'pageStyles' => ['css/plataforma-gestao.css'],
            'pageScripts' => ['js/forms.js'],
        ], $codigo);
    }

    /** @return array<string,mixed>|null */
    private function restaurante(string $id): ?array
    {
        return ctype_digit($id) && strlen($id) <= 18 ? (new RestauranteAdminRepository($this->db))->buscar((int) $id) : null;
    }

    /** @param array<string,mixed> $r @return array<string,string> */
    private static function valoresDe(array $r): array
    {
        $v = [];
        foreach (['nome', 'email', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado', 'ponto_referencia'] as $c) {
            $v[$c] = (string) ($r[$c] ?? '');
        }
        $v['telefone'] = $r['telefone'] ? Documento::formatarTelefone((string) $r['telefone']) : '';
        $v['cnpj'] = $r['cnpj'] ? Documento::formatarCnpj((string) $r['cnpj']) : '';
        $v['cep'] = $r['cep'] ? substr((string) $r['cep'], 0, 5) . '-' . substr((string) $r['cep'], 5) : '';
        return $v;
    }
}
