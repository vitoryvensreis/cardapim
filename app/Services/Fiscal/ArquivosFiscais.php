<?php
declare(strict_types=1);

namespace Cardapim\Services\Fiscal;

use RuntimeException;

/** XMLs autorizados fora de public/, por restaurante e mês (guarda pelo prazo legal). */
final class ArquivosFiscais
{
    public static function raiz(): string
    {
        return dirname(__DIR__, 3) . '/storage/fiscal';
    }

    /** @return string caminho relativo a storage/fiscal */
    public static function salvarXml(int $idRestaurante, string $chave, string $xml): string
    {
        if (!preg_match('/\A\d{44}\z/', $chave)) { throw new RuntimeException('Chave inválida.'); }
        $rel = $idRestaurante . '/' . date('Y-m') . '/' . $chave . '-nfce.xml';
        $dest = self::raiz() . '/' . $rel;
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
            throw new RuntimeException('Pasta fiscal indisponível.');
        }
        if (@file_put_contents($dest, $xml, LOCK_EX) === false) { throw new RuntimeException('Não foi possível salvar o XML.'); }
        @chmod($dest, 0640);
        return $rel;
    }

    /** Lê um XML garantindo que o caminho pertence ao restaurante (sem ../). */
    public static function lerXml(int $idRestaurante, ?string $rel): ?string
    {
        if ($rel === null || !preg_match('~\A' . $idRestaurante . '/\d{4}-\d{2}/\d{44}-nfce\.xml\z~', $rel)) { return null; }
        $c = @file_get_contents(self::raiz() . '/' . $rel);
        return is_string($c) ? $c : null;
    }
}
