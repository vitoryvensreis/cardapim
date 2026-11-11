<?php

declare(strict_types=1);

// Teste sem banco: php tests/unit/dominio.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Core\DominioCanonico;

$novo = 'https://deliveryzim.com.br';
$antigos = 'cardapim.escotilhaprodutora.com, deliveryzim.com,www.deliveryzim.com , www.deliveryzim.com.br';
$falhas = 0;
$conferir = static function (string $nome, ?string $esperado, string $host, string $metodo, string $uri, string $app, string $lista) use (&$falhas): void {
    $r = DominioCanonico::redirecionamento($host, $metodo, $uri, $app, $lista);
    $obtido = $r === null ? null : (function () { return [$this->status, $this->headers['Location'] ?? '']; })->call($r);
    $obtidoTexto = $obtido === null ? 'nada' : $obtido[0] . ' ' . $obtido[1];
    $esperadoTexto = $esperado ?? 'nada';
    if ($obtidoTexto !== $esperadoTexto) {
        $falhas++;
        fwrite(STDERR, "FALHOU $nome: esperado '$esperadoTexto', obtido '$obtidoTexto'\n");
    }
};
$conferir('subdominio antigo com caminho e busca', '301 https://deliveryzim.com.br/restaurante-x?a=1', 'cardapim.escotilhaprodutora.com', 'GET', '/restaurante-x?a=1', $novo, $antigos);
$conferir('maiusculas e porta', '301 https://deliveryzim.com.br/login', 'Cardapim.Escotilhaprodutora.com:443', 'HEAD', '/login', $novo, $antigos);
$conferir('.com vai para .com.br', '301 https://deliveryzim.com.br/', 'deliveryzim.com', 'GET', '/', $novo, $antigos);
$conferir('www vai para sem www', '301 https://deliveryzim.com.br/catalogo', 'www.deliveryzim.com.br', 'GET', '/catalogo', $novo, $antigos);
$conferir('dominio novo nao redireciona', null, 'deliveryzim.com.br', 'GET', '/', $novo, $antigos);
$conferir('webhook do Asaas no antigo continua', null, 'cardapim.escotilhaprodutora.com', 'POST', '/webhooks/asaas/abc', $novo, $antigos);
$conferir('GET em webhook antigo continua', null, 'cardapim.escotilhaprodutora.com', 'GET', '/webhooks/asaas/abc', $novo, $antigos);
$conferir('formulario enviado no antigo continua', null, 'cardapim.escotilhaprodutora.com', 'POST', '/login', $novo, $antigos);
$conferir('lista vazia nao muda nada', null, 'cardapim.escotilhaprodutora.com', 'GET', '/', $novo, '');
$conferir('host fora da lista', null, 'outro.exemplo.com', 'GET', '/', $novo, $antigos);
$conferir('APP_URL sem https nao redireciona', null, 'deliveryzim.com', 'GET', '/', 'http://deliveryzim.com.br', $antigos);
$conferir('APP_URL igual ao host nao entra em laco', null, 'deliveryzim.com.br', 'GET', '/', $novo, 'deliveryzim.com.br');
$conferir('uri estranha volta para a raiz', '301 https://deliveryzim.com.br/', 'deliveryzim.com', 'GET', 'http://evil.test/', $novo, $antigos);
$conferir('barra dupla volta para a raiz', '301 https://deliveryzim.com.br/', 'deliveryzim.com', 'GET', '//evil.test/x', $novo, $antigos);
if ($falhas > 0) {
    exit(1);
}
echo "Dominio: enderecos antigos redirecionam para o APP_URL (webhooks e formularios preservados).\n";
