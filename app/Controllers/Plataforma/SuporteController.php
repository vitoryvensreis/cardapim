<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Repositories\SuporteRepository;
use Cardapim\Services\Suporte\SuporteService;

/** Suporte da plataforma (/painel/suporte): chamados de todos os restaurantes, responder e mudar a situacao. */
final class SuporteController extends BasePlataforma
{
    private const FILTROS = ['aguardando', 'abertos', 'resolvidos', 'todos'];

    public function lista(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new SuporteRepository($this->db);
        $filtro = in_array($_GET['filtro'] ?? '', self::FILTROS, true) ? (string) $_GET['filtro'] : 'abertos';
        $busca = mb_substr(trim(is_string($_GET['busca'] ?? null) ? $_GET['busca'] : ''), 0, 80);
        $pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
        $res = $repo->daPlataforma($filtro, $busca, $pagina);
        return $this->tela('plataforma/suporte/lista', 'suporte', 'Suporte', [
            'chamados' => $res['itens'],
            'total' => $res['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($res['total'] / SuporteRepository::POR_PAGINA)),
            'filtros' => ['filtro' => $filtro, 'busca' => $busca],
            'contagem' => $repo->contagemPlataforma(),
            'pageStyles' => ['css/plataforma-gestao.css', 'css/suporte.css'],
        ]);
    }

    public function ver(string $id): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new SuporteRepository($this->db);
        $c = ctype_digit($id) && strlen($id) <= 18 ? $repo->buscar((int) $id, null) : null;
        if ($c === null) {
            return $this->voltar('/painel/suporte', 'erro', 'Chamado não encontrado.');
        }
        $repo->marcarVisto((int) $c['id'], 'suporte');
        return $this->tela('plataforma/suporte/ver', 'suporte', 'Chamado #' . (int) $c['id'], [
            'chamado' => $c,
            'mensagens' => $repo->mensagens((int) $c['id']),
            'pageStyles' => ['css/plataforma-gestao.css', 'css/suporte.css'],
            'pageScripts' => ['js/forms.js'],
        ]);
    }

    public function responderChamado(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $idChamado = ctype_digit($id) ? (int) $id : 0;
        $res = (new SuporteService($this->db, $this->usuario))->responderSuporte($idChamado, (string) ($_POST['mensagem'] ?? ''), ($_POST['resolver'] ?? '') === '1');
        return $this->responder($res, '/painel/suporte/' . $idChamado . '#fim');
    }

    public function status(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $idChamado = ctype_digit($id) ? (int) $id : 0;
        return $this->responder((new SuporteService($this->db, $this->usuario))->mudarStatus($idChamado, $this->post('status')), '/painel/suporte/' . $idChamado);
    }
}
