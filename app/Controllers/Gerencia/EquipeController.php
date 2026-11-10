<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Repositories\Gerencia\EquipeRepository;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Shared\Validation\Documento;

/** Aba Equipe: contas de caixa, garcom e entregador do restaurante. */
final class EquipeController extends BaseGerencia
{
    public function lista(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $pessoas = (new EquipeRepository($this->db))->listar($this->restaurante['id']);
        $filtro = in_array($_GET['funcao'] ?? '', EquipeRepository::CARGOS, true) ? $_GET['funcao'] : 'todos';
        $contagem = array_fill_keys(EquipeRepository::CARGOS, 0);
        foreach ($pessoas as $p) {
            $contagem[$p['cargo']]++;
        }
        $total = count($pessoas);
        if ($filtro !== 'todos') {
            $pessoas = array_values(array_filter($pessoas, static fn (array $p): bool => $p['cargo'] === $filtro));
        }
        return $this->tela('gerencia/equipe/lista', 'equipe', 'Equipe', compact('pessoas', 'filtro', 'contagem', 'total'));
    }

    public function nova(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $cargo = in_array($_GET['funcao'] ?? '', EquipeRepository::CARGOS, true) ? $_GET['funcao'] : '';
        return $this->form(null, ['cargo' => $cargo], []);
    }

    public function editar(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $pessoa = $this->buscar($idTexto);
        if ($pessoa === null) {
            return $this->voltar($this->url('equipe'), 'erro', 'Pessoa não encontrada na sua equipe.');
        }
        return $this->form($pessoa, [
            'cargo' => (string) $pessoa['cargo'],
            'nome' => (string) $pessoa['nome'],
            'sobrenome' => (string) $pessoa['sobrenome'],
            'usuario' => (string) $pessoa['usuario'],
            'email' => EquipeService::semEmail((string) $pessoa['email']) ? '' : (string) $pessoa['email'],
            'whatsapp' => (string) ($pessoa['whatsapp'] ?? ''),
            'cpf' => $pessoa['cpf'] ? Documento::formatarCpf((string) $pessoa['cpf']) : '',
        ], []);
    }

    public function criar(string $slug): Response
    {
        return $this->salvar($slug, null);
    }

    public function atualizar(string $slug, string $idTexto): Response
    {
        return $this->salvar($slug, $idTexto);
    }

    public function senha(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->voltar($this->url('equipe'), 'erro', 'Pessoa não encontrada na sua equipe.');
        }
        $resultado = $this->servico()->redefinirSenha($id, (string) ($_POST['senha'] ?? ''));
        return $this->responder($resultado, $this->url('equipe/' . $id), $this->url('equipe/' . $id) . '#senha');
    }

    public function acesso(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->voltar($this->url('equipe'), 'erro', 'Pessoa não encontrada na sua equipe.');
        }
        return $this->responder($this->servico()->alternarAcesso($id), $this->origem($this->url('equipe')));
    }

    public function excluir(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = self::id($idTexto);
        if ($id === null) {
            return $this->voltar($this->url('equipe'), 'erro', 'Pessoa não encontrada na sua equipe.');
        }
        return $this->responder($this->servico()->excluir($id), $this->url('equipe'));
    }

    private function salvar(string $slug, ?string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $id = $idTexto === null ? null : self::id($idTexto);
        if ($idTexto !== null && $id === null) {
            return $this->voltar($this->url('equipe'), 'erro', 'Pessoa não encontrada na sua equipe.');
        }
        $resultado = $this->servico()->salvar($id, $_POST);
        if ($resultado->ok) {
            return $this->voltar($this->url('equipe'), 'sucesso', $resultado->mensagem);
        }
        $pessoa = $id === null ? null : (new EquipeRepository($this->db))->buscar($this->restaurante['id'], $id);
        if ($id !== null && $pessoa === null) {
            return $this->voltar($this->url('equipe'), 'erro', $resultado->mensagem);
        }
        $valores = [];
        foreach (['cargo', 'nome', 'sobrenome', 'usuario', 'email', 'whatsapp', 'cpf'] as $campo) {
            $valores[$campo] = is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '';
        }
        return $this->form($pessoa, $valores, $resultado->erros, 422);
    }

    /** @param array<string,mixed>|null $pessoa @param array<string,string> $valores @param array<string,string> $erros */
    private function form(?array $pessoa, array $valores, array $erros, int $status = 200): Response
    {
        return $this->tela('gerencia/equipe/form', 'equipe', $pessoa === null ? 'Nova pessoa na equipe' : 'Editar equipe', [
            'pessoa' => $pessoa,
            'valores' => $valores,
            'erros' => $erros,
            'pageScripts' => ['js/forms.js', 'js/equipe.js'],
        ], $status);
    }

    /** @return array<string,mixed>|null */
    private function buscar(string $idTexto): ?array
    {
        $id = self::id($idTexto);
        return $id === null ? null : (new EquipeRepository($this->db))->buscar($this->restaurante['id'], $id);
    }

    private function servico(): EquipeService
    {
        return new EquipeService($this->db, $this->usuario, $this->restaurante['id']);
    }
}
