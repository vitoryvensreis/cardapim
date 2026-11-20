<?php

declare(strict_types=1);

// Sessoes dos servidores de WhatsApp (WPPConnect e Evolution): lista e fecha as que nenhum restaurante usa mais.
// Sessao abandonada abre um navegador a toa e gasta a memoria que as lojas conectadas precisam.
//
// Uso (usuario do site, na pasta current/):
//   php8.4 bin/whatsapp-sessoes.php                 -> so mostra (nao fecha nada)
//   php8.4 bin/whatsapp-sessoes.php --limpar        -> fecha as abandonadas ha 10+ minutos (para o cron)
//   php8.4 bin/whatsapp-sessoes.php --limpar --sem-espera -> fecha ja todas as abandonadas (uso manual)
// Cron sugerido (a cada 15 minutos):
//   */15 * * * * php8.4 /home/<usuario>/apps/cardapim/current/bin/whatsapp-sessoes.php --limpar >> /home/<usuario>/apps/cardapim/shared/storage/logs/whatsapp-sessoes.log 2>&1
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use Cardapim\Services\WhatsApp\SessoesAbandonadas;

$servidores = WhatsAppProvedores::configurados();
if ($servidores === []) {
    echo "WhatsApp nao configurado (WPPCONNECT_* ou EVOLUTION_* no .env). Nada a fazer.\n";
    exit(0);
}

$limpar = in_array('--limpar', $argv, true);
$semEspera = in_array('--sem-espera', $argv, true);
$pasta = dirname(__DIR__) . '/storage/cache';
if (!is_dir($pasta)) {
    @mkdir($pasta, 0775, true);
}
$trava = fopen($pasta . '/whatsapp-sessoes.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    exit(0); // outra rodada em andamento
}

$linha = static fn (string $rotulo, array $lista): string => sprintf("  %-34s %d%s\n", $rotulo, count($lista), $lista === [] ? '' : ': ' . implode(', ', $lista));
$saida = 0;
$db = Database::connect();
foreach ($servidores as $api) {
    // Um arquivo de "vistas" por servidor (o do WPPConnect mantem o nome antigo).
    $estado = $pasta . ($api->id() === WhatsAppProvedores::WPPCONNECT ? '/whatsapp-sessoes.json' : '/whatsapp-sessoes-' . $api->id() . '.json');
    try {
        $r = (new SessoesAbandonadas($db, $api, $estado))->rodar($limpar, $semEspera);
    } catch (IntegracaoErro $erro) {
        fwrite(STDERR, date('Y-m-d H:i:s') . ' ERRO (' . $api->id() . '): ' . $erro->getMessage() . "\n");
        $saida = max($saida, 2);
        continue;
    }
    echo date('Y-m-d H:i:s') . ' [' . $api->id() . ']' . ($limpar ? ' limpeza' : ' consulta (nada foi fechado)') . "\n";
    echo $linha('Sessoes no servidor do WhatsApp', $r['servidor']);
    echo $linha('Em uso (gravadas no banco)', $r['em_uso']);
    if ($limpar) {
        echo $linha('Fechadas agora', $r['fechadas']);
        echo $linha('Nao fecharam (tenta de novo)', $r['falharam']);
        echo $linha('Abandonadas, aguardando 10 min', $r['aguardando']);
    } else {
        echo $linha('Abandonadas (use --limpar)', $r['aguardando']);
    }
    if ($r['falharam'] !== []) {
        $saida = max($saida, 3);
    }
}
exit($saida);
