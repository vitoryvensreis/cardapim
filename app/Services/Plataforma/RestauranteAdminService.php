<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Modules\Restaurante\LogoUpload;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\Plataforma\RestauranteAdminRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\LoginLembrado;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Cadastro\CadastroRestaurante;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Services\Gerencia\PerfilService;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Validation\Documento;
use PDO;
use Throwable;

/**
 * Restaurantes pela administracao da plataforma: cadastrar (com gerente e plano), editar dados, liberar/suspender/desativar,
 * trocar o plano (inclusive privado) e excluir quem ainda nao teve movimento. Tudo com log em log_user.
 */
final class RestauranteAdminService
{
    /** Situacoes que o administrador escolhe na mao (pendente e inadimplente vem da cobranca). */
    public const ACOES_STATUS = [
        'ativo' => ['Liberar acesso', 'O restaurante volta a funcionar: equipe entra e o cardápio aparece.'],
        'suspenso' => ['Suspender', 'Pausa temporária: ninguém da equipe entra e o cardápio sai do ar. Dá para liberar depois.'],
        'inativo' => ['Desativar', 'Encerrado: some do catálogo, ninguém entra. Os dados e o histórico ficam guardados.'],
    ];

    private readonly RestauranteAdminRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor)
    {
        $this->repo = new RestauranteAdminRepository($db);
    }

    /** @param array<string,mixed> $d */
    public function criar(array $d): Resultado
    {
        $r = (new CadastroRestaurante($this->db))->cadastrarPeloPainel($d, $this->autor->id);
        if (!$r['ok']) {
            return Resultado::falha((string) ($r['mensagem'] ?? 'Confira os campos destacados.'), $r['erros'] ?? []);
        }
        $nome = trim((string) ($d['nome_restaurante'] ?? ''));
        $msg = sprintf('Restaurante "%s" cadastrado. O gerente entra com o usuário "%s" e a senha que você definiu.', $nome, strtolower(trim((string) ($d['usuario'] ?? ''))));
        if (isset($r['cobranca']) && !$r['cobranca']['ok']) {
            $msg .= ' Atenção: ' . $r['cobranca']['mensagem'];
        }
        return Resultado::sucesso($msg, (int) $r['id_restaurante']);
    }

    /** Nome, telefone, e-mail, endereco (mesmas regras do Perfil) e CNPJ. @param array<string,mixed> $d */
    public function salvarDados(int $id, array $d): Resultado
    {
        $antes = $this->repo->buscar($id);
        if ($antes === null) {
            return Resultado::falha('Restaurante não encontrado.');
        }
        Auditoria::noRestaurante('Plataforma · ' . $antes['nome']);
        $cnpjTexto = trim((string) ($d['cnpj'] ?? ''));
        $cnpj = $cnpjTexto === '' ? null : Documento::somenteDigitos($cnpjTexto);
        $erroCnpj = null;
        if ($cnpj !== null && !Documento::cnpjValido($cnpj)) {
            $erroCnpj = 'CNPJ inválido. Confira os números ou deixe em branco.';
        } elseif ($cnpj !== null && $this->repo->cnpjEmUso($cnpj, $id)) {
            $erroCnpj = 'Este CNPJ já está em outro restaurante.';
        } elseif ($cnpj === null && $antes['cnpj'] !== null && (float) ($antes['plano_valor'] ?? 0) > 0) {
            $erroCnpj = 'Restaurante com plano pago precisa do CNPJ (o Asaas usa para a cobrança).';
        }
        $perfil = (new PerfilService($this->db, $this->autor, $id))->salvarDados($d);
        if ($erroCnpj !== null || !$perfil->ok) {
            $erros = $perfil->ok ? [] : $perfil->erros;
            if ($erroCnpj !== null) {
                $erros['cnpj'] = $erroCnpj;
            }
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        if ($cnpj !== ($antes['cnpj'] !== null ? (string) $antes['cnpj'] : null)) {
            $this->repo->definirCnpj($id, $cnpj);
            Auditoria::registrar($this->autor, 'Restaurante: CNPJ alterado', sprintf(
                '%s: de %s para %s.',
                $antes['nome'],
                $antes['cnpj'] !== null ? Documento::formatarCnpj((string) $antes['cnpj']) : 'vazio',
                $cnpj !== null ? Documento::formatarCnpj($cnpj) : 'vazio',
            ));
        }
        return Resultado::sucesso('Dados de "' . trim((string) ($d['nome'] ?? $antes['nome'])) . '" salvos.', $id);
    }

    public function mudarStatus(int $id, string $novo, string $motivo): Resultado
    {
        $r = $this->repo->buscar($id);
        if ($r === null) {
            return Resultado::falha('Restaurante não encontrado.');
        }
        if (!isset(self::ACOES_STATUS[$novo])) {
            return Resultado::falha('Escolha uma situação válida.');
        }
        if ($r['status_rest'] === $novo) {
            return Resultado::falha('O restaurante já está nesta situação.');
        }
        $motivo = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $motivo)), 0, 200);
        if ($novo !== 'ativo' && mb_strlen($motivo) < 3) {
            return Resultado::falha('Escreva o motivo (aparece no histórico da plataforma).');
        }
        $this->repo->definirStatus($id, $novo);
        if ($novo !== 'ativo') {
            $lembrados = new LoginLembrado($this->db);
            foreach ($this->repo->idsContas($id) as $idUser) {
                $lembrados->revogarTodos($idUser);
            }
        }
        Auditoria::noRestaurante('Plataforma · ' . $r['nome']);
        Auditoria::registrar($this->autor, 'Restaurante: ' . mb_strtolower(self::ACOES_STATUS[$novo][0]), sprintf(
            '%s: de "%s" para "%s".%s',
            $r['nome'],
            self::rotuloStatus((string) $r['status_rest']),
            self::rotuloStatus($novo),
            $motivo !== '' ? ' Motivo: ' . $motivo : '',
        ));
        return Resultado::sucesso(match ($novo) {
            'ativo' => '"' . $r['nome'] . '" liberado: a equipe já pode entrar.',
            'suspenso' => '"' . $r['nome'] . '" suspenso: a equipe foi desconectada e o cardápio saiu do ar.',
            default => '"' . $r['nome'] . '" desativado. Os dados continuam guardados.',
        }, $id);
    }

    public function trocarPlano(int $id, int $planoId): Resultado
    {
        $r = $this->repo->buscar($id);
        if ($r === null) {
            return Resultado::falha('Restaurante não encontrado.');
        }
        Auditoria::noRestaurante('Plataforma · ' . $r['nome']);
        return (new AssinaturaService($this->db, $this->autor, $id))->assinar($planoId, true);
    }

    /** Exclui de vez so restaurante sem movimento; pede o endereco (slug) digitado para confirmar. */
    public function excluir(int $id, string $confirmacao): Resultado
    {
        $r = $this->repo->buscar($id);
        if ($r === null) {
            return Resultado::falha('Restaurante não encontrado. Ele pode já ter sido excluído.');
        }
        if (trim($confirmacao) !== (string) $r['slug']) {
            return Resultado::falha('Para excluir, digite exatamente o endereço do restaurante: ' . $r['slug']);
        }
        $bloqueio = self::motivoNaoExcluir($this->repo->movimento($id));
        if ($bloqueio !== null) {
            return Resultado::falha($bloqueio);
        }
        $vigente = (new AssinaturaRepository($this->db))->vigente($id);
        if ($vigente !== null && $vigente['asaas_assinatura_id'] !== null) {
            $erro = (new AssinaturaCobranca($this->db))->remover($vigente);
            if ($erro !== null) {
                return Resultado::falha('Não foi possível cancelar a cobrança no Asaas: ' . $erro);
            }
        }
        $contas = count($this->repo->idsContas($id));
        $this->db->beginTransaction();
        try {
            $this->repo->excluir($id);
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        LogoUpload::apagar($r['logo_path'] !== null ? (string) $r['logo_path'] : null);
        Auditoria::registrar($this->autor, 'Restaurante excluído', sprintf(
            '%s (%s, CNPJ %s): restaurante sem movimento apagado com %d conta(s) de equipe, cardápio e configurações.',
            $r['nome'],
            $r['slug'],
            $r['cnpj'] !== null ? Documento::formatarCnpj((string) $r['cnpj']) : 'não informado',
            $contas,
        ));
        return Resultado::sucesso('Restaurante "' . $r['nome'] . '" excluído.');
    }

    /**
     * null = pode excluir. Com pedidos, caixas, contas, faturas pagas ou integracoes ligadas, so desativar.
     * @param array{pedidos:int, caixas:int, contas_financeiras:int, faturas_pagas:int, integracoes:list<string>} $m
     */
    public static function motivoNaoExcluir(array $m): ?string
    {
        $tem = [];
        if ($m['pedidos'] > 0) {
            $tem[] = $m['pedidos'] === 1 ? '1 pedido' : $m['pedidos'] . ' pedidos';
        }
        if ($m['caixas'] > 0) {
            $tem[] = 'caixas abertos';
        }
        if ($m['contas_financeiras'] > 0) {
            $tem[] = 'contas no financeiro';
        }
        if ($m['faturas_pagas'] > 0) {
            $tem[] = 'faturas pagas';
        }
        if ($tem !== []) {
            return 'Este restaurante já tem ' . implode(', ', $tem) . '. Para não perder o histórico, use "Desativar" em vez de excluir.';
        }
        if ($m['integracoes'] !== []) {
            return 'Desconecte antes as integrações do restaurante (' . implode(', ', array_map(static fn (string $p): string => match ($p) {
                'whatsapp' => 'WhatsApp', 'asaas' => 'Asaas', 'ifood' => 'iFood', default => $p,
            }, $m['integracoes'])) . '): entre no painel dele > Integrações.';
        }
        return null;
    }

    public static function rotuloStatus(string $status): string
    {
        return match ($status) {
            'ativo' => 'Ativo',
            'pendente' => 'Aguardando 1º pagamento',
            'inadimplente' => 'Pagamento atrasado',
            'suspenso' => 'Suspenso',
            'inativo' => 'Desativado',
            default => $status,
        };
    }
}
