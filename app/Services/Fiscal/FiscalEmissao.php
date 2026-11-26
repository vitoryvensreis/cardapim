<?php
declare(strict_types=1);

namespace Cardapim\Services\Fiscal;

use Cardapim\Repositories\Gerencia\FiscalRepository;
use Cardapim\Services\Gerencia\FiscalAcesso;
use Cardapim\Shared\Esquema;
use Cardapim\Shared\Validation\Documento;
use PDO;
use Throwable;

/**
 * Portão único da emissão automática. Com o módulo fiscal desligado (ou incompleto) para o restaurante,
 * nada acontece: nenhuma consulta à NeXT, nenhuma linha gravada, nenhum erro propagado para o caixa.
 */
final class FiscalEmissao
{
    public const ORIGENS = ['mesa' => 'Mesa', 'balcao' => 'Balcão', 'delivery' => 'Delivery próprio', 'ifood' => 'iFood'];
    /** Padrão aprovado: automática em mesa, balcão e delivery próprio; iFood desligado. */
    public const PADRAO = ['mesa' => true, 'balcao' => true, 'delivery' => true, 'ifood' => false, 'imprimir' => true];

    /** @return array{mesa:bool,balcao:bool,delivery:bool,ifood:bool,imprimir:bool} */
    public static function configuracao(array $dados): array
    {
        $c = is_array($dados['emissao'] ?? null) ? $dados['emissao'] : [];
        $out = [];
        foreach (self::PADRAO as $k => $padrao) { $out[$k] = is_bool($c[$k] ?? null) ? $c[$k] : $padrao; }
        return $out;
    }

    /** Origem fiscal do pedido a partir de origem/tipo comerciais. */
    public static function origemDoPedido(string $origem, string $tipo): string
    {
        return match (true) {
            $origem === 'ifood' => 'ifood',
            $tipo === 'mesa' => 'mesa',
            $tipo === 'balcao' => 'balcao',
            default => 'delivery',
        };
    }

    /**
     * Restaurante pronto para emitir? Lê só o necessário e nunca lança exceção.
     * @return array{ambiente:int,config:array}|null
     */
    public static function pronto(PDO $db, int $idRestaurante): ?array
    {
        try {
            if (!Esquema::tem($db, 'fiscal_documentos') || !FiscalAcesso::permitido($db, $idRestaurante)) { return null; }
            $r = (new FiscalRepository($db))->buscar($idRestaurante);
            if ($r === null || !$r['habilitado'] || !$r['aprovado'] || !isset($r['dados']['token'])) { return null; }
            $q = $db->prepare('SELECT cnpj FROM restaurantes WHERE id = ?');
            $q->execute([$idRestaurante]);
            if ($r['cnpj'] !== Documento::somenteDigitos((string) $q->fetchColumn())) { return null; }
            return ['ambiente' => (int) ($r['dados']['ambiente'] ?? 3), 'config' => self::configuracao($r['dados'])];
        } catch (Throwable $e) {
            error_log('[Cardapim] Fiscal: verificação de emissão ignorada (' . get_class($e) . ').');
            return null;
        }
    }

    /**
     * Coloca a venda na fila de emissão, se o restaurante usa o módulo e a origem está ligada.
     * Chamar DEPOIS do commit da venda. Idempotente (uma nota por pedido). Nunca lança exceção.
     */
    public static function solicitar(PDO $db, int $idRestaurante, int $idPedido, bool $forcar = false): void
    {
        try {
            $pronto = self::pronto($db, $idRestaurante);
            if ($pronto === null) { return; }
            $q = $db->prepare('SELECT origem, tipo, status, total FROM pedidos WHERE id = ? AND id_restaurante = ?');
            $q->execute([$idPedido, $idRestaurante]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p || $p['status'] === 'cancelado') { return; }
            $origem = self::origemDoPedido((string) $p['origem'], (string) $p['tipo']);
            if (!$forcar && !$pronto['config'][$origem]) { return; }
            // Pequena folga para o caixa informar o CPF antes do primeiro envio.
            $db->prepare("INSERT IGNORE INTO fiscal_documentos (id_restaurante, id_pedido, origem, ambiente, status, valor, proxima_tentativa_em) VALUES (?, ?, ?, ?, 'pendente', ?, DATE_ADD(NOW(), INTERVAL 20 SECOND))")
                ->execute([$idRestaurante, $idPedido, $origem, $pronto['ambiente'], (string) $p['total']]);
        } catch (Throwable $e) {
            error_log('[Cardapim] Fiscal: venda ' . $idPedido . ' não entrou na fila (' . get_class($e) . ').');
        }
    }

    /** CPF/CNPJ na nota, enquanto ela ainda não foi enviada. Nunca lança exceção. */
    public static function informarDocumento(PDO $db, int $idRestaurante, int $idPedido, string $documento): bool
    {
        $doc = Documento::somenteDigitos($documento);
        if (!(strlen($doc) === 11 && Documento::cpfValido($doc)) && !(strlen($doc) === 14 && Documento::cnpjValido($doc))) { return false; }
        try {
            $u = $db->prepare("UPDATE fiscal_documentos SET cpf_cnpj = ? WHERE id_restaurante = ? AND id_pedido = ? AND status IN ('pendente', 'bloqueada', 'rejeitada') AND numero IS NULL");
            $u->execute([$doc, $idRestaurante, $idPedido]);
            return $u->rowCount() === 1;
        } catch (Throwable) { return false; }
    }

    /** Situação da nota de um pedido, para o caixa. null quando não existe (módulo desligado ou origem desligada). */
    public static function situacao(PDO $db, int $idRestaurante, int $idPedido): ?array
    {
        try {
            if (!Esquema::tem($db, 'fiscal_documentos')) { return null; }
            $q = $db->prepare('SELECT id, status, numero, serie, chave, motivo, ambiente, cpf_cnpj FROM fiscal_documentos WHERE id_restaurante = ? AND id_pedido = ?');
            $q->execute([$idRestaurante, $idPedido]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable) { return null; }
    }

    /** "Tentar de novo": volta para a fila notas rejeitadas, bloqueadas ou com falha. */
    public static function reenviar(PDO $db, int $idRestaurante, int $idPedido): bool
    {
        try {
            $u = $db->prepare("UPDATE fiscal_documentos SET status = 'pendente', proxima_tentativa_em = NOW(), motivo = NULL WHERE id_restaurante = ? AND id_pedido = ? AND status IN ('rejeitada', 'bloqueada', 'pendente')");
            $u->execute([$idRestaurante, $idPedido]);
            return $u->rowCount() === 1;
        } catch (Throwable) { return false; }
    }

    public const ROTULOS = [
        'pendente' => 'Nota na fila', 'enviando' => 'Enviando nota', 'incerta' => 'Confirmando nota', 'autorizada' => 'Nota autorizada',
        'rejeitada' => 'Nota rejeitada', 'bloqueada' => 'Nota com pendência', 'cancelada' => 'Nota cancelada',
    ];
}
