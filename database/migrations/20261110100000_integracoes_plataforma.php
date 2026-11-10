<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // Integracoes da PLATAFORMA (administracao do Cardapim): Asaas para receber as assinaturas e o
    // WhatsApp proprio da plataforma (codigos de verificacao dos clientes). Usam as mesmas tabelas das
    // integracoes dos restaurantes com id_restaurante NULL.
    // "escopo" = id_restaurante ou 0 (plataforma): mantem UMA integracao por provedor em cada escopo,
    // inclusive para a plataforma (UNIQUE com NULL nao impediria duplicatas no MySQL).
    // A FK e recriada porque a coluna muda para NULL (sem acao em cascata: exigencia da coluna gerada).
    $db->exec('ALTER TABLE integracoes DROP FOREIGN KEY fk_integracoes_restaurante');
    $db->exec(<<<'SQL'
ALTER TABLE integracoes
    MODIFY id_restaurante BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN escopo BIGINT UNSIGNED AS (IFNULL(id_restaurante, 0)) STORED NOT NULL AFTER id_restaurante,
    ADD KEY idx_integracoes_restaurante (id_restaurante),
    DROP INDEX uq_integracoes_restaurante_provedor,
    ADD UNIQUE KEY uq_integracoes_escopo_provedor (escopo, provedor)
SQL);
    $db->exec('ALTER TABLE integracoes ADD CONSTRAINT fk_integracoes_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id)');

    $db->exec('ALTER TABLE integracao_eventos DROP FOREIGN KEY fk_integracao_eventos_restaurante');
    $db->exec(<<<'SQL'
ALTER TABLE integracao_eventos
    MODIFY id_restaurante BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN escopo BIGINT UNSIGNED AS (IFNULL(id_restaurante, 0)) STORED NOT NULL AFTER id_restaurante,
    ADD KEY idx_integracao_eventos_escopo (escopo, provedor, id)
SQL);
    $db->exec('ALTER TABLE integracao_eventos ADD CONSTRAINT fk_integracao_eventos_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id)');
};
