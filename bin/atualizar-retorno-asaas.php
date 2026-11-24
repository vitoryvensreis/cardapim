<?php

declare(strict_types=1);

// Atualiza apenas o callback de uma recorrência existente e suas cobranças elegíveis.
// Por padrão somente consulta. --apply autoriza alterações na conta Asaas indicada.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap/app.php';

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\AsaasApi;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Services\Plataforma\AssinaturaCobranca;

$opcoes = getopt('', ['restaurante:', 'ambiente:', 'apply']);
$id = filter_var($opcoes['restaurante'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ambiente = $opcoes['ambiente'] ?? null;
$aplicar = array_key_exists('apply', $opcoes);

if ($id === false || !in_array($ambiente, ['sandbox', 'producao'], true)) {
    fwrite(STDERR, "Uso: php bin/atualizar-retorno-asaas.php --restaurante=ID --ambiente=sandbox|producao [--apply]\n");
    exit(1);
}

try {
    $db = Database::connect();
    $lock = $db->prepare('SELECT GET_LOCK(?, 3)');
    $nomeLock = 'cardapim:assinatura:' . $id;
    $lock->execute([$nomeLock]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Operação de assinatura em andamento.');
    }

    try {
        $repo = new AssinaturaRepository($db);
        $a = $repo->vigente($id);
        $conta = (new AssinaturaCobranca($db))->conta();
        if ($a === null || !in_array($a['status'], ['ativa', 'aguardando'], true)
            || $a['asaas_assinatura_id'] === null || $a['asaas_cliente_id'] === null
            || $conta === null || $conta['ambiente'] !== $ambiente || $a['ambiente'] !== $ambiente) {
            throw new RuntimeException('Não há recorrência vigente vinculada à conta e ao ambiente informados.');
        }

        $q = $db->prepare('SELECT slug FROM restaurantes WHERE id = ?');
        $q->execute([$id]);
        $callback = AssinaturaCobranca::callbackRetorno(Config::get('APP_URL'), (string) $q->fetchColumn())['callback'] ?? null;
        if ($callback === null) {
            throw new RuntimeException('APP_URL HTTPS ou slug inválido.');
        }

        $api = new AsaasApi();
        $remote = $api->consultarAssinatura($conta['chave'], $ambiente, $a['asaas_assinatura_id']);
        if (($remote['customer'] ?? null) !== $a['asaas_cliente_id'] || ($remote['status'] ?? null) !== 'ACTIVE'
            || !empty($remote['deleted'])) {
            throw new RuntimeException('Recorrência remota não corresponde ao cliente ativo esperado.');
        }

        $faturas = $api->faturasDaAssinatura($conta['chave'], $ambiente, $a['asaas_assinatura_id']);
        $elegiveis = array_values(array_filter($faturas, static fn (array $f): bool =>
            $f['assinatura'] === $a['asaas_assinatura_id'] && $f['cliente'] === $a['asaas_cliente_id']
            && in_array($f['status'], ['PENDING', 'OVERDUE'], true)));

        echo ($aplicar ? 'APLICAÇÃO' : 'SIMULAÇÃO') . ": restaurante {$id}; {$ambiente}; "
            . count($elegiveis) . " cobrança(s) elegível(is).\n";
        echo "Retorno: " . $callback['successUrl'] . "\n";
        if ($aplicar) {
            $api->atualizarAssinatura($conta['chave'], $ambiente, $a['asaas_assinatura_id'], [
                'callback' => $callback, 'updatePendingPayments' => false,
            ]);
            $falhas = 0;
            foreach ($elegiveis as $fatura) {
                try {
                    // Reconfere estado e vínculo antes de atualizar cada fatura, sem alterar preço ou data.
                    $atual = $api->consultarFaturaAssinatura($conta['chave'], $ambiente, $fatura['id']);
                    if ($atual['assinatura'] !== $a['asaas_assinatura_id'] || $atual['cliente'] !== $a['asaas_cliente_id']
                        || !in_array($atual['status'], ['PENDING', 'OVERDUE'], true)) {
                        continue;
                    }
                    $api->atualizarCallbackFatura($conta['chave'], $ambiente, $fatura['id'], $callback);
                } catch (Throwable $erro) {
                    $falhas++;
                    fwrite(STDERR, 'Falha na atualização de uma fatura: ' . get_class($erro) . "\n");
                }
            }
            if ($falhas > 0) {
                throw new RuntimeException("Atualização parcial: {$falhas} falha(s). Repita após conferir o Asaas.");
            }
            echo "Callback da recorrência e das faturas elegíveis atualizado.\n";
        } else {
            echo "Nenhuma alteração feita. Use --apply após homologação.\n";
        }
    } finally {
        $lock = $db->prepare('SELECT RELEASE_LOCK(?)');
        $lock->execute([$nomeLock]);
    }
} catch (Throwable $erro) {
    fwrite(STDERR, 'Atualização de retorno: ' . $erro->getMessage() . "\n");
    exit(1);
}
