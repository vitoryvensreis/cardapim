<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Gerencia\EquipeRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\LoginLembrado;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Validation\Documento;
use PDO;
use PDOException;
use Throwable;

/**
 * Equipe do restaurante: o gerente cria, edita, bloqueia, troca a senha e exclui contas de
 * caixa, garcom e entregador (motoqueiro) do proprio restaurante. Tudo registrado em log_user.
 */
final class EquipeService
{
    /** E-mail tecnico para quem nao tem e-mail (a coluna e obrigatoria e unica). */
    public const DOMINIO_SEM_EMAIL = '@equipe.cardapim.invalid';
    public const SENHA_MIN = 8;

    private const ROTULOS = [
        'cargo' => 'Função', 'nome' => 'Nome', 'sobrenome' => 'Sobrenome', 'usuario' => 'Usuário',
        'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'cpf' => 'CPF',
    ];

    private readonly EquipeRepository $equipe;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->equipe = new EquipeRepository($db);
    }

    public static function semEmail(string $email): bool
    {
        return str_ends_with(strtolower($email), self::DOMINIO_SEM_EMAIL);
    }

    /** @param array<string,mixed> $entrada */
    public function salvar(?int $id, array $entrada): Resultado
    {
        $antes = null;
        if ($id !== null) {
            $antes = $this->equipe->buscar($this->idRestaurante, $id);
            if ($antes === null) {
                return Resultado::falha('Pessoa não encontrada na sua equipe.');
            }
        }
        [$dados, $erros] = $this->validar($entrada, $id);
        $senha = is_string($entrada['senha'] ?? null) ? $entrada['senha'] : '';
        if ($antes === null) {
            $erroSenha = self::validarSenha($senha);
            if ($erroSenha !== null) {
                $erros['senha'] = $erroSenha;
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }

        try {
            if ($antes === null) {
                $novoId = $this->equipe->criar($this->idRestaurante, $dados, password_hash($senha, PASSWORD_DEFAULT));
                Auditoria::registrar($this->autor, 'Equipe: conta criada', sprintf(
                    '%s %s (%s), usuário %s.',
                    $dados['nome'],
                    $dados['sobrenome'],
                    Autorizacao::rotuloCargo($dados['cargo']),
                    $dados['usuario'],
                ));
                return Resultado::sucesso(sprintf('Conta de %s criada. Passe o usuário "%s" e a senha para ela entrar.', $dados['nome'], $dados['usuario']), $novoId);
            }
            $this->equipe->atualizar($this->idRestaurante, (int) $id, $dados);
            Auditoria::registrar($this->autor, 'Equipe: conta editada', sprintf(
                '%s %s - %s',
                $antes['nome'],
                $antes['sobrenome'],
                Auditoria::diferencas(self::paraLog($antes), self::paraLog($dados), self::ROTULOS),
            ));
            return Resultado::sucesso('Dados de ' . $dados['nome'] . ' atualizados.', $id);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return Resultado::falha('Confira os campos destacados.', ['usuario' => 'Usuário, e-mail ou CPF já cadastrado em outra conta.']);
            }
            throw $exception;
        }
    }

    public function redefinirSenha(int $id, string $senha): Resultado
    {
        $pessoa = $this->equipe->buscar($this->idRestaurante, $id);
        if ($pessoa === null) {
            return Resultado::falha('Pessoa não encontrada na sua equipe.');
        }
        $erro = self::validarSenha($senha);
        if ($erro !== null) {
            return Resultado::falha($erro, ['senha' => $erro]);
        }
        $this->equipe->definirSenha($this->idRestaurante, $id, password_hash($senha, PASSWORD_DEFAULT));
        (new LoginLembrado($this->db))->revogarTodos($id);
        Auditoria::registrar($this->autor, 'Equipe: senha redefinida', sprintf('%s %s (usuário %s). Aparelhos lembrados foram desconectados.', $pessoa['nome'], $pessoa['sobrenome'], $pessoa['usuario']));
        return Resultado::sucesso('Senha de ' . $pessoa['nome'] . ' trocada. Passe a nova senha para ela.', $id);
    }

    public function alternarAcesso(int $id): Resultado
    {
        $pessoa = $this->equipe->buscar($this->idRestaurante, $id);
        if ($pessoa === null) {
            return Resultado::falha('Pessoa não encontrada na sua equipe.');
        }
        $liberar = (int) $pessoa['ativo'] !== 1;
        $this->equipe->definirAtivo($this->idRestaurante, $id, $liberar);
        if (!$liberar) {
            (new LoginLembrado($this->db))->revogarTodos($id);
        }
        Auditoria::registrar($this->autor, $liberar ? 'Equipe: acesso liberado' : 'Equipe: acesso bloqueado', sprintf('%s %s (usuário %s).', $pessoa['nome'], $pessoa['sobrenome'], $pessoa['usuario']));
        return Resultado::sucesso($liberar
            ? $pessoa['nome'] . ' pode entrar no sistema de novo.'
            : $pessoa['nome'] . ' não consegue mais entrar. Se estava usando o sistema, foi desconectada.', $id);
    }

    public function excluir(int $id): Resultado
    {
        $pessoa = $this->equipe->buscar($this->idRestaurante, $id);
        if ($pessoa === null) {
            return Resultado::falha('Pessoa não encontrada na sua equipe.');
        }
        $descricao = sprintf('%s %s (%s, usuário %s)', $pessoa['nome'], $pessoa['sobrenome'], Autorizacao::rotuloCargo((string) $pessoa['cargo']), $pessoa['usuario']);
        $this->db->beginTransaction();
        try {
            (new LoginLembrado($this->db))->revogarTodos($id);
            if ($this->equipe->temHistorico($id)) {
                // Tem acessos registrados: preserva o id para a auditoria e apaga os dados pessoais.
                $this->equipe->anonimizar($this->idRestaurante, $id, date('Y-m-d H:i:s'));
                $modo = 'dados pessoais apagados; histórico de acessos preservado';
            } else {
                $this->equipe->apagar($this->idRestaurante, $id);
                $modo = 'conta apagada (nunca tinha entrado)';
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        Auditoria::registrar($this->autor, 'Equipe: conta excluída', $descricao . ' - ' . $modo . '.');
        return Resultado::sucesso($pessoa['nome'] . ' foi removida da equipe e não tem mais acesso.');
    }

    public static function validarSenha(string $senha): ?string
    {
        if (mb_strlen($senha) < self::SENHA_MIN) {
            return 'A senha precisa ter pelo menos ' . self::SENHA_MIN . ' caracteres.';
        }
        if (strlen($senha) > 128) {
            return 'Use uma senha de no máximo 128 caracteres.';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $e
     * @return array{0:array{cargo:string,usuario:string,email:string,nome:string,sobrenome:string,cpf:?string,whatsapp:?string}, 1:array<string,string>}
     */
    private function validar(array $e, ?int $id): array
    {
        $erros = [];
        $cargo = (string) ($e['cargo'] ?? '');
        if (!in_array($cargo, EquipeRepository::CARGOS, true)) {
            $erros['cargo'] = 'Escolha a função da pessoa.';
        }
        $nome = InsumoService::texto($e['nome'] ?? '');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
            $erros['nome'] = 'Digite o nome (2 a 120 letras).';
        }
        $sobrenome = InsumoService::texto($e['sobrenome'] ?? '');
        if (mb_strlen($sobrenome) < 2 || mb_strlen($sobrenome) > 150) {
            $erros['sobrenome'] = 'Digite o sobrenome (2 a 150 letras).';
        }

        $usuario = strtolower(trim((string) ($e['usuario'] ?? '')));
        if (preg_match('/\A[a-z0-9._-]{3,60}\z/', $usuario) !== 1) {
            $erros['usuario'] = 'Use de 3 a 60 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.';
        } elseif (str_starts_with($usuario, 'removido_') || $this->equipe->emUso('usuario', $usuario, $id)) {
            $erros['usuario'] = 'Este nome de usuário já está em uso. Tente outro, como ' . $usuario . '.' . strtolower(substr(preg_replace('/[^a-z]/i', '', $nome) ?: 'x', 0, 1)) . random_int(1, 99) . '.';
        }

        $email = trim((string) ($e['email'] ?? ''));
        if ($email === '' || self::semEmail($email)) {
            $email = $usuario . self::DOMINIO_SEM_EMAIL;
        } elseif (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_ends_with(strtolower($email), '.invalid')) {
            $erros['email'] = 'Digite um e-mail válido, como nome@exemplo.com, ou deixe em branco.';
        } elseif ($this->equipe->emUso('email', $email, $id)) {
            $erros['email'] = 'Este e-mail já está em outra conta.';
        }

        $whatsTexto = trim((string) ($e['whatsapp'] ?? ''));
        $whatsapp = $whatsTexto === '' ? null : Documento::normalizarTelefone($whatsTexto);
        if ($whatsTexto !== '' && $whatsapp === null) {
            $erros['whatsapp'] = 'Digite o DDD e o número completo, ou deixe em branco.';
        }

        $cpfTexto = trim((string) ($e['cpf'] ?? ''));
        $cpf = $cpfTexto === '' ? null : Documento::normalizarCpf($cpfTexto);
        if ($cpfTexto !== '' && $cpf === null) {
            $erros['cpf'] = 'CPF inválido. Confira os números ou deixe em branco.';
        } elseif ($cpf !== null && $this->equipe->emUso('cpf', $cpf, $id)) {
            $erros['cpf'] = 'Este CPF já está em outra conta.';
        }

        return [compact('cargo', 'usuario', 'email', 'nome', 'sobrenome', 'cpf', 'whatsapp'), $erros];
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private static function paraLog(array $d): array
    {
        return [
            'cargo' => Autorizacao::rotuloCargo((string) $d['cargo']),
            'nome' => $d['nome'],
            'sobrenome' => $d['sobrenome'],
            'usuario' => $d['usuario'],
            'email' => self::semEmail((string) $d['email']) ? null : $d['email'],
            'whatsapp' => $d['whatsapp'] ?? null,
            // CPF nunca vai inteiro para o log
            'cpf' => empty($d['cpf']) ? null : Documento::mascararCpf((string) $d['cpf']),
        ];
    }
}
