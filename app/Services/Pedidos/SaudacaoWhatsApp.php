<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\WppConnectApi;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Shared\Validation\Documento;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Uma saudacao por restaurante + contato + dia (fuso da aplicacao), apenas no expediente.
 * O webhook nunca bloqueia na chamada HTTP do WhatsApp e nunca utiliza a sessao da plataforma.
 */
final class SaudacaoWhatsApp
{
    public const PADRAO = "Olá! Bem-vindo(a) à *{{restaurante}}*.\n\nVeja nosso cardápio e faça seu pedido:\n{{cardapio}}\n\nEstamos à disposição!";

    public static function configuracao(PDO $db, int $restaurante): array
    {
        $q = $db->prepare('SELECT ativo, mensagem FROM restaurante_whatsapp_saudacao WHERE id_restaurante = ?');
        $q->execute([$restaurante]);
        $d = $q->fetch(PDO::FETCH_ASSOC);
        return ['ativo' => $d !== false && (int) $d['ativo'] === 1, 'mensagem' => $d !== false ? (string) $d['mensagem'] : self::PADRAO];
    }

    public static function salvar(PDO $db, int $restaurante, bool $ativo, string $mensagem): void
    {
        $mensagem = trim(str_replace("\r\n", "\n", $mensagem));
        if ($mensagem === '' || mb_strlen($mensagem) > 1500) {
            throw new \InvalidArgumentException('A mensagem deve ter de 1 a 1500 caracteres.');
        }
        $q = $db->prepare('INSERT INTO restaurante_whatsapp_saudacao (id_restaurante, ativo, mensagem, atualizado_em)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), mensagem = VALUES(mensagem), atualizado_em = VALUES(atualizado_em)');
        $q->execute([$restaurante, $ativo ? 1 : 0, $mensagem, date('Y-m-d H:i:s')]);
    }

    public static function texto(string $modelo, string $nome, string $slug): string
    {
        $link = BaseIntegracao::urlPublica('/' . $slug);
        $texto = str_replace(['{{restaurante}}', '{{cardapio}}'], [$nome, $link], $modelo);
        // A mensagem sempre inclui o cardapio, mesmo quando o gerente removeu o marcador.
        return str_contains($texto, $link) ? $texto : $texto . "\n\nCardápio: " . $link;
    }

    /**
     * Endereco de uma conversa individual: numero BR ou LID recebido do proprio
     * WPPConnect. LID nao e telefone; deve ser encaminhado SEM remover @lid.
     * A idempotencia diaria usa exatamente o identificador da conversa.
     */
    public static function destinatarioDireto(array $evento): ?string
    {
        if (($evento['event'] ?? null) !== 'onmessage' || ($evento['fromMe'] ?? null) !== false
            || !empty($evento['isGroupMsg']) || !empty($evento['isGroup']) || !empty($evento['isStatus'])) {
            return null;
        }
        $origem = $evento['from'] ?? null;
        if (!is_string($origem)) {
            return null;
        }
        // O identificador LID e independente do numero telefonico e ja identifica
        // o chat que escreveu para a loja. Aceitar somente o dominio exato.
        if (preg_match('/\A[0-9]{6,24}@lid\z/D', $origem) === 1) {
            return $origem;
        }
        if (preg_match('/\A(55[0-9]{10,11})@c\.us\z/D', $origem, $m) !== 1) {
            return null;
        }
        return Documento::normalizarTelefone(substr($m[1], 2)) === null ? null : $m[1];
    }

    /** Nao aceitar mensagens antigas reemitidas pelo WPPConnect ao reiniciar. */
    public static function instanteMensagem(array $evento, DateTimeImmutable $agora): ?DateTimeImmutable
    {
        // As versoes de WPPConnect podem fornecer t ou timestamp (segundos Unix).
        $segundos = $evento['t'] ?? $evento['timestamp'] ?? null;
        if (!is_int($segundos) && !(is_string($segundos) && ctype_digit($segundos))) {
            return null;
        }
        $segundos = (int) $segundos;
        if ($segundos < $agora->getTimestamp() - 300 || $segundos > $agora->getTimestamp() + 120) {
            return null;
        }
        return (new DateTimeImmutable('@' . $segundos))->setTimezone($agora->getTimezone());
    }

    /**
     * Diagnostico opcional e sem PII: nao grava telefone, sessao, texto ou token.
     * Ativar temporariamente com WPPCONNECT_SAUDACAO_DIAGNOSTICO=1 no .env compartilhado.
     */
    private static function ignorado(string $motivo, array $evento): bool
    {
        if (($evento['event'] ?? null) === 'onmessage'
            && Config::get('WPPCONNECT_SAUDACAO_DIAGNOSTICO') === '1') {
            error_log('[Cardapim] Saudacao WhatsApp ignorada: ' . $motivo);
        }
        return false;
    }

