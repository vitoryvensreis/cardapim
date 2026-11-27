<?php

declare(strict_types=1);

/**
 * Contato do cliente na via de producao do delivery aceito (impressao automatica):
 * - restaurante_impressao_config.delivery_com_endereco: sai o endereco e a referencia (so pedidos de entrega).
 * - restaurante_impressao_config.delivery_com_telefone: sai o telefone do cliente.
 * Ambas comecam desligadas (nenhum cupom muda sem o restaurante ligar). delivery_com_telefone e criada por ultimo
 * (Esquema::tem 'cupom_contato').
 */
return static function (PDO $db): void {
    $db->exec('ALTER TABLE restaurante_impressao_config
        ADD COLUMN delivery_com_endereco TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_com_preco,
        ADD COLUMN delivery_com_telefone TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_com_endereco');
};
