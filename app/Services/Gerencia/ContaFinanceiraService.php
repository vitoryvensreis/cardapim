<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Repositories\Gerencia\FinanceiroRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Shared\Numero;
use DateTimeImmutable;
use PDO;
use Throwable;

/** Contas a pagar e a receber: cadastro (com repeticao mensal), baixa, desfazer baixa e exclusao, com auditoria. */
final class ContaFinanceiraService
{
    public const CATEGORIAS = [
        'pagar' => [
            'fornecedor' => 'Fornecedor e mercadoria',
            'aluguel' => 'Aluguel',
            'contas_consumo' => 'Água, luz e gás',
            'internet' => 'Internet e telefone',
            'salarios' => 'Salários e diárias',
            'impostos' => 'Impostos e taxas',
            'manutencao' => 'Manutenção e equipamentos',
            'divulgacao' => 'Divulgação',
            'outra_despesa' => 'Outra despesa',
        ],
        'receber' => [
            'encomenda' => 'Encomenda ou evento',
            'fiado' => 'Venda a prazo (fiado)',
            'convenio' => 'Convênio com empresa',
            'outra_receita' => 'Outra receita',
        ],
    ];

    public const FORMAS = [
        'dinheiro' => 'Dinheiro',
        'pix' => 'Pix',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'boleto' => 'Boleto',
        'transferencia' => 'Transferência',
    ];

    public const MAX_REPETIR = 12;

    private const ROTULOS = ['tipo' => 'Tipo', 'descricao' => 'Descrição', 'categoria' => 'Categoria', 'valor' => 'Valor', 'vencimento' => 'Vencimento', 'observacao' => 'Observação'];

