<?php
declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Fiscal\FiscalEmissao;
use Cardapim\Services\Fiscal\GestaoNotas;
use Cardapim\Services\Gerencia\FiscalAcesso;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Services\Impressao\DanfeNfce;
use Throwable;

/** Gerência > Nota fiscal > Notas emitidas. Só para restaurantes com o módulo fiscal liberado. */
final class FiscalNotasController extends BaseGerencia
{
    private function abrir(string $slug, bool $csrf = false): ?Response
    {
        if ($r = $this->entrar($slug, $csrf)) { return $r; }
        if (!FiscalAcesso::permitido($this->db, $this->restaurante['id']) || !GestaoNotas::disponivel($this->db)) {
            return Guard::proibido($this->usuario, 'Módulo indisponível para este restaurante.');
        }
        return null;
    }

    private function servico(): GestaoNotas
    {
        return new GestaoNotas($this->db, $this->usuario, $this->restaurante['id']);
    }

    private function lista(): string
    {
        return $this->url('fiscal/notas');
    }

    public function inicio(string $slug): Response
    {
        if ($r = $this->abrir($slug)) { return $r; }
        $texto = static fn (string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
        $filtro = ['status' => $texto('status'), 'mes' => $texto('mes'), 'busca' => $texto('busca'), 'pagina' => (int) $texto('pagina')];
        $fiscal = (new \Cardapim\Repositories\Gerencia\FiscalRepository($this->db))->buscar($this->restaurante['id']);
        $amb = (int) ($fiscal['dados']['ambiente'] ?? 3);
        $cfg = $amb === 3 ? [] : ($fiscal['dados']['config'][$amb] ?? []);
        return $this->tela('gerencia/fiscal/notas', 'fiscal', 'Notas emitidas', [
            'dados' => $this->servico()->lista(array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 60) : $v, $filtro)),
            'ambiente' => $amb, 'seriePadrao' => (int) ($cfg['serie'] ?? 1),
            'pageStyles' => ['css/gerencia-fiscal.css'],
        ]);
    }

    public function ver(string $slug, string $id): Response
    {
        if ($r = $this->abrir($slug)) { return $r; }
        $d = self::id($id) !== null ? $this->servico()->detalhe((int) $id) : null;
        if ($d === null) { return $this->voltar($this->lista(), 'erro', 'Nota não encontrada.'); }
        return $this->tela('gerencia/fiscal/nota', 'fiscal', 'Nota fiscal', ['d' => $d, 'pageStyles' => ['css/gerencia-fiscal.css']]);
    }

    private function acao(string $slug, string $id, callable $fn): Response
    {
        if ($r = $this->abrir($slug, true)) { return $r; }
        $idDoc = self::id($id);
        $volta = $idDoc !== null ? $this->url('fiscal/notas/' . $idDoc) : $this->lista();
        if ($idDoc === null) { return $this->voltar($this->lista(), 'erro', 'Nota não encontrada.'); }
        try {
            return $this->responder($fn($idDoc), $volta);
        } catch (Throwable $e) {
            error_log('[Cardapim] Notas fiscais: falha na operação (' . get_class($e) . ').');
            return $this->voltar($volta, 'erro', \Cardapim\Modules\Fiscal\MensagemFiscal::para($e, 'Não foi possível concluir agora. Tente de novo em instantes.'));
        }
    }

    public function cancelar(string $slug, string $id): Response
    {
        return $this->acao($slug, $id, fn (int $i): Resultado => $this->servico()->cancelar($i, $this->post('justificativa')));
    }

    public function email(string $slug, string $id): Response
    {
        return $this->acao($slug, $id, fn (int $i): Resultado => $this->servico()->email($i, $this->post('email')));
    }

    public function imprimir(string $slug, string $id): Response
    {
        return $this->acao($slug, $id, fn (int $i): Resultado => DanfeNfce::enfileirar($this->db, $this->restaurante['id'], $i, $this->usuario->id, false));
    }

    public function reenviar(string $slug, string $id): Response
    {
        return $this->acao($slug, $id, function (int $i): Resultado {
            $d = $this->servico()->detalhe($i);
            if ($d === null || FiscalEmissao::pronto($this->db, $this->restaurante['id']) === null) {
                return Resultado::falha('A emissão de nota fiscal não está ligada para este restaurante.');
            }
            return FiscalEmissao::reenviar($this->db, $this->restaurante['id'], (int) $d['doc']['id_pedido'])
                ? Resultado::sucesso('Nota enviada de novo para a fila. Ela sai em alguns segundos.')
                : Resultado::falha('Esta nota não precisa ser reenviada agora.');
        });
    }

    public function inutilizar(string $slug): Response
    {
        if ($r = $this->abrir($slug, true)) { return $r; }
        try {
            return $this->responder($this->servico()->inutilizar($this->post('serie'), $this->post('inicio'), $this->post('fim'), $this->post('justificativa')), $this->lista());
        } catch (Throwable $e) {
            error_log('[Cardapim] Notas fiscais: falha ao inutilizar (' . get_class($e) . ').');
            return $this->voltar($this->lista(), 'erro', \Cardapim\Modules\Fiscal\MensagemFiscal::para($e, 'Não foi possível inutilizar agora. Tente de novo em instantes.'));
        }
    }

    public function xml(string $slug, string $id): Response
    {
        if ($r = $this->abrir($slug)) { return $r; }
        try { $a = self::id($id) !== null ? $this->servico()->xml((int) $id) : null; } catch (Throwable) { $a = null; }
        if ($a === null) { return $this->voltar($this->lista(), 'erro', 'XML indisponível para esta nota.'); }
        return self::arquivo($a['conteudo'], 'application/xml; charset=UTF-8', $a['nome']);
    }

    public function pdf(string $slug, string $id): Response
    {
        if ($r = $this->abrir($slug)) { return $r; }
        try { $a = self::id($id) !== null ? $this->servico()->pdf((int) $id) : null; } catch (Throwable) { $a = null; }
        if ($a === null) { return $this->voltar($this->url('fiscal/notas/' . (int) $id), 'erro', 'O PDF da nota não está disponível agora. Tente de novo em instantes.'); }
        return self::arquivo($a['conteudo'], 'application/pdf', $a['nome'], 'inline');
    }

    public function exportar(string $slug): Response
    {
        if ($r = $this->abrir($slug)) { return $r; }
        $mes = is_string($_GET['mes'] ?? null) ? $_GET['mes'] : '';
        $a = $this->servico()->exportar($mes);
        if ($a === null) { return $this->voltar($this->lista(), 'erro', 'Escolha um mês válido para exportar.'); }
        if ($a['quantidade'] === 0) { return $this->voltar($this->lista() . '?mes=' . rawurlencode($mes), 'aviso', 'Nenhuma nota de produção (com valor fiscal) neste mês para exportar.'); }
        return self::arquivo($a['conteudo'], 'application/zip', $a['nome']);
    }

    private static function arquivo(string $conteudo, string $tipo, string $nome, string $modo = 'attachment'): Response
    {
        $nome = preg_replace('/[^A-Za-z0-9._-]/', '', $nome) ?: 'arquivo';
        return new Response($conteudo, 200, [
            'Content-Type' => $tipo, 'Content-Disposition' => $modo . '; filename="' . $nome . '"',
            'Cache-Control' => 'private, no-store', 'Content-Length' => (string) strlen($conteudo),
        ]);
    }
}
