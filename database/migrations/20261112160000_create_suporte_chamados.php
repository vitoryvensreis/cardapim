<?php

declare(strict_types=1);

return static function (PDO $db): void {
    // SUPORTE: chamados abertos pelo gerente de cada restaurante e respondidos pela administracao da plataforma.
    // status: aberto (esperando o suporte), em_atendimento, resolvido (reabre se o restaurante responder).
    // ultima_de: quem escreveu por ultimo (mostra "Respondido" / "Aguardando resposta").
    // visto_*_em: ultima vez que cada lado abriu o chamado (contador de novidades no menu).
    $db->exec(<<<'SQL'
CREATE TABLE suporte_chamados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    assunto VARCHAR(120) NOT NULL,
    categoria ENUM('duvida', 'problema', 'pedidos', 'financeiro', 'integracoes', 'sugestao', 'outro') NOT NULL,
    status ENUM('aberto', 'em_atendimento', 'resolvido') NOT NULL DEFAULT 'aberto',
    ultima_de ENUM('restaurante', 'suporte') NOT NULL DEFAULT 'restaurante',
    ultima_mensagem_em DATETIME NOT NULL,
    visto_restaurante_em DATETIME NULL DEFAULT NULL,
    visto_suporte_em DATETIME NULL DEFAULT NULL,
    resolvido_em DATETIME NULL DEFAULT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suporte_chamados_restaurante (id, id_restaurante),
    KEY idx_suporte_chamados_fila (status, ultima_mensagem_em),
    KEY idx_suporte_chamados_restaurante (id_restaurante, ultima_mensagem_em),
    CONSTRAINT fk_suporte_chamados_restaurante FOREIGN KEY (id_restaurante) REFERENCES restaurantes (id),
    CONSTRAINT fk_suporte_chamados_user FOREIGN KEY (id_user) REFERENCES `user` (id),
    CONSTRAINT chk_suporte_chamados_assunto CHECK (CHAR_LENGTH(TRIM(assunto)) >= 3),
    CONSTRAINT chk_suporte_chamados_resolvido CHECK ((status = 'resolvido') = (resolvido_em IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Mensagens do chamado (a primeira e a descricao). origem diz o lado; id_user quem escreveu.
    $db->exec(<<<'SQL'
CREATE TABLE suporte_mensagens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_chamado BIGINT UNSIGNED NOT NULL,
    id_restaurante BIGINT UNSIGNED NOT NULL,
    id_user BIGINT UNSIGNED NULL DEFAULT NULL,
    origem ENUM('restaurante', 'suporte') NOT NULL,
    texto TEXT NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_suporte_mensagens_chamado (id_chamado, id),
    CONSTRAINT fk_suporte_mensagens_chamado FOREIGN KEY (id_chamado, id_restaurante) REFERENCES suporte_chamados (id, id_restaurante),
    CONSTRAINT fk_suporte_mensagens_user FOREIGN KEY (id_user) REFERENCES `user` (id),
    CONSTRAINT chk_suporte_mensagens_texto CHECK (CHAR_LENGTH(TRIM(texto)) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
};
