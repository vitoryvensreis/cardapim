<?php

declare(strict_types=1);

namespace Cardapim\Services\Gerencia;

use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Descontos\Cupons;
use Cardapim\Services\Descontos\Desconto;
use Cardapim\Shared\Numero;
use PDO;
use Throwable;

/**
 * Cupons do restaurante (aba Clientes > Cupons): criar, editar, pausar e excluir.
 * Cupom ja usado em pedido nao e excluido (o historico do pedido aponta para ele): pausar resolve.
 */
final class CupomService
{
    public const MAX_CUPONS = 200;
    public const USOS = ['varios' => 'Várias vezes, sem limite', 'limite' => 'Até um número de vezes', 'unico' => 'Só 1 vez no total'];

    public function __construct(private readonly PDO $db, private readonly int $idRestaurante, private readonly UsuarioLogado $autor)
    {
    }

    /** Cupons com usos e situacao. @return list<array<string,mixed>> */
    public function listar(): array
    {
        $q = $this->db->prepare(
            "SELECT c.*, (SELECT COUNT(*) FROM pedidos p WHERE p.id_cupom = c.id AND p.id_restaurante = c.id_restaurante AND p.status <> 'cancelado') AS usos,
                    (SELECT COALESCE(SUM(p.desconto), 0) FROM pedidos p WHERE p.id_cupom = c.id AND p.id_restaurante = c.id_restaurante AND p.status <> 'cancelado') AS descontado
             FROM cupons c WHERE c.id_restaurante = ? ORDER BY c.ativo DESC, c.id DESC"
        );
        $q->execute([$this->idRestaurante]);
        $lista = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($lista as &$c) {
            $c['situacao'] = self::situacao($c);
        }
        unset($c);
        return $lista;
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $q = $this->db->prepare('SELECT * FROM cupons WHERE id = ? AND id_restaurante = ?');
        $q->execute([$id, $this->idRestaurante]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        if ($c === false) {
            return null;
        }
        $c['usos'] = (new Cupons($this->db))->usos($this->idRestaurante, $id);
        $c['situacao'] = self::situacao($c);
        return $c;
    }

    /** ativo | pausado | vencido | esgotado (com texto para a tela). @param array<string,mixed> $c */
    public static function situacao(array $c): string
    {
        if ((int) $c['ativo'] !== 1) {
            return 'pausado';
        }
        if ($c['valido_ate'] !== null && (string) $c['valido_ate'] < date('Y-m-d')) {
            return 'vencido';
        }
        if ($c['limite_usos'] !== null && (int) ($c['usos'] ?? 0) >= (int) $c['limite_usos']) {
            return 'esgotado';
        }
        return 'ativo';
    }

    /** Valores do formulario a partir do cupom salvo. @param array<string,mixed> $c @return array<string,string> */
    public static function valoresForm(array $c): array
    {
        $limite = $c['limite_usos'] !== null ? (int) $c['limite_usos'] : null;
        return [
            'codigo' => (string) $c['codigo'],
            'tipo' => (string) $c['tipo'],
            'valor' => $c['tipo'] === 'percentual' ? rtrim(rtrim(number_format((float) $c['valor'], 2, ',', ''), '0'), ',') : Numero::dinheiroCampo((string) $c['valor']),
            'pedido_minimo' => Numero::centavos((string) $c['pedido_minimo']) > 0 ? Numero::dinheiroCampo((string) $c['pedido_minimo']) : '',
            'uso' => $limite === null ? 'varios' : ($limite === 1 ? 'unico' : 'limite'),
            'limite_usos' => $limite !== null && $limite > 1 ? (string) $limite : '',
            'um_por_cliente' => (int) $c['um_por_cliente'] === 1 ? '1' : '',
            'valido_ate' => (string) ($c['valido_ate'] ?? ''),
            'ativo' => (int) $c['ativo'] === 1 ? '1' : '',
        ];
    }

    /** @param array<string,mixed> $d */
    public function salvar(array $d, ?int $id = null): Resultado
    {
        $antes = $id !== null ? $this->buscar($id) : null;
        if ($id !== null && $antes === null) {
            return Resultado::falha('Cupom não encontrado. Ele pode ter sido excluído.');
        }
        $v = $this->validar($d, $id);
        if ($v instanceof Resultado) {
            return $v;
        }
        if ($id === null) {
            $q = $this->db->prepare('SELECT COUNT(*) FROM cupons WHERE id_restaurante = ?');
            $q->execute([$this->idRestaurante]);
            if ((int) $q->fetchColumn() >= self::MAX_CUPONS) {
                return Resultado::falha('Limite de ' . self::MAX_CUPONS . ' cupons. Exclua os que não usa mais.');
            }
        }
        $agora = date('Y-m-d H:i:s');
        try {
            if ($id === null) {
                $this->db->prepare('INSERT INTO cupons (id_restaurante, codigo, tipo, valor, pedido_minimo, limite_usos, um_por_cliente, valido_ate, ativo, criado_em, atualizado_em)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$this->idRestaurante, $v['codigo'], $v['tipo'], $v['valor'], $v['pedido_minimo'], $v['limite_usos'], $v['um_por_cliente'], $v['valido_ate'], $v['ativo'], $agora, $agora]);
                $id = (int) (new Cupons($this->db))->porCodigo($this->idRestaurante, $v['codigo'])['id'];
            } else {
                $this->db->prepare('UPDATE cupons SET codigo = ?, tipo = ?, valor = ?, pedido_minimo = ?, limite_usos = ?, um_por_cliente = ?, valido_ate = ?, ativo = ?, atualizado_em = ?
                    WHERE id = ? AND id_restaurante = ?')
                    ->execute([$v['codigo'], $v['tipo'], $v['valor'], $v['pedido_minimo'], $v['limite_usos'], $v['um_por_cliente'], $v['valido_ate'], $v['ativo'], $agora, $id, $this->idRestaurante]);
            }
        } catch (Throwable $erro) {
            // Dois cadastros com o mesmo codigo ao mesmo tempo (indice unico do banco).
            if ((new Cupons($this->db))->porCodigo($this->idRestaurante, $v['codigo']) !== null && $antes === null) {
                return Resultado::falha('Confira os campos destacados.', ['codigo' => 'Já existe um cupom com este código.']);
            }
            throw $erro;
        }
        $descricao = self::resumo($v);
        if ($antes === null) {
            Auditoria::registrar($this->autor, 'Cupom criado', $v['codigo'] . ': ' . $descricao . '.');
            return Resultado::sucesso('Cupom ' . $v['codigo'] . ' criado. Já pode ser usado no cardápio online.', $id);
        }
        Auditoria::registrar($this->autor, 'Cupom editado', $v['codigo'] . ': ' . $descricao . '.');
        return Resultado::sucesso('Cupom ' . $v['codigo'] . ' salvo.', $id);
    }

    public function alternar(int $id): Resultado
    {
        $c = $this->buscar($id);
        if ($c === null) {
            return Resultado::falha('Cupom não encontrado. Ele pode ter sido excluído.');
        }
        $ativo = (int) $c['ativo'] !== 1;
        $this->db->prepare('UPDATE cupons SET ativo = ?, atualizado_em = ? WHERE id = ? AND id_restaurante = ?')
            ->execute([$ativo ? 1 : 0, date('Y-m-d H:i:s'), $id, $this->idRestaurante]);
        Auditoria::registrar($this->autor, $ativo ? 'Cupom ativado' : 'Cupom pausado', (string) $c['codigo'] . '.');
        return Resultado::sucesso($ativo ? 'Cupom ' . $c['codigo'] . ' ativado.' : 'Cupom ' . $c['codigo'] . ' pausado. Os clientes não conseguem mais usar.');
    }

    public function excluir(int $id): Resultado
    {
        $c = $this->buscar($id);
        if ($c === null) {
            return Resultado::falha('Cupom não encontrado. Ele pode já ter sido excluído.');
        }
        $q = $this->db->prepare('SELECT COUNT(*) FROM pedidos WHERE id_cupom = ? AND id_restaurante = ?');
        $q->execute([$id, $this->idRestaurante]);
        if ((int) $q->fetchColumn() > 0) {
            return Resultado::falha('O cupom ' . $c['codigo'] . ' já foi usado em pedidos e fica no histórico. Use "Pausar" para ninguém mais usar.');
        }
        $this->db->prepare('DELETE FROM cupons WHERE id = ? AND id_restaurante = ?')->execute([$id, $this->idRestaurante]);
        Auditoria::registrar($this->autor, 'Cupom excluído', (string) $c['codigo'] . '.');
        return Resultado::sucesso('Cupom ' . $c['codigo'] . ' excluído.');
    }

    /** "10% de desconto, pedidos a partir de R$ 30,00, so 1 vez, cada cliente 1 vez, ate 31/12/2026". @param array<string,mixed> $c */
    public static function resumo(array $c, bool $comDesconto = true): string
    {
        $partes = $comDesconto ? [Desconto::rotulo((string) $c['tipo'], (string) $c['valor']) . ' de desconto'] : [];
        if (Numero::centavos((string) $c['pedido_minimo']) > 0) {
            $partes[] = 'pedidos a partir de ' . Numero::reais((string) $c['pedido_minimo']);
        }
        if ($c['limite_usos'] === null) {
            $partes[] = 'usos sem limite';
        } elseif ((int) $c['limite_usos'] === 1) {
            $partes[] = 'só 1 uso';
        } else {
            $partes[] = 'até ' . (int) $c['limite_usos'] . ' usos';
        }
        if ((int) $c['um_por_cliente'] === 1) {
            $partes[] = 'cada cliente usa 1 vez';
        }
        if (!empty($c['valido_ate'])) {
            $partes[] = 'válido até ' . date('d/m/Y', (int) strtotime((string) $c['valido_ate']));
        }
        return implode(', ', $partes);
    }

    /**
     * @param array<string,mixed> $d
     * @return array{codigo:string, tipo:string, valor:string, pedido_minimo:string, limite_usos:?int, um_por_cliente:int, valido_ate:?string, ativo:int}|Resultado
     */
    private function validar(array $d, ?int $id): array|Resultado
    {
        $erros = [];
        $texto = static fn (string $k): string => is_string($d[$k] ?? null) ? trim((string) $d[$k]) : '';
        $codigo = Cupons::normalizar($texto('codigo'));
        if ($texto('codigo') === '') {
            $erros['codigo'] = 'Digite o código que o cliente vai usar (ex.: BEMVINDO10).';
        } elseif (strlen($codigo) < 3 || strlen($codigo) > 20) {
            $erros['codigo'] = 'Use de 3 a 20 letras ou números (sem espaços).';
        } else {
            $q = $this->db->prepare('SELECT 1 FROM cupons WHERE id_restaurante = ? AND codigo = ? AND id <> ?');
            $q->execute([$this->idRestaurante, $codigo, $id ?? 0]);
            if ($q->fetchColumn() !== false) {
                $erros['codigo'] = 'Já existe um cupom com este código.';
            }
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
                $erros['valor'] = 'Digite o valor do desconto (ex.: 5,00).';
                $valor = null;
            }
        }
        $minimo = '0.00';
        if ($texto('pedido_minimo') !== '') {
            $m = Numero::dinheiroParaDecimal($texto('pedido_minimo'));
            if ($m === null || Numero::centavos($m) > 10000000) {
                $erros['pedido_minimo'] = 'Valor inválido. Deixe vazio para qualquer valor.';
            } else {
                $minimo = $m;
            }
        }
        $uso = $texto('uso');
        $limite = null;
        if ($uso === 'unico') {
            $limite = 1;
        } elseif ($uso === 'limite') {
            $n = $texto('limite_usos');
            if (!ctype_digit($n) || (int) $n < 2 || (int) $n > 100000) {
                $erros['limite_usos'] = 'Digite quantas vezes o cupom pode ser usado (2 ou mais).';
            } else {
                $limite = (int) $n;
            }
        } elseif ($uso !== 'varios') {
            $erros['uso'] = 'Escolha quantas vezes o cupom pode ser usado.';
        }
        $validade = $texto('valido_ate');
        if ($validade !== '') {
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $validade);
            if ($data === false || $data->format('Y-m-d') !== $validade) {
                $erros['valido_ate'] = 'Data inválida.';
            } elseif ($id === null && $validade < date('Y-m-d')) {
                $erros['valido_ate'] = 'A data já passou. Escolha hoje ou depois.';
            }
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        return [
            'codigo' => $codigo,
            'tipo' => $tipo,
            'valor' => (string) $valor,
            'pedido_minimo' => $minimo,
            'limite_usos' => $limite,
            'um_por_cliente' => ($d['um_por_cliente'] ?? '') === '1' ? 1 : 0,
            'valido_ate' => $validade !== '' ? $validade : null,
            'ativo' => $id === null || ($d['ativo'] ?? '') === '1' ? 1 : 0,
        ];
    }
}
