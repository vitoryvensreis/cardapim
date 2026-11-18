<?php

declare(strict_types=1);

use Cardapim\Services\Pedidos\SaudacaoWhatsApp;

require dirname(__DIR__, 2) . '/bootstrap/app.php';

function verifica(bool $ok, string $descricao): void {
    if (!$ok) throw new RuntimeException('Falha: ' . $descricao);
}
$agora = new DateTimeImmutable('2026-11-18 12:00:00', new DateTimeZone('America/Fortaleza'));
$base = ['event' => 'onmessage', 'from' => '5585999990000@c.us', 'fromMe' => false, 't' => $agora->getTimestamp()];
verifica(SaudacaoWhatsApp::destinatarioDireto($base) === '5585999990000', 'contato particular');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['isGroupMsg' => true])) === null, 'grupo');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['fromMe' => true])) === null, 'mensagem propria');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['from' => '1234567890123456@lid'])) === '1234567890123456@lid', 'identificador LID de conversa direta');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['from' => '12345@lid'])) === null, 'LID curto nao aceito');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['from' => '1234567890123456789012345@lid'])) === null, 'LID longo nao aceito');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['from' => '1234567890123456@lid', 'isGroupMsg' => true])) === null, 'LID em grupo rejeitado');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['from' => '1234567890123456@lid', 'fromMe' => true])) === null, 'LID proprio rejeitado');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['from' => '5585999990000@g.us'])) === null, 'grupo pelo dominio');
verifica(SaudacaoWhatsApp::destinatarioDireto(array_replace($base, ['event' => 'status-find'])) === null, 'evento diferente');
verifica(SaudacaoWhatsApp::instanteMensagem($base, $agora) !== null, 'horario novo');
verifica(SaudacaoWhatsApp::instanteMensagem(array_replace($base, ['t' => null, 'timestamp' => $agora->getTimestamp()]), $agora) !== null, 'timestamp alternativo');
verifica(SaudacaoWhatsApp::instanteMensagem(array_replace($base, ['t' => null, 'timestamp' => $agora->getTimestamp() - 301]), $agora) === null, 'timestamp alternativo antigo');
verifica(SaudacaoWhatsApp::instanteMensagem(array_replace($base, ['t' => $agora->getTimestamp() - 301]), $agora) === null, 'evento atrasado');
verifica(SaudacaoWhatsApp::instanteMensagem(array_replace($base, ['t' => $agora->getTimestamp() + 121]), $agora) === null, 'evento futuro');
verifica(str_contains(SaudacaoWhatsApp::texto('Olá {{restaurante}}', 'Loja CI', 'loja-ci'), 'loja-ci'), 'link obrigatorio');
verifica(str_contains(SaudacaoWhatsApp::texto(SaudacaoWhatsApp::PADRAO, 'Loja CI', 'loja-ci'), 'Loja CI'), 'nome dinamico');
echo "OK: filtro de mensagens e modelo de saudacao.\n";
