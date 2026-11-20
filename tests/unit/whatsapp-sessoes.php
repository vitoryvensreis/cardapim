<?php

declare(strict_types=1);

// Teste sem servidor real da limpeza de sessoes abandonadas do WhatsApp: php tests/unit/whatsapp-sessoes.php
require dirname(__DIR__, 2) . '/bootstrap/app.php';

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Services\WhatsApp\SessoesAbandonadas as S;

$falhas = 0;
$ok = static function (bool $cond, string $nome) use (&$falhas): void {
    if (!$cond) {
        $falhas++;
        fwrite(STDERR, "FALHOU: $nome\n");
    }
};

// Nomes
$ok(S::doCardapim('cardapim_r2_50f28385') && S::doCardapim('cardapim_plataforma_f90007fc') && S::doCardapim('cardapim_r123_abcdef01'), 'nomes do Cardapim');
$ok(!S::doCardapim('taskesc') && !S::doCardapim('cardapim_r0_50f28385') && !S::doCardapim('cardapim_r2_50F28385') && !S::doCardapim('cardapim_r2_50f2838') && !S::doCardapim('outro_cardapim_r2_50f28385'), 'outros nomes ficam de fora');

// Decisao com carencia
$agora = 1_800_000_000;
$servidor = ['cardapim_plataforma_f90007fc', 'cardapim_r1_f4d5096c', 'cardapim_r2_40031dfd', 'cardapim_r2_50f28385', 'cardapim_r3_aaaaaaaa', 'leaguecut'];
$emUso = ['cardapim_plataforma_f90007fc', 'cardapim_r1_f4d5096c', 'cardapim_r2_40031dfd'];
$d = S::decidir($servidor, $emUso, [], $agora);
$ok($d['fechar'] === [] && $d['aguardando'] === ['cardapim_r2_50f28385', 'cardapim_r3_aaaaaaaa'], 'primeira vez: so marca, nao fecha');
$ok($d['vistas'] === ['cardapim_r2_50f28385' => $agora, 'cardapim_r3_aaaaaaaa' => $agora], 'guarda quando viu');
$d2 = S::decidir($servidor, $emUso, $d['vistas'], $agora + 599);
$ok($d2['fechar'] === [], 'antes de 10 minutos nao fecha');
$d3 = S::decidir($servidor, $emUso, $d['vistas'], $agora + 600);
$ok($d3['fechar'] === ['cardapim_r2_50f28385', 'cardapim_r3_aaaaaaaa'], 'depois de 10 minutos fecha');
$d4 = S::decidir($servidor, [...$emUso, 'cardapim_r3_aaaaaaaa'], $d['vistas'], $agora + 900);
$ok($d4['fechar'] === ['cardapim_r2_50f28385'] && !isset($d4['vistas']['cardapim_r3_aaaaaaaa']), 'sessao que voltou a ser usada nao e fechada');
$ok(!in_array('leaguecut', [...$d3['fechar'], ...$d3['aguardando']], true), 'sessao de outro sistema nunca e tocada');
$ok(S::decidir([], $emUso, $d['vistas'], $agora)['vistas'] === [], 'sessao que sumiu do servidor sai da lista');

// Rodada completa com banco SQLite e servidor falso
$db = class_exists(\Pdo\Sqlite::class) ? \Pdo\Sqlite::connect('sqlite::memory:') : new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$funcao = static fn ($v) => $v;
method_exists($db, 'createFunction') ? $db->createFunction('JSON_UNQUOTE', $funcao, 1) : $db->sqliteCreateFunction('JSON_UNQUOTE', $funcao, 1);
$db->exec("CREATE TABLE integracoes (id INTEGER PRIMARY KEY, id_restaurante INT, provedor TEXT, status TEXT, dados TEXT)");
$db->exec("INSERT INTO integracoes (id_restaurante, provedor, status, dados) VALUES
    (NULL, 'whatsapp', 'conectado', '{\"sessao\":\"cardapim_plataforma_f90007fc\"}'),
    (1, 'whatsapp', 'conectado', '{\"sessao\":\"cardapim_r1_f4d5096c\",\"numero\":\"5585\"}'),
    (2, 'whatsapp', 'pendente', '{\"sessao\":\"cardapim_r2_40031dfd\"}'),
    (3, 'whatsapp', 'desconectado', NULL),
    (2, 'asaas', 'conectado', '{\"sessao\":\"cardapim_r2_50f28385\"}')");

$api = new class extends WppConnectApi {
    /** @var list<string> */
    public array $servidor = [];
    /** @var list<string> */
    public array $saiu = [];
    public bool $travada = false;
    public function sessoesNoServidor(): array
    {
        return $this->servidor;
    }
    public function gerarToken(string $sessao): string
    {
        return 'tok-' . $sessao;
    }
    public function sair(string $sessao, string $token): bool
    {
        if ($token !== 'tok-' . $sessao) {
            throw new IntegracaoErro('token');
        }
        if ($this->travada) {
            return false;
        }
        $this->saiu[] = $sessao;
        $this->servidor = array_values(array_diff($this->servidor, [$sessao]));
        return true;
    }
};
$api->servidor = $servidor;
$estado = tempnam(sys_get_temp_dir(), 'wpp');
@unlink($estado);
$svc = new S($db, $api, $estado);
$ok($svc->emUso() === $emUso, 'em uso: so whatsapp nao desconectado, com sessao');

$r = $svc->rodar(false, false, $agora);
$ok($api->saiu === [] && $r['aguardando'] === ['cardapim_r2_50f28385', 'cardapim_r3_aaaaaaaa'] && !is_file($estado), 'consulta nao fecha nem grava nada');
$r = $svc->rodar(true, false, $agora);
$ok($api->saiu === [] && $r['fechadas'] === [] && count($r['aguardando']) === 2 && is_file($estado), 'primeira limpeza: aguarda a carencia');
$api->travada = true;
$r = $svc->rodar(true, false, $agora + 900);
$ok($r['fechadas'] === [] && $r['falharam'] === ['cardapim_r2_50f28385', 'cardapim_r3_aaaaaaaa'], 'servidor travado: falha e tenta depois');
$api->travada = false;
$r = $svc->rodar(true, false, $agora + 1800);
$ok($r['fechadas'] === ['cardapim_r2_50f28385', 'cardapim_r3_aaaaaaaa'] && $api->saiu === $r['fechadas'], 'limpeza fecha as abandonadas');
$ok(in_array('cardapim_r2_40031dfd', $api->servidor, true) && in_array('leaguecut', $api->servidor, true) && in_array('cardapim_plataforma_f90007fc', $api->servidor, true), 'em uso e de outros sistemas continuam');
$ok(json_decode((string) file_get_contents($estado), true) === [], 'estado limpo depois de fechar');

$api->servidor[] = 'cardapim_r9_bbbbbbbb';
$r = $svc->rodar(true, true, $agora + 1900);
$ok($r['fechadas'] === ['cardapim_r9_bbbbbbbb'], '--sem-espera fecha na hora');
@unlink($estado);

if ($falhas > 0) {
    fwrite(STDERR, "$falhas teste(s) falharam.\n");
    exit(1);
}
echo "OK limpeza de sessoes do WhatsApp (unit)\n";
