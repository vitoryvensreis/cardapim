<?php

declare(strict_types=1);

/**
 * Atalhos globais da marca (nome vem de configuracoes.nome_sistema).
 * marca(): texto puro, para strings que ja sao escapadas depois (titulos, mensagens, WhatsApp, e-mail).
 * marca_html(): ja escapado, para HTML direto nas views.
 */
function marca(): string
{
    return \Cardapim\Services\Configuracoes::nome();
}

function marca_html(): string
{
    return htmlspecialchars(marca(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
