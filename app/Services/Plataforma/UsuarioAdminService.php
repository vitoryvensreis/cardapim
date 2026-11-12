<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Repositories\Plataforma\UsuarioAdminRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Auth\DoisFatores;
use Cardapim\Services\Auth\LoginLembrado;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\EquipeService;
use Cardapim\Services\Gerencia\InsumoService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Validation\Documento;
use PDO;
use PDOException;
use Throwable;

/**
 * Contas pela administracao da plataforma: criar (qualquer cargo, em qualquer restaurante), editar, trocar a senha,
 * bloquear/liberar, desligar a verificacao em duas etapas e excluir. Protecoes: ninguem bloqueia/exclui/rebaixa a si mesmo
 * e a plataforma nunca fica sem administrador ativo. Tudo com log.
 */
final class UsuarioAdminService
{
    private const ROTULOS = [
        'cargo' => 'Função', 'restaurante' => 'Restaurante', 'nome' => 'Nome', 'sobrenome' => 'Sobrenome', 'usuario' => 'Usuário',
        'email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'cpf' => 'CPF',
    ];

    private readonly UsuarioAdminRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor)
    {
        $this->repo = new UsuarioAdminRepository($db);
    }

    /** @param array<string,mixed> $e */
    public function salvar(?int $id, array $e): Resultado
    {
        $antes = null;
        if ($id !== null) {
            $antes = $this->repo->buscar($id);
            if ($antes === null) {
                return Resultado::falha('Conta não encontrada.');
            }
        }
        [$d, $erros] = $this->validar($e, $id);
        $senha = is_string($e['senha'] ?? null) ? $e['senha'] : '';
        if ($antes === null && ($erroSenha = EquipeService::validarSenha($senha)) !== null) {
            $erros['senha'] = $erroSenha;
        }
        if ($antes !== null && !isset($erros['cargo'])) {
            if ((int) $antes['id'] === $this->autor->id && $d['cargo'] !== $antes['cargo']) {
                $erros['cargo'] = 'Você não pode mudar a própria função.';
            } elseif ($antes['cargo'] === Autorizacao::CARGO_MASTER && $d['cargo'] !== Autorizacao::CARGO_MASTER && (int) $antes['ativo'] === 1 && $this->repo->admsAtivos() <= 1) {
                $erros['cargo'] = 'Esta é a única conta de administrador ativa. Crie outro administrador antes.';
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        try {
            if ($antes === null) {
                $novo = $this->repo->criar($d, password_hash($senha, PASSWORD_DEFAULT));
                Auditoria::registrar($this->autor, 'Usuário criado', sprintf('%s %s (%s%s), usuário %s.', $d['nome'], $d['sobrenome'],
                    Autorizacao::rotuloCargo($d['cargo']), $this->nomeRestaurante($d['id_restaurante']), $d['usuario']));
                return Resultado::sucesso(sprintf('Conta de %s criada. Ela entra com o usuário "%s" e a senha definida.', $d['nome'], $d['usuario']), $novo);
            }
            $this->repo->atualizar((int) $id, $d);
            if ($antes['cargo'] !== $d['cargo'] || (int) ($antes['id_restaurante'] ?? 0) !== (int) ($d['id_restaurante'] ?? 0)) {
                // Mudou de funcao ou de restaurante: os aparelhos lembrados entram de novo pelo login.
                (new LoginLembrado($this->db))->revogarTodos((int) $id);
            }
            Auditoria::registrar($this->autor, 'Usuário editado', sprintf('%s %s - %s', $antes['nome'], $antes['sobrenome'],
                Auditoria::diferencas($this->paraLog($antes), $this->paraLog($d), self::ROTULOS)));
            return Resultado::sucesso('Dados de ' . $d['nome'] . ' salvos.', $id);
        } catch (PDOException $erro) {
            if ($erro->getCode() === '23000') {
                return Resultado::falha('Confira os campos destacados.', ['usuario' => 'Usuário, e-mail ou CPF já cadastrado em outra conta.']);
            }
            throw $erro;
        }
    }

    public function redefinirSenha(int $id, string $senha): Resultado
    {
        $u = $this->repo->buscar($id);
        if ($u === null) {
            return Resultado::falha('Conta não encontrada.');
        }
        if (($erro = EquipeService::validarSenha($senha)) !== null) {
            return Resultado::falha($erro, ['senha' => $erro]);
        }
        $this->repo->definirSenha($id, password_hash($senha, PASSWORD_DEFAULT));
        (new LoginLembrado($this->db))->revogarTodos($id);
        Auditoria::registrar($this->autor, 'Usuário: senha redefinida', sprintf('%s %s (usuário %s). Aparelhos lembrados desconectados.', $u['nome'], $u['sobrenome'], $u['usuario']));
        return Resultado::sucesso('Senha de ' . $u['nome'] . ' trocada. Passe a nova senha para ela.', $id);
    }

    public function alternarAcesso(int $id): Resultado
    {
        $u = $this->repo->buscar($id);
        if ($u === null) {
            return Resultado::falha('Conta não encontrada.');
        }
        $liberar = (int) $u['ativo'] !== 1;
        if (!$liberar && $id === $this->autor->id) {
            return Resultado::falha('Você não pode bloquear a própria conta.');
        }
        if (!$liberar && $u['cargo'] === Autorizacao::CARGO_MASTER && $this->repo->admsAtivos() <= 1) {
            return Resultado::falha('Esta é a única conta de administrador ativa. Crie outro administrador antes de bloquear.');
        }
        $this->repo->definirAtivo($id, $liberar);
        if (!$liberar) {
            (new LoginLembrado($this->db))->revogarTodos($id);
        }
        Auditoria::registrar($this->autor, $liberar ? 'Usuário: acesso liberado' : 'Usuário: acesso bloqueado', sprintf('%s %s (usuário %s).', $u['nome'], $u['sobrenome'], $u['usuario']));
        return Resultado::sucesso($liberar ? $u['nome'] . ' pode entrar de novo.' : $u['nome'] . ' não consegue mais entrar e foi desconectada.', $id);
    }

    /** Suporte: a pessoa perdeu o celular e os codigos de recuperacao. */
    public function desligarDoisFatores(int $id): Resultado
    {
        $u = $this->repo->buscar($id);
        if ($u === null) {
            return Resultado::falha('Conta não encontrada.');
        }
        if ($u['dois_fatores'] === 'desativado') {
            return Resultado::falha('A verificação em duas etapas já está desligada nesta conta.');
        }
        (new DoisFatores($this->db))->desativar($id);
        (new LoginLembrado($this->db))->revogarTodos($id);
        Auditoria::registrar($this->autor, 'Usuário: verificação em duas etapas desligada', sprintf('%s %s (usuário %s), pelo suporte da plataforma.', $u['nome'], $u['sobrenome'], $u['usuario']));
        return Resultado::sucesso('Verificação em duas etapas desligada. ' . $u['nome'] . ' entra só com a senha e pode ligar de novo em Minha conta.', $id);
    }

    public function excluir(int $id): Resultado
    {
        $u = $this->repo->buscar($id);
        if ($u === null) {
            return Resultado::falha('Conta não encontrada. Ela pode já ter sido excluída.');
        }
        if ($id === $this->autor->id) {
            return Resultado::falha('Você não pode excluir a própria conta.');
        }
        if ($u['cargo'] === Autorizacao::CARGO_MASTER && (int) $u['ativo'] === 1 && $this->repo->admsAtivos() <= 1) {
            return Resultado::falha('Esta é a única conta de administrador ativa. Crie outro administrador antes.');
        }
        $descricao = sprintf('%s %s (%s%s, usuário %s)', $u['nome'], $u['sobrenome'], Autorizacao::rotuloCargo((string) $u['cargo']),
            $u['restaurante_nome'] !== null ? ', ' . $u['restaurante_nome'] : '', $u['usuario']);
        $this->db->beginTransaction();
        try {
            (new LoginLembrado($this->db))->revogarTodos($id);
            $modo = 'dados pessoais apagados; histórico preservado';
            if (!$this->repo->temHistorico($id)) {
                try {
                    $this->repo->apagar($id);
                    $modo = 'conta apagada (nunca tinha entrado)';
                } catch (PDOException $erro) {
                    if ($erro->getCode() !== '23000') {
                        throw $erro;
                    }
                    // Aparece em pedidos/caixas: nao apaga, anonimiza.
                    $this->repo->anonimizar($id, date('Y-m-d H:i:s'));
                }
            } else {
                $this->repo->anonimizar($id, date('Y-m-d H:i:s'));
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        Auditoria::registrar($this->autor, 'Usuário excluído', $descricao . ' - ' . $modo . '.');
        return Resultado::sucesso($u['nome'] . ' foi removida e não tem mais acesso.');
    }

    /**
     * @param array<string,mixed> $e
     * @return array{0:array{id_restaurante:?int,cargo:string,usuario:string,email:string,nome:string,sobrenome:string,cpf:?string,whatsapp:?string}, 1:array<string,string>}
     */
    private function validar(array $e, ?int $id): array
    {
        $erros = [];
        $cargo = (string) ($e['cargo'] ?? '');
        if (!in_array($cargo, UsuarioAdminRepository::CARGOS, true)) {
            $erros['cargo'] = 'Escolha a função.';
        }
        $idRest = null;
        if ($cargo !== Autorizacao::CARGO_MASTER) {
            $texto = (string) ($e['id_restaurante'] ?? '');
            $idRest = ctype_digit($texto) ? (int) $texto : null;
            if ($idRest === null || !$this->repo->restauranteExiste($idRest)) {
                $erros['id_restaurante'] = 'Escolha o restaurante desta pessoa.';
                $idRest = null;
            }
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
        } elseif (str_starts_with($usuario, 'removido_') || $this->repo->emUso('usuario', $usuario, $id)) {
            $erros['usuario'] = 'Este nome de usuário já está em uso. Tente outro.';
        }
        // Administrador e gerente recebem codigos e avisos por e-mail: obrigatorio. Equipe pode ficar sem.
        $precisaEmail = in_array($cargo, [Autorizacao::CARGO_MASTER, 'gerente'], true);
        $email = mb_strtolower(trim((string) ($e['email'] ?? '')));
        if ($email === '' || EquipeService::semEmail($email)) {
            if ($precisaEmail) {
                $erros['email'] = 'Digite o e-mail (obrigatório para administrador e gerente).';
            }
            $email = $usuario . EquipeService::DOMINIO_SEM_EMAIL;
        } elseif (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_ends_with($email, '.invalid')) {
            $erros['email'] = 'Digite um e-mail válido, como nome@exemplo.com.';
        } elseif ($this->repo->emUso('email', $email, $id)) {
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
        } elseif ($cpf !== null && $this->repo->emUso('cpf', $cpf, $id)) {
            $erros['cpf'] = 'Este CPF já está em outra conta.';
        }
        return [['id_restaurante' => $idRest, 'cargo' => $cargo, 'usuario' => $usuario, 'email' => $email, 'nome' => $nome,
            'sobrenome' => $sobrenome, 'cpf' => $cpf, 'whatsapp' => $whatsapp], $erros];
    }

    private function nomeRestaurante(?int $id): string
    {
        if ($id === null) {
            return '';
        }
        $q = $this->db->prepare('SELECT nome FROM restaurantes WHERE id = ?');
        $q->execute([$id]);
        return ', ' . (string) $q->fetchColumn();
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function paraLog(array $d): array
    {
        return [
            'cargo' => Autorizacao::rotuloCargo((string) $d['cargo']),
            'restaurante' => ltrim($this->nomeRestaurante(isset($d['id_restaurante']) ? (int) $d['id_restaurante'] ?: null : null), ', ') ?: null,
            'nome' => $d['nome'],
            'sobrenome' => $d['sobrenome'],
            'usuario' => $d['usuario'],
            'email' => EquipeService::semEmail((string) $d['email']) ? null : $d['email'],
            'whatsapp' => $d['whatsapp'] ?? null,
            'cpf' => empty($d['cpf']) ? null : Documento::mascararCpf((string) $d['cpf']),
        ];
    }
}
