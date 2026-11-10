<?php

declare(strict_types=1);

namespace Cardapim\Services;

/** Cursor opaco da paginacao do catalogo: base64url de [nome, id]. */
final class CatalogoCursor
{
    /** @param array{0:string,1:int} $posicao */
    public static function codificar(array $posicao): string
    {
        $json = json_encode([$posicao[0], $posicao[1]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{0:string,1:int}|null null quando ausente ou adulterado */
    public static function decodificar(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 400 || preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) !== 1) {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || count($data) !== 2 || !is_string($data[0]) || !is_int($data[1]) || $data[1] < 1) {
            return null;
        }
        return [$data[0], $data[1]];
    }
}
