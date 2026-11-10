<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Core\RateLimiter;
use Cardapim\Repositories\ContaRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Validation\Documento;
use PDO;

/** Minha conta: dados pessoais, troca de senha e confirmacao de senha para acoes sensiveis. */
final class ContaService
{
    private readonly ContaRepository $contas;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor)
    {
        $this->contas = new ContaRepository($db);
    }

    /** @param array<string,mixed> $e */
    public function salvarDados(array $e): Resultado
    {
        $conta = $this->contas->buscar($this->autor->id);
        if ($conta === null) {
            return Resultado::falha('Conta não encontrada.');
        }
        $erros = [];
        $nome = InsumoService::texto($e['nome'] ?? '');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
            $erros['nome'] = 'Digite seu nome (2 a 120 letras).';
        }
        $sobrenome = InsumoService::texto($e['sobrenome'] ?? '');
        if (mb_strlen($sobrenome) < 2 || mb_strlen($sobrenome) > 150) {
            $erros['sobrenome'] = 'Digite seu sobrenome (2 a 150 letras).';
        }
        $whatsTexto = trim((string) ($e['whatsapp'] ?? ''));
        $whatsapp = $whatsTexto === '' ? null : Documento::normalizarTelefone($whatsTexto);
        if ($whatsTexto !== '' && $whatsapp === null) {
            $erros['whatsapp'] = 'Digite o DDD e o número completo, ou deixe em branco.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $this->contas->atualizarDados($this->autor->id, $nome, $sobrenome, $whatsapp);
        $diferenca = Auditoria::diferencas($conta, compact('nome', 'sobrenome', 'whatsapp'), ['nome' => 'Nome', 'sobrenome' => 'Sobrenome', 'whatsapp' => 'Telefone']);
        if ($diferenca !== 'Nenhuma alteração.') {
            Auditoria::registrar($this->autor, 'Minha conta: dados alterados', $diferenca);
        }
        return Resultado::sucesso('Seus dados foram salvos.');
    }

    /**
     * Troca a propria senha. Os outros aparelhos com "manter conectado" sao desconectados.
     * @return Resultado
     */
    public function trocarSenha(string $atual, string $nova, string $confirmacao, ?string $seletorAtual): Resultado
    {
        $conta = $this->contas->buscar($this->autor->id);
        if ($conta === null) {
            return Resultado::falha('Conta não encontrada.');
        }
        if (!$this->senhaConfere($conta, $atual)) {
            return Resultado::falha('Confira os campos destacados.', ['senha_atual' => 'Senha atual incorreta.']);
        }
        $erro = EquipeService::validarSenha($nova);
        if ($erro !== null) {
            return Resultado::falha('Confira os campos destacados.', ['senha_nova' => $erro]);
        }
        if (hash_equals($atual, $nova)) {
            return Resultado::falha('Confira os campos destacados.', ['senha_nova' => 'A nova senha precisa ser diferente da atual.']);
        }
        if (!hash_equals($nova, $confirmacao)) {
            return Resultado::falha('Confira os campos destacados.', ['senha_confirmacao' => 'As duas senhas não são iguais. Digite de novo.']);
        }
        $this->contas->definirSenha($this->autor->id, password_hash($nova, PASSWORD_DEFAULT));
        $removidos = $this->contas->removerOutrosAparelhos($this->autor->id, $seletorAtual);
        Auditoria::registrar($this->autor, 'Minha conta: senha alterada', $removidos > 0 ? $removidos . ' outro(s) aparelho(s) foram desconectados.' : 'Nenhum outro aparelho estava conectado.');
        return Resultado::sucesso($removidos > 0
            ? 'Senha trocada. Os outros aparelhos onde você estava conectado vão pedir a nova senha.'
            : 'Senha trocada com sucesso.');
    }

    /**
     * Confere a senha antes de acoes sensiveis (desativar verificacao, gerar novos codigos).
     * Limite de 5 erros em 15 minutos.
     * @param array<string,mixed> $conta
     */
    public function senhaConfere(array $conta, string $senha): bool
    {
        $limite = RateLimiter::padrao(5, 900);
        $chave = 'senha-confirmar|' . (int) $conta['id'];
        if ($limite->excedido($chave)) {
            return false;
        }
        if ($senha !== '' && password_verify($senha, (string) $conta['senha_hash'])) {
            $limite->limpar($chave);
            return true;
        }
        $limite->permitir($chave);
        return false;
    }
}
