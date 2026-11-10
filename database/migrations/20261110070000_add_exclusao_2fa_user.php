<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // excluido_em: funcionario removido pela gerencia que ja tem historico (logs). A conta e
    // anonimizada e desativada, mantendo o id para a auditoria.
    // dois_fatores: verificacao em duas etapas (aplicativo autenticador ou e-mail).
    // dois_fatores_segredo: segredo TOTP criptografado (AES-256-GCM, chave fora do banco).
    $db->exec(<<<'SQL'
ALTER TABLE `user`
    ADD COLUMN excluido_em DATETIME NULL DEFAULT NULL AFTER ativo,
    ADD COLUMN dois_fatores ENUM('desativado', 'app', 'email') NOT NULL DEFAULT 'desativado' AFTER email_verificado_em,
    ADD COLUMN dois_fatores_segredo VARCHAR(255) NULL DEFAULT NULL AFTER dois_fatores,
    ADD COLUMN dois_fatores_ativado_em DATETIME NULL DEFAULT NULL AFTER dois_fatores_segredo,
    ADD KEY idx_user_restaurante_excluido (id_restaurante, excluido_em)
SQL);
};
