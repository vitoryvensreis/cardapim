<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Repositories\SuporteRepository;
use Cardapim\Services\Suporte\SuporteService;

/** Aba Suporte do gerente: abrir chamado, ver a conversa, responder e marcar como resolvido (so do proprio restaurante). */
final class SuporteController extends BaseGerencia
{
    public function lista(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $repo = new SuporteRepository($this->db);
        $filtro = in_array($_GET['filtro'] ?? '', ['resolvidos', 'todos'], true) ? (string) $_GET['filtro'] : 'abertos';
        return $this->tela('gerencia/suporte/lista', 'suporte', 'Suporte', [
            'chamados' => $repo->doRestaurante((int) $this->restaurante['id'], $filtro),
            'contagem' => $repo->contagemRestaurante((int) $this->restaurante['id']),
            'filtro' => $filtro,
            'pageStyles' => ['css/suporte.css'],
        ]);
    }

    public function novo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->form([], []);
    }

    public function criar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $res = $this->servico()->abrir((int) $this->restaurante['id'], (string) $this->restaurante['nome'], (string) $this->restaurante['slug'], $_POST);
        if (!$res->ok) {
            return $this->form(['assunto' => $this->post('assunto'), 'categoria' => $this->post('categoria'), 'mensagem' => $this->post('mensagem')], $res->erros, 422);
        }
        return $this->responder($res, $this->url('suporte/' . (int) $res->id));
    }

    public function ver(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $repo = new SuporteRepository($this->db);
        $c = self::id($id) !== null ? $repo->buscar((int) $id, (int) $this->restaurante['id']) : null;
        if ($c === null) {
            return $this->voltar($this->url('suporte'), 'erro', 'Chamado não encontrado.');
        }
        $repo->marcarVisto((int) $c['id'], 'restaurante');
        return $this->tela('gerencia/suporte/ver', 'suporte', 'Chamado #' . (int) $c['id'], [
            'chamado' => $c,
            'mensagens' => $repo->mensagens((int) $c['id']),
            'pageStyles' => ['css/suporte.css'],
            'pageScripts' => ['js/forms.js'],
        ]);
    }

    public function responderChamado(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idChamado = self::id($id) ?? 0;
        $res = $this->servico()->responderRestaurante((int) $this->restaurante['id'], $idChamado, (string) ($_POST['mensagem'] ?? ''));
        return $this->responder($res, $this->url('suporte/' . $idChamado) . '#fim');
    }

    public function resolver(string $slug, string $id): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $idChamado = self::id($id) ?? 0;
        return $this->responder($this->servico()->resolverRestaurante((int) $this->restaurante['id'], $idChamado), $this->url('suporte/' . $idChamado));
    }

    /** @param array<string,string> $valores @param array<string,string> $erros */
    private function form(array $valores, array $erros, int $status = 200): Response
    {
        return $this->tela('gerencia/suporte/novo', 'suporte', 'Abrir chamado', [
            'valores' => $valores + ['assunto' => '', 'categoria' => '', 'mensagem' => ''],
            'erros' => $erros,
            'pageStyles' => ['css/suporte.css'],
            'pageScripts' => ['js/forms.js'],
        ], $status);
    }

    private function servico(): SuporteService
    {
        return new SuporteService($this->db, $this->usuario);
    }
}
