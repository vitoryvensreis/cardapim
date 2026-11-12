<?php

declare(strict_types=1);

namespace Cardapim\Services\Cadastro;

use Cardapim\Repositories\Caixa\MesaRepository;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\LogUserRepository;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Repositories\RestauranteRepository;
use Cardapim\Repositories\UsuarioRepository;
use Cardapim\Services\Gerencia\InsumoService;
use Cardapim\Services\Plataforma\AssinaturaCobranca;
use Cardapim\Services\Plataforma\CobrancaAssinaturas;
use Cardapim\Shared\Slug;
use Cardapim\Shared\Validation\Documento;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

/**
 * Cadastro publico do restaurante (/cadastro): cria o restaurante, a conta do gerente e a assinatura do plano,
 * tudo na mesma transacao; depois gera a cobranca no Asaas da plataforma.
 * - Plano gratis (ex.: parceiro vitalicio, por link de convite): restaurante ja ativo, sem cobranca.
 * - Plano pago com dias gratis: ativo ate o 1o vencimento.
 * - Plano pago sem teste: "pendente" (monta o restaurante, mas o cardapio so aparece depois do 1o pagamento).
 */
final class CadastroRestaurante
{
    public const SENHA_MIN = 8;

    public function __construct(
        private readonly PDO $db,
        private readonly ?AssinaturaCobranca $cobranca = null,
        private readonly ?DateTimeImmutable $hoje = null,
    ) {
    }

    /** Plano do cadastro: convite (privado) tem prioridade sobre o plano publico da URL. @return array<string,mixed>|null */
    public function plano(?string $convite, ?int $planoId): ?array
    {
        $planos = new PlanoRepository($this->db);
        if ($convite !== null && $convite !== '') {
            return $planos->buscarPorConvite($convite);
        }
        return $planoId !== null ? $planos->buscarPublicoPorId($planoId) : null;
    }

    /** Planos pagos so podem ser escolhidos com o Asaas da plataforma conectado. */
    public function pagamentoDisponivel(): bool
    {
        return $this->cobrancaServico()->conta() !== null;
    }

    /**
     * @param array<string,mixed> $d dados do formulario
     * @return array{ok:bool, erros?:array<string,string>, mensagem?:string, id_user?:int, slug?:string, cobranca?:array{ok:bool,mensagem:string}, gratis?:bool}
     */
    public function cadastrar(array $d): array
    {
        $t = static fn (string $c): string => trim(is_string($d[$c] ?? null) ? $d[$c] : '');
        $erros = [];
        $convite = $t('convite');
        $planoId = filter_var($d['plano_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $plano = $this->plano($convite !== '' ? $convite : null, $planoId === false ? null : $planoId);
        if ($plano === null) {
            $erros['plano_id'] = $convite !== '' ? 'Este link de convite não vale mais. Peça um novo para quem enviou.' : 'Escolha um plano.';
        }
        $gratis = $plano !== null && (float) $plano['valor'] <= 0;
        if ($plano !== null && !$gratis && !$this->pagamentoDisponivel()) {
            $erros['plano_id'] = 'O pagamento online está indisponível agora. Tente de novo em alguns minutos.';
        }

        $nome = $t('nome');
        $sobrenome = $t('sobrenome');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
            $erros['nome'] = 'Digite seu nome.';
        }
        if (mb_strlen($sobrenome) < 2 || mb_strlen($sobrenome) > 150) {
            $erros['sobrenome'] = 'Digite seu sobrenome.';
        }
        $cpf = Documento::normalizarCpf($t('cpf'));
        if ($cpf === null) {
            $erros['cpf'] = 'CPF inválido. Confira os números.';
        } elseif ($this->existe('SELECT 1 FROM `user` WHERE cpf = ? LIMIT 1', $cpf)) {
            $erros['cpf'] = 'Este CPF já tem uma conta. Se é você, entre pelo login.';
        }
        $whatsapp = Documento::normalizarTelefone($t('whatsapp'));
        if ($whatsapp === null) {
            $erros['whatsapp'] = 'Digite o WhatsApp com DDD.';
        }
        $restaurante = $t('nome_restaurante');
        if (mb_strlen($restaurante) < 2 || mb_strlen($restaurante) > 180) {
            $erros['nome_restaurante'] = 'Digite o nome do restaurante.';
        }
        $cnpj = Documento::somenteDigitos($t('cnpj'));
        if (!Documento::cnpjValido($cnpj)) {
            $erros['cnpj'] = 'CNPJ inválido. Confira os números.';
        } elseif ((new RestauranteRepository($this->db))->cnpjExiste($cnpj)) {
            $erros['cnpj'] = 'Este CNPJ já possui um restaurante cadastrado.';
        }
        $usuario = strtolower($t('usuario'));
        $usuarios = new UsuarioRepository($this->db);
        if (preg_match('/\A[a-z0-9._-]{3,60}\z/', $usuario) !== 1) {
            $erros['usuario'] = 'Use de 3 a 60 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.';
        } elseif ($usuarios->usuarioExiste($usuario)) {
            $erros['usuario'] = 'Este nome de usuário já está em uso. Tente outro.';
        }
        $email = mb_strtolower($t('email'));
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erros['email'] = 'Digite um e-mail válido, como nome@exemplo.com.';
        } elseif ($usuarios->emailExiste($email)) {
            $erros['email'] = 'Este e-mail já está cadastrado. Se a conta é sua, entre pelo login.';
        }
        $senha = is_string($d['senha'] ?? null) ? $d['senha'] : '';
        if (strlen($senha) < self::SENHA_MIN || strlen($senha) > 128 || preg_match('/[A-Za-z]/', $senha) !== 1 || preg_match('/\d/', $senha) !== 1) {
            $erros['senha'] = 'Use 8 ou mais caracteres, misturando letras e números.';
        }
        if (empty($d['aceite_termos'])) {
            $erros['aceite_termos'] = 'Para continuar, aceite os Termos de Uso e a Política de Privacidade.';
        }
        if ($erros !== []) {
            return ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos destacados.'];
        }

        return $this->gravar([
            'restaurante' => $restaurante, 'cnpj' => $cnpj, 'telefone' => $whatsapp, 'email_restaurante' => $email,
            'usuario' => $usuario, 'email' => $email, 'senha' => $senha, 'nome' => $nome, 'sobrenome' => $sobrenome,
            'cpf' => $cpf, 'whatsapp' => $whatsapp,
        ], $plano, null);
    }

