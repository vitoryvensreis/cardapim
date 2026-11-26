<?php

declare(strict_types=1);

/**
 * Opcoes do cupom impresso:
 * - impressoras.margem_lateral_mm: margem de cada lado do cupom em imagem, por impressora (NULL = padrao do Deliveryzim).
 *   O Agent nao grava esta coluna: so a tela de Impressoras muda.
 * - restaurante_impressao_config.mostrar_atendente: imprimir (1) ou nao (0) o nome de quem atendeu (garcom ou quem aceitou).
 * - restaurante_impressao_config.delivery_com_preco: o pedido do delivery aceito sai com o resumo de valores
 *   (total, entrega, desconto, pagamento e troco). Comeca desligado. Criada por ultimo (Esquema::tem 'cupom_opcoes').
 * Sem linha na config = padrao (nome impresso, delivery sem o resumo).
 */
return static function (PDO $db): void {
    $db->exec('ALTER TABLE impressoras ADD COLUMN margem_lateral_mm DECIMAL(3,1) NULL DEFAULT NULL AFTER margem_corte,
        ADD CONSTRAINT chk_impressoras_margem_lateral CHECK (margem_lateral_mm IS NULL OR margem_lateral_mm BETWEEN 0 AND 8)');
    $db->exec('ALTER TABLE restaurante_impressao_config ADD COLUMN mostrar_atendente TINYINT(1) NOT NULL DEFAULT 1 AFTER cancelamento');
    $db->exec('ALTER TABLE restaurante_impressao_config ADD COLUMN delivery_com_preco TINYINT(1) NOT NULL DEFAULT 0 AFTER mostrar_atendente');
};