    private readonly FinanceiroRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $autor, private readonly int $idRestaurante)
    {
        $this->repo = new FinanceiroRepository($db);
    }

    public static function rotuloCategoria(string $tipo, string $categoria): string
    {
        return self::CATEGORIAS[$tipo][$categoria] ?? ($tipo === 'receber' ? 'Outra receita' : 'Outra despesa');
    }

    /** @param array<string,mixed> $entrada */
    public function salvar(?int $id, array $entrada): Resultado
    {
        $antes = null;
        if ($id !== null) {
            $antes = $this->repo->conta($this->idRestaurante, $id);
            if ($antes === null) {
                return Resultado::falha('Conta não encontrada. Ela pode ter sido excluída.');
            }
        }
        [$dados, $repetir, $erros] = $this->validar($entrada, $antes === null);
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $nomeTipo = $dados['tipo'] === 'pagar' ? 'a pagar' : 'a receber';

        if ($antes !== null) {
            $this->repo->atualizarConta($this->idRestaurante, (int) $id, $dados);
            Auditoria::registrar($this->autor, 'Conta editada', $antes['descricao'] . ' - ' . Auditoria::diferencas($antes, $dados, self::ROTULOS));
            return Resultado::sucesso('Conta "' . $dados['descricao'] . '" atualizada.', $id);
        }

        $this->db->beginTransaction();
        try {
            $primeiro = new DateTimeImmutable($dados['vencimento']);
            $diaDoMes = (int) $primeiro->format('d');
            $primeiroId = 0;
            for ($i = 0; $i < $repetir; $i++) {
                $parcela = $dados;
                $parcela['vencimento'] = self::mesesDepois($primeiro, $i, $diaDoMes);
                if ($repetir > 1) {
                    $parcela['descricao'] = mb_substr($dados['descricao'], 0, 112) . ' (' . ($i + 1) . '/' . $repetir . ')';
                }
                $novo = $this->repo->criarConta($this->idRestaurante, $this->autor->id, $parcela);
                $primeiroId = $primeiroId ?: $novo;
            }
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $erro;
        }
        $valor = Numero::reais($dados['valor']);
        Auditoria::registrar($this->autor, 'Conta cadastrada', sprintf(
            '%s %s: %s, vence %s%s.',
            ucfirst($nomeTipo),
            $dados['descricao'],
            $valor,
            date('d/m/Y', (int) strtotime($dados['vencimento'])),
            $repetir > 1 ? ', repetida por ' . $repetir . ' meses' : '',
        ));
        return Resultado::sucesso($repetir > 1
            ? sprintf('%d contas %s de %s cadastradas, uma por mês.', $repetir, $nomeTipo, $valor)
            : sprintf('Conta %s "%s" cadastrada.', $nomeTipo, $dados['descricao']), $primeiroId);
    }

    /** Marca como paga (ou recebida). Sem data = hoje. */
    public function baixar(int $id, string $diaTexto = '', string $forma = ''): Resultado
    {
        $conta = $this->repo->conta($this->idRestaurante, $id);
        if ($conta === null) {
            return Resultado::falha('Conta não encontrada. Ela pode ter sido excluída.');
        }
        $verbo = $conta['tipo'] === 'receber' ? 'recebida' : 'paga';
        if ($conta['status'] === 'paga') {
            return Resultado::falha('Esta conta já está marcada como ' . $verbo . '.');
        }
        $dia = $diaTexto === '' ? date('Y-m-d') : self::data($diaTexto);
        if ($dia === null || $dia > date('Y-m-d')) {
            return Resultado::falha('Escolha uma data válida, de hoje ou antes.');
        }
        $forma = $forma === '' ? null : $forma;
        if ($forma !== null && !isset(self::FORMAS[$forma])) {
            return Resultado::falha('Escolha uma forma de pagamento da lista.');
        }
        if (!$this->repo->baixar($this->idRestaurante, $id, $dia, $forma)) {
            return Resultado::falha('Esta conta já está marcada como ' . $verbo . '.');
        }
        Auditoria::registrar($this->autor, 'Conta ' . $verbo, sprintf('%s: %s em %s%s.', $conta['descricao'], Numero::reais((string) $conta['valor']), date('d/m/Y', (int) strtotime($dia)), $forma ? ' (' . self::FORMAS[$forma] . ')' : ''));
        return Resultado::sucesso(sprintf('"%s" marcada como %s.', $conta['descricao'], $verbo), $id);
    }

    public function reabrir(int $id): Resultado
    {
        $conta = $this->repo->conta($this->idRestaurante, $id);
        if ($conta === null) {
            return Resultado::falha('Conta não encontrada. Ela pode ter sido excluída.');
        }
        if (!$this->repo->reabrir($this->idRestaurante, $id)) {
            return Resultado::falha('Esta conta já está em aberto.');
        }
        Auditoria::registrar($this->autor, 'Baixa de conta desfeita', sprintf('%s: %s voltou para em aberto.', $conta['descricao'], Numero::reais((string) $conta['valor'])));
        return Resultado::sucesso('"' . $conta['descricao'] . '" voltou para em aberto.', $id);
    }

    public function excluir(int $id): Resultado
    {
        $conta = $this->repo->conta($this->idRestaurante, $id);
        if ($conta === null || !$this->repo->excluirConta($this->idRestaurante, $id)) {
            return Resultado::falha('Conta não encontrada. Ela pode já ter sido excluída.');
        }
        Auditoria::registrar($this->autor, 'Conta excluída', sprintf(
            '%s (%s, %s, vencimento %s, %s).',
            $conta['descricao'],
            $conta['tipo'] === 'pagar' ? 'a pagar' : 'a receber',
            Numero::reais((string) $conta['valor']),
            date('d/m/Y', (int) strtotime((string) $conta['vencimento'])),
            $conta['status'] === 'paga' ? 'estava baixada em ' . date('d/m/Y', (int) strtotime((string) $conta['pago_em'])) : 'estava em aberto',
        ));
        return Resultado::sucesso('Conta "' . $conta['descricao'] . '" excluída.');
    }

    /**
     * @param array<string,mixed> $e
     * @return array{0:array{tipo:string,descricao:string,categoria:string,valor:string,vencimento:string,observacao:?string}, 1:int, 2:array<string,string>}
     */
    private function validar(array $e, bool $nova): array
    {
        $erros = [];
        $tipo = (string) ($e['tipo'] ?? '');
        if (!isset(self::CATEGORIAS[$tipo])) {
            $erros['tipo'] = 'Escolha se é uma conta a pagar ou a receber.';
            $tipo = 'pagar';
        }
        $descricao = self::texto($e['descricao'] ?? '');
        if (mb_strlen($descricao) < 2 || mb_strlen($descricao) > 120) {
            $erros['descricao'] = 'Digite uma descrição entre 2 e 120 letras. Exemplo: Aluguel de novembro';
        }
        $categoria = (string) ($e['categoria'] ?? '');
        if (!isset(self::CATEGORIAS[$tipo][$categoria])) {
            $erros['categoria'] = 'Escolha a categoria da lista.';
        }
        $valor = Numero::dinheiroParaDecimal((string) ($e['valor'] ?? ''));
        if ($valor === null || Numero::centavos($valor) <= 0 || Numero::centavos($valor) > 999_999_999) {
            $erros['valor'] = 'Digite o valor em reais, maior que zero. Exemplo: 1.250,00';
            $valor = '0.00';
        }
        $vencimento = self::data((string) ($e['vencimento'] ?? ''));
        if ($vencimento === null) {
            $erros['vencimento'] = 'Escolha a data de vencimento.';
        }
        $observacao = self::texto($e['observacao'] ?? '');
        if (mb_strlen($observacao) > 300) {
            $erros['observacao'] = 'A observação pode ter até 300 letras.';
        }
        $repetir = 1;
        if ($nova) {
            $texto = (string) ($e['repetir'] ?? '1');
            $repetir = ctype_digit($texto) ? (int) $texto : 0;
            if ($repetir < 1 || $repetir > self::MAX_REPETIR) {
                $erros['repetir'] = 'Escolha de 1 a 12 meses.';
                $repetir = 1;
            }
        }
        return [[
            'tipo' => $tipo,
            'descricao' => $descricao,
            'categoria' => $categoria,
            'valor' => $valor,
            'vencimento' => $vencimento ?? '',
            'observacao' => $observacao === '' ? null : $observacao,
        ], $repetir, $erros];
    }

    /** Mesmo dia nos meses seguintes; dia 31 vira o ultimo dia em meses mais curtos. */
    private static function mesesDepois(DateTimeImmutable $primeiro, int $meses, int $dia): string
    {
        $mes = $primeiro->modify('first day of this month')->modify('+' . $meses . ' months');
        return $mes->format('Y-m-') . str_pad((string) min($dia, (int) $mes->format('t')), 2, '0', STR_PAD_LEFT);
    }

    private static function data(string $valor): ?string
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $valor) !== 1) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        return $d !== false && $d->format('Y-m-d') === $valor && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100 ? $valor : null;
    }

    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim((string) preg_replace('/\s+/u', ' ', $valor)) : '';
    }
}
