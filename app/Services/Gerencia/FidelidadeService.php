<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Descontos\Desconto;
use Cardapim\Services\Descontos\Fidelidade;
use Cardapim\Shared\Numero;
use PDO;

/** Aba Clientes > Fidelidade: liga/desliga e configura o programa (uma linha por restaurante). */
final class FidelidadeService
{
    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
    }

    /** @return array{ativo:bool, compras_necessarias:int, tipo:string, valor:string, valor_minimo:string} */
    public function config(): array
    {
        return (new Fidelidade($this->db))->config($this->idRestaurante);
    }

    /** Valores do formulario. @return array<string,string> */
    public function valoresForm(): array
    {
        $c = $this->config();
        return [
            'ativo' => $c['ativo'] ? '1' : '',
            'compras_necessarias' => (string) $c['compras_necessarias'],
            'tipo' => $c['tipo'],
            'valor' => $c['tipo'] === 'percentual' ? rtrim(rtrim(number_format((float) $c['valor'], 2, ',', ''), '0'), ',') : Numero::dinheiroCampo($c['valor']),
            'quais' => Numero::centavos($c['valor_minimo']) > 0 ? 'minimo' : 'todos',
            'valor_minimo' => Numero::centavos($c['valor_minimo']) > 0 ? Numero::dinheiroCampo($c['valor_minimo']) : '',
        ];
    }

    /**
     * Numeros do programa: recompensas usadas, quanto foi descontado e clientes que ja compraram pelo cardapio online.
     * @return array{recompensas:int, descontado:string, clientes:int}
     */
    public function numeros(): array
    {
        $q = $this->db->prepare("SELECT COUNT(*), COALESCE(SUM(desconto), 0) FROM pedidos WHERE id_restaurante = ? AND desconto_origem = 'fidelidade' AND status <> 'cancelado'");
        $q->execute([$this->idRestaurante]);
        [$qtd, $soma] = $q->fetch(PDO::FETCH_NUM);
        $c = $this->db->prepare("SELECT COUNT(DISTINCT id_cliente) FROM pedidos WHERE id_restaurante = ? AND id_cliente IS NOT NULL AND status = 'concluido'");
        $c->execute([$this->idRestaurante]);
        return ['recompensas' => (int) $qtd, 'descontado' => (string) $soma, 'clientes' => (int) $c->fetchColumn()];
    }

    /** @param array<string,mixed> $d */
    public function salvar(array $d): Resultado
    {
        $texto = static fn (string $k): string => is_string($d[$k] ?? null) ? trim((string) $d[$k]) : '';
        $erros = [];
        $ativo = ($d['ativo'] ?? '') === '1';
        $n = $texto('compras_necessarias');
        if (!ctype_digit($n) || (int) $n < 1 || (int) $n > 100) {
            $erros['compras_necessarias'] = 'Digite quantos pedidos o cliente precisa fazer (de 1 a 100).';
        }
        $tipo = $texto('tipo') === 'fixo' ? 'fixo' : ($texto('tipo') === 'percentual' ? 'percentual' : '');
        if ($tipo === '') {
            $erros['tipo'] = 'Escolha se o desconto é em % ou em reais.';
        }
        $valor = null;
        if ($tipo === 'percentual') {
            $pct = str_replace(',', '.', str_replace('%', '', $texto('valor')));
            if (!preg_match('/\A\d{1,3}(\.\d{1,2})?\z/', $pct) || (float) $pct <= 0 || (float) $pct > 100) {
                $erros['valor'] = 'Digite a porcentagem, de 1 a 100 (ex.: 10).';
            } else {
                $valor = number_format((float) $pct, 2, '.', '');
            }
        } elseif ($tipo === 'fixo') {
            $valor = Numero::dinheiroParaDecimal($texto('valor'));
            if ($valor === null || Numero::centavos($valor) <= 0 || Numero::centavos($valor) > 10000000) {
                $erros['valor'] = 'Digite o valor do desconto (ex.: 10,00).';
                $valor = null;
            }
        }
        $minimo = '0.00';
        if ($texto('quais') === 'minimo') {
            $m = Numero::dinheiroParaDecimal($texto('valor_minimo'));
            if ($m === null || Numero::centavos($m) <= 0 || Numero::centavos($m) > 10000000) {
                $erros['valor_minimo'] = 'Digite o valor mínimo do pedido (ex.: 30,00).';
            } else {
                $minimo = $m;
            }
        } elseif ($texto('quais') !== 'todos') {
            $erros['quais'] = 'Escolha quais pedidos contam.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $antes = $this->config();
        $novo = ['ativo' => $ativo, 'compras_necessarias' => (int) $n, 'tipo' => $tipo, 'valor' => (string) $valor, 'valor_minimo' => $minimo];
        $agora = date('Y-m-d H:i:s');
        $existe = $this->db->prepare('SELECT 1 FROM restaurante_fidelidade WHERE id_restaurante = ?');
        $existe->execute([$this->idRestaurante]);
        if ($existe->fetchColumn() === false) {
            $this->db->prepare('INSERT INTO restaurante_fidelidade (id_restaurante, ativo, compras_necessarias, tipo, valor, valor_minimo, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$this->idRestaurante, $ativo ? 1 : 0, (int) $n, $tipo, $valor, $minimo, $agora]);
        } else {
            $this->db->prepare('UPDATE restaurante_fidelidade SET ativo = ?, compras_necessarias = ?, tipo = ?, valor = ?, valor_minimo = ?, atualizado_em = ? WHERE id_restaurante = ?')
                ->execute([$ativo ? 1 : 0, (int) $n, $tipo, $valor, $minimo, $agora, $this->idRestaurante]);
        }
        $mudou = Auditoria::diferencas(
            ['ativo' => $antes['ativo'] ? 'ligado' : 'desligado', 'regra' => Fidelidade::regra($antes)],
            ['ativo' => $ativo ? 'ligado' : 'desligado', 'regra' => Fidelidade::regra($novo)],
            ['ativo' => 'Programa', 'regra' => 'Regra']
        );
        Auditoria::registrar($this->autor, 'Fidelidade configurada', $mudou !== '' ? $mudou . '.' : 'Sem mudanças.');
        if (!$ativo) {
            return Resultado::sucesso('Programa de fidelidade desligado. As compras continuam contando quando você ligar de novo.');
        }
        return Resultado::sucesso('Programa de fidelidade ligado: ' . mb_strtolower(mb_substr(Fidelidade::regra($novo), 0, 1)) . mb_substr(Fidelidade::regra($novo), 1));
    }

    /** Exemplo do desconto para a tela: em um pedido de R$ 50,00. */
    public static function exemplo(array $c): string
    {
        $d = Desconto::calcular((string) $c['tipo'], (string) $c['valor'], 5000);
        return 'Num pedido de R$ 50,00, o desconto seria de ' . Numero::reais(Desconto::dec($d)) . '.';
    }
}
