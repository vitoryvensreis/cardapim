<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // restaurantes.cnpj ja existe (CHAR(14) NULL, unico). NULL continua permitido para o
    // Restaurante Modelo e registros de demonstracao; o cadastro publico exige CNPJ valido
    // (validacao de digitos no backend). Aqui o banco garante o formato: 14 digitos.
    // O indice atende o catalogo publico (status ativo, ordenado por nome e id).
    $db->exec(<<<'SQL'
ALTER TABLE restaurantes
    ADD CONSTRAINT chk_restaurantes_cnpj CHECK (cnpj IS NULL OR cnpj REGEXP '^[0-9]{14}$'),
    ADD KEY idx_restaurantes_catalogo (status_rest, nome, id)
SQL);
};
