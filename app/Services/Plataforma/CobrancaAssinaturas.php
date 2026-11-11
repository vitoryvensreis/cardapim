<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Numero;
use DateTimeImmutable;
use PDO;

/**
 * Regras de cobranca das ASSINATURAS dos restaurantes (conta Asaas da plataforma).
 *
 * - Formas aceitas: Pix, boleto e/ou cartao. Mais de uma = a fatura do Asaas deixa o restaurante escolher (UNDEFINED).
 * - Ciclo vem do plano: mensal = MONTHLY, semestral = SEMIANNUALLY, anual = YEARLY. O Asaas gera cada nova cobranca sozinho.
 * - Multa (ate 2%, limite do Codigo de Defesa do Consumidor), juros ao mes e desconto para pagar antes.
 * - Tolerancia: dias de atraso antes de o Cardapim suspender o restaurante (feito pelo Cardapim, nao pelo Asaas).
 */
final class CobrancaAssinaturas
{
    public const FORMAS = ['pix' => 'Pix', 'boleto' => 'Boleto', 'cartao' => 'Cartão de crédito'];
    public const TIPO_ASAAS = ['pix' => 'PIX', 'boleto' => 'BOLETO', 'cartao' => 'CREDIT_CARD'];
    public const CICLOS = ['mensal' => 'MONTHLY', 'semestral' => 'SEMIANNUALLY', 'anual' => 'YEARLY'];
    public const PADRAO = [
        'formas' => ['pix', 'boleto'], 'vencimento_dias' => 3, 'teste_dias' => 0, 'multa_percentual' => '2.00', 'juros_mensal' => '1.00',
        'desconto_percentual' => '0.00', 'desconto_dias' => 0, 'tolerancia_dias' => 7, 'avisos_asaas' => true, 'atualizado_em' => null,
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{formas:list<string>, vencimento_dias:int, teste_dias:int, multa_percentual:string, juros_mensal:string,
     *   desconto_percentual:string, desconto_dias:int, tolerancia_dias:int, avisos_asaas:bool, atualizado_em:?string}
     */
    public function regras(): array
    {
        try {
            $r = $this->db->query('SELECT * FROM plataforma_cobranca WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            $r = false; // tabela ainda nao criada (antes da migration): usa o padrao
        }
        if ($r === false) {
            return self::PADRAO;
        }
        $formas = array_values(array_intersect(array_keys(self::FORMAS), explode(',', (string) $r['formas'])));
        return [
            'formas' => $formas !== [] ? $formas : self::PADRAO['formas'],
            'vencimento_dias' => (int) $r['vencimento_dias'],
            'teste_dias' => (int) $r['teste_dias'],
            'multa_percentual' => number_format((float) $r['multa_percentual'], 2, '.', ''),
            'juros_mensal' => number_format((float) $r['juros_mensal'], 2, '.', ''),
            'desconto_percentual' => number_format((float) $r['desconto_percentual'], 2, '.', ''),
            'desconto_dias' => (int) $r['desconto_dias'],
            'tolerancia_dias' => (int) $r['tolerancia_dias'],
            'avisos_asaas' => (int) $r['avisos_asaas'] === 1,
            'atualizado_em' => $r['atualizado_em'] !== null ? (string) $r['atualizado_em'] : null,
        ];
    }

    /** @param array<string,mixed> $d dados do formulario */
    public function salvar(array $d, UsuarioLogado $autor): Resultado
    {
        $erros = [];
        $formas = array_values(array_intersect(array_keys(self::FORMAS), is_array($d['formas'] ?? null) ? array_map('strval', $d['formas']) : []));
        if ($formas === []) {
            $erros['formas'] = 'Escolha pelo menos uma forma de pagamento.';
        }
        $inteiro = static function (string $campo, int $max) use ($d, &$erros): int {
            $v = trim((string) ($d[$campo] ?? ''));
            if ($v === '') {
                return 0;
            }
            if (!ctype_digit($v) || (int) $v > $max) {
                $erros[$campo] = 'Use um número de 0 a ' . $max . '.';
                return 0;
            }
            return (int) $v;
        };
        $percentual = static function (string $campo, float $max) use ($d, &$erros): string {
            $v = trim(str_replace('%', '', (string) ($d[$campo] ?? '')));
            if ($v === '') {
                return '0.00';
            }
            $dec = Numero::dinheiroParaDecimal($v);
            if ($dec === null || (float) $dec < 0 || (float) $dec > $max) {
                $erros[$campo] = 'Use de 0 a ' . str_replace('.', ',', (string) $max) . '%.';
                return '0.00';
            }
            return $dec;
        };
        $novo = [
            'formas' => $formas,
            'vencimento_dias' => $inteiro('vencimento_dias', 30),
            'teste_dias' => $inteiro('teste_dias', 60),
            'multa_percentual' => $percentual('multa_percentual', 2),
            'juros_mensal' => $percentual('juros_mensal', 10),
            'desconto_percentual' => $percentual('desconto_percentual', 50),
            'desconto_dias' => $inteiro('desconto_dias', 30),
            'tolerancia_dias' => $inteiro('tolerancia_dias', 60),
            'avisos_asaas' => ($d['avisos_asaas'] ?? '') === '1',
        ];
        if (!isset($erros['desconto_percentual']) && (float) $novo['desconto_percentual'] > 0 && $novo['desconto_dias'] === 0 && !isset($erros['desconto_dias'])) {
            $erros['desconto_dias'] = 'Diga até quantos dias antes do vencimento vale o desconto.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $antes = $this->regras();
        $agora = date('Y-m-d H:i:s');
        $this->db->prepare('UPDATE plataforma_cobranca SET formas = ?, vencimento_dias = ?, teste_dias = ?, multa_percentual = ?, juros_mensal = ?,
                desconto_percentual = ?, desconto_dias = ?, tolerancia_dias = ?, avisos_asaas = ?, id_user = ?, atualizado_em = ? WHERE id = 1')
            ->execute([implode(',', $formas), $novo['vencimento_dias'], $novo['teste_dias'], $novo['multa_percentual'], $novo['juros_mensal'],
                $novo['desconto_percentual'], $novo['desconto_dias'], $novo['tolerancia_dias'], $novo['avisos_asaas'] ? 1 : 0, $autor->id, $agora]);
        Auditoria::registrar($autor, 'Cobrança das assinaturas', self::resumo($antes) . ' -> ' . self::resumo($novo) . ' [Plataforma]');
        return Resultado::sucesso('Regras de cobrança salvas. Valem para as próximas assinaturas.');
    }

    /** Tipo de cobranca no Asaas: uma forma = ela; varias = UNDEFINED (o restaurante escolhe na fatura). @param list<string> $formas */
    public static function tipoAsaas(array $formas): string
    {
        return count($formas) === 1 ? self::TIPO_ASAAS[$formas[0]] : 'UNDEFINED';
    }

    /** Vencimento da primeira cobranca: depois do teste gratis ou em N dias. */
    public static function primeiroVencimento(array $regras, ?DateTimeImmutable $hoje = null): string
    {
        $dias = $regras['teste_dias'] > 0 ? $regras['teste_dias'] : $regras['vencimento_dias'];
        return ($hoje ?? new DateTimeImmutable('today'))->modify('+' . (int) $dias . ' days')->format('Y-m-d');
    }

    /**
     * Campos de multa, juros e desconto no formato da API de assinaturas do Asaas.
     * @return array<string, array<string, float|int|string>>
     */
    public static function encargosAsaas(array $regras): array
    {
        $c = [];
        if ((float) $regras['multa_percentual'] > 0) {
            $c['fine'] = ['value' => (float) $regras['multa_percentual'], 'type' => 'PERCENTAGE'];
        }
        if ((float) $regras['juros_mensal'] > 0) {
            $c['interest'] = ['value' => (float) $regras['juros_mensal']];
        }
        if ((float) $regras['desconto_percentual'] > 0 && $regras['desconto_dias'] > 0) {
            $c['discount'] = ['value' => (float) $regras['desconto_percentual'], 'dueDateLimitDays' => (int) $regras['desconto_dias'], 'type' => 'PERCENTAGE'];
        }
        return $c;
    }

    /** Texto curto das regras (log e tela). */
    public static function resumo(array $r): string
    {
        $pct = static fn (string $v): string => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',') . '%';
        return implode('; ', array_filter([
            implode(' ou ', array_map(static fn (string $f): string => self::FORMAS[$f], $r['formas'])),
            $r['teste_dias'] > 0 ? $r['teste_dias'] . ' dias grátis' : '1ª cobrança em ' . $r['vencimento_dias'] . ' dias',
            'multa ' . $pct($r['multa_percentual']),
            'juros ' . $pct($r['juros_mensal']) . ' ao mês',
            (float) $r['desconto_percentual'] > 0 ? 'desconto ' . $pct($r['desconto_percentual']) . ' até ' . $r['desconto_dias'] . ' dias antes' : null,
            'suspende após ' . $r['tolerancia_dias'] . ' dias',
            $r['avisos_asaas'] ? 'Asaas avisa o restaurante' : 'sem avisos do Asaas',
        ]));
    }
}