    /**
     * Cadastro feito pelo administrador da plataforma (/painel/restaurantes/novo): qualquer plano ativo (inclusive privado),
     * CNPJ e CPF opcionais (CNPJ obrigatorio para plano pago: o Asaas exige), sem aceite de termos.
     * @param array<string,mixed> $d
     * @return array{ok:bool, erros?:array<string,string>, mensagem?:string, id_restaurante?:int, id_user?:int, slug?:string, cobranca?:array{ok:bool,mensagem:string}, gratis?:bool}
     */
    public function cadastrarPeloPainel(array $d, int $idAutor): array
    {
        $t = static fn (string $c): string => trim(is_string($d[$c] ?? null) ? $d[$c] : '');
        $erros = [];
        $planoId = filter_var($d['plano_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $plano = $planoId === false ? null : (new PlanoRepository($this->db))->buscar((int) $planoId);
        if ($plano === null || (int) $plano['ativo'] !== 1) {
            $erros['plano_id'] = 'Escolha um plano ativo.';
            $plano = null;
        }
        $gratis = $plano !== null && (float) $plano['valor'] <= 0;
        if ($plano !== null && !$gratis && !$this->pagamentoDisponivel()) {
            $erros['plano_id'] = 'Plano pago precisa do Asaas da plataforma conectado (Integrações > Asaas). Escolha um plano grátis ou conecte o Asaas.';
        }

        $restaurante = $t('nome_restaurante');
        if (mb_strlen($restaurante) < 2 || mb_strlen($restaurante) > 180) {
            $erros['nome_restaurante'] = 'Digite o nome do restaurante.';
        }
        $cnpjTexto = $t('cnpj');
        $cnpj = Documento::somenteDigitos($cnpjTexto);
        if ($cnpjTexto === '') {
            $cnpj = null;
            if ($plano !== null && !$gratis) {
                $erros['cnpj'] = 'Plano pago precisa do CNPJ (o Asaas usa para a cobrança).';
            }
        } elseif (!Documento::cnpjValido($cnpj)) {
            $erros['cnpj'] = 'CNPJ inválido. Confira os números ou deixe em branco.';
        } elseif ((new RestauranteRepository($this->db))->cnpjExiste($cnpj)) {
            $erros['cnpj'] = 'Este CNPJ já possui um restaurante cadastrado.';
        }
        $telefone = Documento::normalizarTelefone($t('telefone'));
        if ($telefone === null) {
            $erros['telefone'] = 'Digite o telefone do restaurante com DDD.';
        }

        $nome = $t('nome');
        $sobrenome = $t('sobrenome');
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 120) {
            $erros['nome'] = 'Digite o nome do gerente.';
        }
        if (mb_strlen($sobrenome) < 2 || mb_strlen($sobrenome) > 150) {
            $erros['sobrenome'] = 'Digite o sobrenome do gerente.';
        }
        $cpfTexto = $t('cpf');
        $cpf = $cpfTexto === '' ? null : Documento::normalizarCpf($cpfTexto);
        if ($cpfTexto !== '' && $cpf === null) {
            $erros['cpf'] = 'CPF inválido. Confira os números ou deixe em branco.';
        } elseif ($cpf !== null && $this->existe('SELECT 1 FROM `user` WHERE cpf = ? LIMIT 1', $cpf)) {
            $erros['cpf'] = 'Este CPF já está em outra conta.';
        }
        $whatsTexto = $t('whatsapp');
        $whatsapp = $whatsTexto === '' ? null : Documento::normalizarTelefone($whatsTexto);
        if ($whatsTexto !== '' && $whatsapp === null) {
            $erros['whatsapp'] = 'Digite o WhatsApp com DDD ou deixe em branco.';
        }
        $usuario = strtolower($t('usuario'));
        $usuarios = new UsuarioRepository($this->db);
        if (preg_match('/\A[a-z0-9._-]{3,60}\z/', $usuario) !== 1) {
            $erros['usuario'] = 'Use de 3 a 60 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.';
        } elseif (str_starts_with($usuario, 'removido_') || $usuarios->usuarioExiste($usuario)) {
            $erros['usuario'] = 'Este nome de usuário já está em uso. Tente outro.';
        }
        $email = mb_strtolower($t('email'));
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_ends_with($email, '.invalid')) {
            $erros['email'] = 'Digite o e-mail do gerente, como nome@exemplo.com.';
        } elseif ($usuarios->emailExiste($email)) {
            $erros['email'] = 'Este e-mail já está em outra conta.';
        }
        $senha = is_string($d['senha'] ?? null) ? $d['senha'] : '';
        if (strlen($senha) < self::SENHA_MIN || strlen($senha) > 128) {
            $erros['senha'] = 'A senha precisa ter de 8 a 128 caracteres.';
        }
        if ($erros !== []) {
            return ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos destacados.'];
        }
        return $this->gravar([
            'restaurante' => $restaurante, 'cnpj' => $cnpj, 'telefone' => $telefone, 'email_restaurante' => $email,
            'usuario' => $usuario, 'email' => $email, 'senha' => $senha, 'nome' => $nome, 'sobrenome' => $sobrenome,
            'cpf' => $cpf, 'whatsapp' => $whatsapp,
        ], $plano, $idAutor);
    }

