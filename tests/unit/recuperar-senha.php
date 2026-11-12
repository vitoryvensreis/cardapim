<?php

declare(strict_types=1);

// Link do "Esqueci minha senha" (assinatura, validade, uso unico) sem MySQL: php tests/unit/recuperar-senha.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Services\Auth\RecuperarSenha;
use Cardapim\Shared\Seguranca\Cripto;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};

Cripto::usarChave(str_repeat("\x09", 32));
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE `user` (id INTEGER PRIMARY KEY, usuario TEXT, email TEXT, nome TEXT, senha_hash TEXT, ativo INT, excluido_em TEXT)");
$db->exec("INSERT INTO `user` VALUES (7, 'maria', 'maria@example.test', 'Maria', 'hash-antigo', 1, NULL), (8, 'joao', 'joao@equipe.cardapim.invalid', 'Joao', 'h', 1, NULL)");

$agora = 1_800_000_000;
$s = new RecuperarSenha($db, $agora);
$conta = ['id' => 7, 'email' => 'maria@example.test', 'senha_hash' => 'hash-antigo'];
$token = $s->gerarToken($conta);
$ok(preg_match('/\A7\.\d{10}\.[A-Za-z0-9_-]{43}\z/', $token) === 1, 'formato do link');
$ok(($s->validar($token)['id'] ?? null) === 7, 'link certo vale');
$ok((new RecuperarSenha($db, $agora + RecuperarSenha::VALIDADE))->validar($token) !== null, 'vale ate 1 hora');
$ok((new RecuperarSenha($db, $agora + RecuperarSenha::VALIDADE + 1))->validar($token) === null, 'vence depois de 1 hora');
$ok($s->validar(substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A')) === null, 'assinatura adulterada');
$ok($s->validar(str_replace('7.', '8.', $token)) === null, 'trocar a conta invalida');
[$id, $exp, $sig] = explode('.', $token);
$ok($s->validar($id . '.' . ($exp + 999) . '.' . $sig) === null, 'esticar a validade invalida');
$ok($s->validar($id . '.' . ($agora + 99999) . '.' . $sig) === null, 'validade alem de 1 hora recusada');
$ok($s->validar('lixo') === null && $s->validar('') === null, 'texto qualquer');
$db->exec("UPDATE `user` SET senha_hash = 'hash-novo' WHERE id = 7");
$ok($s->validar($token) === null, 'senha trocada: link antigo para de valer (uso unico)');
$db->exec("UPDATE `user` SET senha_hash = 'hash-antigo', ativo = 0 WHERE id = 7");
$ok($s->validar($token) === null, 'conta bloqueada nao usa o link');
$db->exec("UPDATE `user` SET ativo = 1, email = 'outro@example.test' WHERE id = 7");
$ok($s->validar($token) === null, 'e-mail trocado invalida o link');
$ok($s->validar($s->gerarToken(['id' => 8, 'email' => 'joao@equipe.cardapim.invalid', 'senha_hash' => 'h'])) === null, 'equipe sem e-mail de verdade');
$ok(!RecuperarSenha::emailReal('x@equipe.cardapim.invalid') && RecuperarSenha::emailReal('a@b.com.br'), 'e-mail de verdade');
Cripto::usarChave(str_repeat("\x0a", 32));
$db->exec("UPDATE `user` SET email = 'maria@example.test' WHERE id = 7");
$ok($s->validar($token) === null, 'outra chave do servidor invalida o link');

if ($falhas > 0) {
    fwrite(STDERR, "FALHOU: $falhas verificacao(oes) da recuperacao de senha.\n");
    exit(1);
}
echo "OK: link de recuperar senha (assinatura, 1 hora, uso unico, conta e e-mail).\n";