    /**
     * Enfileira a primeira mensagem elegivel do dia. Retorna true se uma saudacao foi criada.
     * A chave unica no MySQL torna webhooks repetidos e mensagens simultaneas idempotentes.
     */
    public static function receber(PDO $db, array $evento, ?DateTimeImmutable $agora = null): bool
    {
        $telefone = self::destinatarioDireto($evento);
        if ($telefone === null) {
            $origem = (string) ($evento['from'] ?? '');
            $tipo = str_ends_with($origem, '@lid') ? 'lid_formato_invalido'
                : ((($evento['fromMe'] ?? null) === true) ? 'mensagem_propria'
                    : 'remetente_ou_tipo_invalido');
            return self::ignorado($tipo, $evento);
        }
        $agora ??= new DateTimeImmutable('now');
        $instante = self::instanteMensagem($evento, $agora);
        if ($instante === null) {
            return self::ignorado('data_invalida_ausente_ou_antiga', $evento);
        }
        $sessao = (string) ($evento['session'] ?? '');
        if ($sessao === '') {
            return self::ignorado('sessao_ausente', $evento);
        }
        $id = (new IntegracaoRepository($db))->restaurantePorSessaoWhatsApp($sessao);
        if ($id === null) {
            return self::ignorado('sessao_sem_restaurante_ativo', $evento);
        }
        $config = self::configuracao($db, $id);
        if (!$config['ativo']) {
            return self::ignorado('automacao_desligada', $evento);
        }
        $perfil = new PerfilRepository($db);
        $loja = $perfil->buscar($id);
        if ($loja === null || ($loja['status_rest'] ?? '') !== 'ativo') {
            return self::ignorado('restaurante_inativo', $evento);
        }
        if (!HorarioFuncionamento::abertoEm((bool) $loja['sempre_aberto'], $perfil->horarios($id), $instante)) {
            return self::ignorado('fora_do_horario', $evento);
        }
        $texto = self::texto($config['mensagem'], (string) $loja['nome'], (string) $loja['slug']);
        $q = $db->prepare("INSERT IGNORE INTO whatsapp_saudacao_diaria
            (id_restaurante, telefone, dia_local, mensagem, status, tentativas, proxima_tentativa_em, criado_em)
            VALUES (?, ?, ?, ?, 'pendente', 0, ?, ?)");
        $q->execute([$id, $telefone, $instante->format('Y-m-d'), $texto, $agora->format('Y-m-d H:i:s'), $agora->format('Y-m-d H:i:s')]);
        if ($q->rowCount() !== 1) {
            return self::ignorado('ja_registrada_hoje', $evento);
        }
        self::agendarEnvio($id);
        return true;
    }

    private static array $agendados = [];

    /** Responde HTTP 200 antes de contatar o WPPConnect; evita a repeticao do webhook por timeout. */
    public static function agendarEnvio(int $id): void
    {
        if (isset(self::$agendados[$id])) return;
        $primeiro = self::$agendados === [];
        self::$agendados[$id] = true;
        if (!$primeiro) return;
        register_shutdown_function(static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try {
                $db = Database::connect();
                foreach (array_keys(self::$agendados) as $restaurante) {
                    self::enviarPendentes($db, $restaurante);
                }
            } catch (Throwable $e) {
                error_log('[Cardapim] Saudacao WhatsApp: ' . get_class($e));
            }
        });
    }

    /** No maximo uma tentativa por contato/dia. Sem repetir se o servidor falhou apos aceitar a mensagem. */
    public static function enviarPendentes(PDO $db, int $restaurante, ?WppConnectApi $api = null): int
    {
        $repo = new IntegracaoRepository($db);
        $integracao = $repo->buscar($restaurante, 'whatsapp');
        $sessao = $integracao['dados']['sessao'] ?? null;
        $token = $repo->credenciais($restaurante, 'whatsapp')['token'] ?? null;
        if (!WppConnectApi::disponivel() || $integracao['status'] !== 'conectado'
            || !is_string($sessao) || !is_string($token) || $token === '') {
            return 0;
        }
        // A saudacao nao pode sair depois que o expediente terminou.
        $perfil = new PerfilRepository($db);
        $loja = $perfil->buscar($restaurante);
        if ($loja === null || ($loja['status_rest'] ?? '') !== 'ativo'
            || !HorarioFuncionamento::abertoEm((bool) $loja['sempre_aberto'], $perfil->horarios($restaurante), new DateTimeImmutable('now'))) {
            return 0;
        }
        $api ??= new WppConnectApi();
        $q = $db->prepare("SELECT id, telefone, mensagem FROM whatsapp_saudacao_diaria
            WHERE id_restaurante = ? AND status = 'pendente' AND dia_local = ? AND proxima_tentativa_em <= ?
            ORDER BY id LIMIT 5");
        $q->execute([$restaurante, date('Y-m-d'), date('Y-m-d H:i:s')]);
        $total = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $claim = $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = 'enviando', tentativas = tentativas + 1
                WHERE id = ? AND id_restaurante = ? AND status = 'pendente'");
            $claim->execute([(int) $a['id'], $restaurante]);
            if ($claim->rowCount() !== 1) continue;
            try {
                $api->enviarTexto($sessao, $token, (string) $a['telefone'], (string) $a['mensagem']);
                $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = 'enviado', enviado_em = ?, ultimo_erro = NULL
                    WHERE id = ? AND id_restaurante = ? AND status = 'enviando'")
                    ->execute([date('Y-m-d H:i:s'), (int) $a['id'], $restaurante]);
                $total++;
            } catch (Throwable $e) {
                $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = 'falhou', ultimo_erro = ?
                    WHERE id = ? AND id_restaurante = ? AND status = 'enviando'")
                    ->execute([mb_substr($e->getMessage(), 0, 255), (int) $a['id'], $restaurante]);
            }
        }
        return $total;
    }

    /** Retencao limitada a 35 dias. Executar no cron de manutencao quando disponivel. */
    public static function limparAntigos(PDO $db): int
    {
        return $db->exec("DELETE FROM whatsapp_saudacao_diaria WHERE dia_local < DATE_SUB(CURDATE(), INTERVAL 35 DAY)") ?: 0;
    }
}
