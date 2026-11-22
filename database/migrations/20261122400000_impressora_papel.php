<?php

declare(strict_types=1);

/**
 * Cupom montado como imagem pelo servidor: o Agent (0.9.0+) informa, de cada impressora, a largura do papel
 * (58 ou 80 mm), o espaco antes do corte (linhas) e se imprime a logo. margem_corte e criada por ultimo
 * (Esquema::tem 'impressora_papel').
 */
return static function (PDO $db): void {
    $db->exec('ALTER TABLE impressoras ADD COLUMN papel TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER apelido');
    $db->exec('ALTER TABLE impressoras ADD COLUMN logo TINYINT(1) NOT NULL DEFAULT 1 AFTER papel');
    $db->exec('ALTER TABLE impressoras ADD COLUMN margem_corte TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER logo');
};