    /**
     * Grava restaurante + gerente + mesas + assinatura numa transacao e depois gera a cobranca (plano pago).
     * $idAutor null = cadastro do site (log em nome do gerente); senao, o administrador que cadastrou.
     * @param array{restaurante:string,cnpj:?string,telefone:string,email_restaurante:string,usuario:string,email:string,senha:string,nome:string,sobrenome:string,cpf:?string,whatsapp:?string} $v
     * @param array<string,mixed> $plano
     * @return array{ok:bool, erros?:array<string,string>, mensagem?:string, id_restaurante?:int, id_user?:int, slug?:string, cobranca?:array{ok:bool,mensagem:string}, gratis?:bool, teste?:bool}
     */
    private function gravar(array $v, array $plano, ?int $idAutor): array
    {
        $gratis = (float) $plano['valor'] <= 0;
        $restaurante = $v['restaurante'];
        $regras = (new CobrancaAssinaturas($this->db))->regras();
        $hoje = $this->hoje();
        $teste = !$gratis && $regras['teste_dias'] > 0;
        $statusRest = $gratis || $teste ? 'ativo' : 'pendente';
        $assinatura = match (true) {
            $gratis => ['status' => 'ativa', 'vence_em' => null],
            $teste => ['status' => 'ativa', 'vence_em' => $hoje->modify('+' . $regras['teste_dias'] . ' days')->format('Y-m-d')],
            default => ['status' => 'aguardando', 'vence_em' => null],
        };

        $this->db->beginTransaction();
        try {
            $slug = $this->slugLivre($restaurante);
            $this->db->prepare('INSERT INTO restaurantes (nome, slug, telefone, email, cnpj, plano_id, status_rest) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$restaurante, $slug, $v['telefone'], $v['email_restaurante'], $v['cnpj'], (int) $plano['id'], $statusRest]);
            $idRest = (int) $this->db->lastInsertId();
            if ($idRest < 1) {
                $idRest = (int) $this->valor('SELECT id FROM restaurantes WHERE slug = ?', $slug);
            }
            $this->db->prepare('INSERT INTO `user` (id_restaurante, cargo, usuario, email, senha_hash, nome, sobrenome, cpf, whatsapp, ativo)
                VALUES (?, \'gerente\', ?, ?, ?, ?, ?, ?, ?, 1)')
                ->execute([$idRest, $v['usuario'], $v['email'], password_hash($v['senha'], PASSWORD_DEFAULT), $v['nome'], $v['sobrenome'], $v['cpf'], $v['whatsapp']]);
            $idUser = (int) $this->valor('SELECT id FROM `user` WHERE usuario = ?', $v['usuario']);
            (new MesaRepository($this->db))->criarPadrao($idRest);
            $idAssinatura = (new AssinaturaRepository($this->db))->criar($idRest, [
                'plano_id' => (int) $plano['id'],
                'valor' => (string) $plano['valor'],
                'periodo' => (string) $plano['periodo'],
                'status' => $assinatura['status'],
                'inicio_em' => $hoje->format('Y-m-d'),
                'vence_em' => $assinatura['vence_em'],
            ]);
            (new LogUserRepository($this->db))->registrar('Cadastro do restaurante', sprintf(
                'Restaurante %s criado %s com o plano %s%s; gerente %s. [%s]',
                $restaurante, $idAutor === null ? 'pelo cadastro do site' : 'pelo painel da plataforma', (string) $plano['nome'],
                (int) ($plano['visibilidade'] ?? 1) === 1 ? '' : ' (privado)', $v['usuario'], $idAutor === null ? $restaurante : 'Plataforma',
            ), $idAutor ?? $idUser, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            // Dois cadastros iguais ao mesmo tempo: o indice unico do banco decide; quem chegou depois ve a mensagem.
            if ($exception->getCode() === '23000') {
                return ['ok' => false, 'erros' => ['usuario' => 'Usuário, e-mail, CPF ou CNPJ acabou de ser cadastrado. Confira os dados.'], 'mensagem' => 'Confira os campos destacados.'];
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $cobranca = ['ok' => true, 'mensagem' => ''];
        if (!$gratis) {
            $vencimento = $teste ? (string) $assinatura['vence_em'] : $hoje->modify('+' . max(1, $regras['vencimento_dias']) . ' days')->format('Y-m-d');
            $cobranca = $this->cobrancaServico()->gerar($idAssinatura, $vencimento);
        }
        return ['ok' => true, 'id_restaurante' => $idRest, 'id_user' => $idUser, 'slug' => $slug, 'cobranca' => $cobranca, 'gratis' => $gratis, 'teste' => $teste];
    }

    /** "Cantina do Zé" -> cantina-do-ze (ou cantina-do-ze-2...). Nunca um slug reservado. */
    private function slugLivre(string $nome): string
    {
        $base = Slug::gerar($nome);
        if ($base === '' || !Slug::formatoValido($base)) {
            $base = 'restaurante';
        }
        $base = substr($base, 0, 150);
        $trava = InsumoService::paraAtualizar($this->db);
        for ($n = 1; $n <= 200; $n++) {
            $slug = $n === 1 ? $base : $base . '-' . $n;
            if (Slug::reservado($slug)) {
                continue;
            }
            $q = $this->db->prepare('SELECT 1 FROM restaurantes WHERE slug = ? LIMIT 1' . $trava);
            $q->execute([$slug]);
            if ($q->fetchColumn() === false) {
                return $slug;
            }
        }
        return $base . '-' . bin2hex(random_bytes(3));
    }

    private function existe(string $sql, string $valor): bool
    {
        $q = $this->db->prepare($sql);
        $q->execute([$valor]);
        return $q->fetchColumn() !== false;
    }

    private function valor(string $sql, string $param): mixed
    {
        $q = $this->db->prepare($sql);
        $q->execute([$param]);
        return $q->fetchColumn();
    }

    private function cobrancaServico(): AssinaturaCobranca
    {
        return $this->cobranca ?? new AssinaturaCobranca($this->db, hoje: $this->hoje);
    }

    private function hoje(): DateTimeImmutable
    {
        return ($this->hoje ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }
}
