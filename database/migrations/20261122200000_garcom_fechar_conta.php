<?php

declare(strict_types=1);

/**
 * Perfil garcom: permissao para fechar a conta (receber pagamento, Pix e 10% de servico).
 * Padrao 1 (liberado): e o que o garcom ja fazia antes; o gerente desmarca se quiser que so o caixa feche.
 */
return static function (PDO $db): void {
    $db->exec('ALTER TABLE restaurante_garcom_permissoes ADD COLUMN fechar_conta TINYINT(1) NOT NULL DEFAULT 1 AFTER cancelar_comanda');
};
