<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Caixa;

use Cardapim\Core\Response;
use Cardapim\Repositories\Caixa\CaixaRepository;
use Cardapim\Services\Caixa\CaixaService;

/** Resumo do caixa, abrir, fechar (contagem cega), entradas/saidas e historico de fechamentos. */
final class CaixaController extends BaseCaixa
{
    public function resumo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $servico = new CaixaService($this->db, $this->restaurante['id'], $this->usuario);
        $caixa = $servico->aberto();
        $repo = new CaixaRepository($this->db);
        return $this->tela('caixa/resumo', 'caixa', 'Caixa', [
            'caixa' => $caixa,
            'resumo' => $caixa !== null ? $servico->resumo($caixa) : null,
            'movimentos' => $caixa !== null ? array_slice($repo->movimentos($this->restaurante['id'], (int) $caixa['id']), 0, 5) : [],
            'pagamentos' => $caixa !== null ? array_slice($repo->pagamentos($this->restaurante['id'], (int) $caixa['id']), 0, 8) : [],
            'ultimo' => $caixa === null ? ($repo->historico($this->restaurante['id'], 1)[0] ?? null) : null,
        ]);
    }

    public function abrir(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = (new CaixaService($this->db, $this->restaurante['id'], $this->usuario))->abrir($this->post('valor_abertura'));
        return $this->responder($res, $this->url());
    }

    public function fecharForm(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $caixa = (new CaixaService($this->db, $this->restaurante['id'], $this->usuario))->aberto();
        if ($caixa === null) {
            return $this->voltar($this->url(), 'aviso', 'O caixa já está fechado.');
        }
        $q = $this->db->prepare("SELECT COUNT(*) FROM pedidos WHERE id_restaurante = ? AND status = 'aberto'");
        $q->execute([$this->restaurante['id']]);
        return $this->tela('caixa/fechar', 'caixa', 'Fechar caixa', ['caixa' => $caixa, 'comandasAbertas' => (int) $q->fetchColumn()]);
    }

    public function fechar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = (new CaixaService($this->db, $this->restaurante['id'], $this->usuario))->fechar($this->post('dinheiro_contado'), $this->post('observacao'));
        return $this->responder($res, $res->id !== null ? $this->url('historico/' . $res->id) : $this->url(), $this->url('fechar'));
    }

    public function movimentos(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $servico = new CaixaService($this->db, $this->restaurante['id'], $this->usuario);
        $caixa = $servico->aberto();
        $tipo = ($_GET['tipo'] ?? '') === 'entrada' ? 'entrada' : 'saida';
        return $this->tela('caixa/movimentos', 'movimentos', 'Entradas e saídas', [
            'caixa' => $caixa,
            'tipo' => $tipo,
            'resumo' => $caixa !== null ? $servico->resumo($caixa) : null,
            'movimentos' => $caixa !== null ? (new CaixaRepository($this->db))->movimentos($this->restaurante['id'], (int) $caixa['id']) : [],
        ]);
    }

    public function registrarMovimento(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $tipo = $this->post('tipo');
        $res = (new CaixaService($this->db, $this->restaurante['id'], $this->usuario))
            ->registrarMovimento($tipo, $this->post('categoria'), $this->post('valor'), $this->post('descricao'));
        return $this->responder($res, $this->url('movimentos?tipo=' . ($tipo === 'entrada' ? 'entrada' : 'saida')), $this->url('movimentos?tipo=' . ($tipo === 'entrada' ? 'entrada' : 'saida')));
    }

    public function historico(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->tela('caixa/historico', 'historico', 'Fechamentos', ['caixas' => (new CaixaRepository($this->db))->historico($this->restaurante['id'])]);
    }

    public function historicoVer(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $repo = new CaixaRepository($this->db);
        $caixa = self::id($id) !== null ? $repo->buscar($this->restaurante['id'], (int) $id) : null;
        if ($caixa === null) {
            return $this->voltar($this->url('historico'), 'erro', 'Caixa não encontrado.');
        }
        $servico = new CaixaService($this->db, $this->restaurante['id'], $this->usuario);
        return $this->tela('caixa/historico-ver', 'historico', 'Caixa de ' . date('d/m H:i', (int) strtotime((string) $caixa['aberto_em'])), [
            'caixa' => $caixa,
            'resumo' => $servico->resumo($caixa),
            'movimentos' => $repo->movimentos($this->restaurante['id'], (int) $caixa['id']),
            'pagamentos' => $repo->pagamentos($this->restaurante['id'], (int) $caixa['id']),
        ]);
    }
}
