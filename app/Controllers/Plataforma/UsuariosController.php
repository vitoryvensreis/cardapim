<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Repositories\Plataforma\UsuarioAdminRepository;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Services\Plataforma\UsuarioAdminService;
use Cardapim\Shared\Validation\Documento;

/** Contas da plataforma (/painel/usuarios): lista, criar, editar, senha, bloquear, desligar 2FA e excluir. */
final class UsuariosController extends BasePlataforma
{
    private const SITUACOES = ['todos' => 'Todas', 'ativos' => 'Com acesso', 'bloqueados' => 'Bloqueadas'];

    public function lista(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new UsuarioAdminRepository($this->db);
        $busca = mb_substr(trim(is_string($_GET['busca'] ?? null) ? $_GET['busca'] : ''), 0, 80);
        $cargo = in_array($_GET['cargo'] ?? '', UsuarioAdminRepository::CARGOS, true) ? $_GET['cargo'] : null;
        $rest = ctype_digit((string) ($_GET['restaurante'] ?? '')) ? (int) $_GET['restaurante'] : null;
        $situacao = isset(self::SITUACOES[$_GET['situacao'] ?? '']) ? $_GET['situacao'] : 'todos';
        $pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
        $res = $repo->listar($busca, $cargo, $rest, $situacao, $pagina);
        return $this->tela('plataforma/usuarios/lista', 'usuarios', 'Usuários', [
            'usuarios' => $res['itens'],
            'total' => $res['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($res['total'] / UsuarioAdminRepository::POR_PAGINA)),
            'filtros' => ['busca' => $busca, 'cargo' => $cargo, 'restaurante' => $rest, 'situacao' => $situacao],
            'situacoes' => self::SITUACOES,
            'contagem' => $repo->contagemPorCargo(),
            'restaurantes' => $repo->restaurantes(),
            'pageStyles' => ['css/plataforma-gestao.css'],
        ]);
    }

    public function novo(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $cargo = in_array($_GET['cargo'] ?? '', UsuarioAdminRepository::CARGOS, true) ? (string) $_GET['cargo'] : '';
        $rest = ctype_digit((string) ($_GET['restaurante'] ?? '')) ? (string) $_GET['restaurante'] : '';
        return $this->form(null, ['cargo' => $cargo, 'id_restaurante' => $rest], []);
    }

    public function criar(): Response
    {
        return $this->salvarConta(null);
    }

    public function editar(string $id): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $u = $this->conta($id);
        if ($u === null) {
            return $this->voltar('/painel/usuarios', 'erro', 'Conta não encontrada.');
        }
        return $this->form($u, [
            'cargo' => (string) $u['cargo'],
            'id_restaurante' => $u['id_restaurante'] !== null ? (string) $u['id_restaurante'] : '',
            'nome' => (string) $u['nome'],
            'sobrenome' => (string) $u['sobrenome'],
            'usuario' => (string) $u['usuario'],
            'email' => EquipeService::semEmail((string) $u['email']) ? '' : (string) $u['email'],
            'whatsapp' => $u['whatsapp'] ? Documento::formatarTelefone((string) $u['whatsapp']) : '',
            'cpf' => $u['cpf'] ? Documento::formatarCpf((string) $u['cpf']) : '',
        ], []);
    }

    public function atualizar(string $id): Response
    {
        return $this->salvarConta($id);
    }

    public function senha(string $id): Response
    {
        return $this->acao($id, fn (UsuarioAdminService $s, int $i) => $s->redefinirSenha($i, is_string($_POST['senha'] ?? null) ? $_POST['senha'] : ''), '#senha');
    }

    public function acesso(string $id): Response
    {
        return $this->acao($id, fn (UsuarioAdminService $s, int $i) => $s->alternarAcesso($i));
    }

    public function doisFatores(string $id): Response
    {
        return $this->acao($id, fn (UsuarioAdminService $s, int $i) => $s->desligarDoisFatores($i));
    }

    public function excluir(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $u = $this->conta($id);
        if ($u === null) {
            return $this->voltar('/painel/usuarios', 'erro', 'Conta não encontrada. Ela pode já ter sido excluída.');
        }
        $res = (new UsuarioAdminService($this->db, $this->usuario))->excluir((int) $u['id']);
        return $this->responder($res, $res->ok ? '/painel/usuarios' : '/painel/usuarios/' . (int) $u['id']);
    }

    /** @param callable(UsuarioAdminService,int):\Cardapim\Services\Gerencia\Resultado $fazer */
    private function acao(string $id, callable $fazer, string $ancora = ''): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $u = $this->conta($id);
        if ($u === null) {
            return $this->voltar('/painel/usuarios', 'erro', 'Conta não encontrada.');
        }
        $res = $fazer(new UsuarioAdminService($this->db, $this->usuario), (int) $u['id']);
        return $this->responder($res, '/painel/usuarios/' . (int) $u['id'] . ($res->ok ? '' : $ancora));
    }

    private function salvarConta(?string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $u = null;
        if ($id !== null) {
            $u = $this->conta($id);
            if ($u === null) {
                return $this->voltar('/painel/usuarios', 'erro', 'Conta não encontrada.');
            }
        }
        $res = (new UsuarioAdminService($this->db, $this->usuario))->salvar($u !== null ? (int) $u['id'] : null, $_POST);
        if (!$res->ok) {
            $valores = [];
            foreach (['cargo', 'id_restaurante', 'nome', 'sobrenome', 'usuario', 'email', 'whatsapp', 'cpf'] as $c) {
                $valores[$c] = is_string($_POST[$c] ?? null) ? $_POST[$c] : '';
            }
            return $this->form($u, $valores, $res->erros, 422);
        }
        return $this->responder($res, '/painel/usuarios/' . (int) $res->id);
    }

    /** @param array<string,mixed>|null $u @param array<string,string> $valores @param array<string,string> $erros */
    private function form(?array $u, array $valores, array $erros, int $codigo = 200): Response
    {
        $repo = new UsuarioAdminRepository($this->db);
        return $this->tela('plataforma/usuarios/form', 'usuarios', $u === null ? 'Nova conta' : (string) $u['nome'], [
            'conta' => $u,
            'valores' => $valores,
            'erros' => $erros,
            'restaurantes' => $repo->restaurantes(),
            'souEu' => $u !== null && (int) $u['id'] === $this->usuario->id,
            'pageStyles' => ['css/plataforma-gestao.css'],
            'pageScripts' => ['js/forms.js', 'js/equipe.js', 'js/plataforma-gestao.js'],
        ], $codigo);
    }

    /** @return array<string,mixed>|null */
    private function conta(string $id): ?array
    {
        return ctype_digit($id) && strlen($id) <= 18 ? (new UsuarioAdminRepository($this->db))->buscar((int) $id) : null;
    }
}
