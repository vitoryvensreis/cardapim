<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Core\Config;
use Cardapim\Core\Database;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\ProvedorWhatsApp;
use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Services\Gerencia\Integracoes\BaseIntegracao;
use Cardapim\Services\WhatsApp\DiagnosticoSaudacao;
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
    /** Tentativas de envio por contato/dia e espera (segundos) antes da 2a e da 3a. */
    public const TENTATIVAS = 3;
    private const ESPERA = [1 => 60, 2 => 180];
    /** Boas-vindas que nao saiu em 10 minutos e descartada: chegar muito depois atrapalha mais do que ajuda. */
    public const VALIDADE_MINUTOS = 10;
    /** Envio "em andamento" ha mais que isso foi interrompido (processo caiu no meio). */
    private const LEASE_MINUTOS = 5;

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
     * Endereco de uma conversa individual: numero BR ou LID recebido do servidor de
     * WhatsApp (eventos do WAHA chegam ja convertidos por EventoWaha). LID nao e telefone; deve ser encaminhado SEM remover @lid.
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

    /** Nao aceitar mensagens antigas reemitidas pelo servidor ao reiniciar. */
    public static function instanteMensagem(array $evento, DateTimeImmutable $agora): ?DateTimeImmutable
    {
        // t ou timestamp em segundos Unix (EventoWaha entrega em t).
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
     * Ativar temporariamente com WHATSAPP_SAUDACAO_DIAGNOSTICO=1 no .env compartilhado.
     */
    private static function ignorado(string $motivo, array $evento): bool
    {
        if (($evento['event'] ?? null) === 'onmessage' && is_string($evento['session'] ?? null)) {
            DiagnosticoSaudacao::registrar($evento['session'], $motivo, is_string($evento['from'] ?? null) ? $evento['from'] : null);
        }
        if (($evento['event'] ?? null) === 'onmessage'
            && Config::get('WHATSAPP_SAUDACAO_DIAGNOSTICO') === '1') {
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
            self::agendarEnvio($id); // se a de hoje ainda esta na fila (aguardando nova tentativa), aproveita para tentar
            return self::ignorado('ja_registrada_hoje', $evento);
        }
        DiagnosticoSaudacao::registrar($sessao, 'enfileirada', $telefone);
        self::agendarEnvio($id);
        return true;
    }

    private static array $agendados = [];

    /** Responde HTTP 200 antes de contatar o servidor do WhatsApp; evita a repeticao do webhook por timeout. */
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

    /**
     * Envia a fila da loja. Ate 3 tentativas por contato/dia (espera de 1 e 3 minutos), dentro de 10 minutos da
     * mensagem do cliente; depois disso desiste (boas-vindas atrasada nao ajuda). Numero sem WhatsApp nao repete.
     * Chamado logo apos o webhook e, a cada minuto, por bin/whatsapp-vigia.php (novas tentativas).
     */
    public static function enviarPendentes(PDO $db, int $restaurante, ?ProvedorWhatsApp $api = null): int
    {
        self::expirar($db, $restaurante);
        $repo = new IntegracaoRepository($db);
        $integracao = $repo->buscar($restaurante, 'whatsapp');
        $sessao = $integracao['dados']['sessao'] ?? null;
        $token = $repo->credenciais($restaurante, 'whatsapp')['token'] ?? null;
        $api ??= WhatsAppProvedores::daIntegracao($integracao['dados']);
        // "erro" = o painel viu o WhatsApp cair por um instante; se a mensagem chegou, a conexao esta de pe.
        if (!$api->configurado() || !in_array($integracao['status'], ['conectado', 'erro'], true)
            || !is_string($sessao) || !is_string($token) || $token === '' || WhatsAppProvedores::legado($integracao['dados'])) {
            if (is_string($sessao)) {
                DiagnosticoSaudacao::registrar($sessao, 'envio_sem_conexao');
            }
            return 0;
        }
        // A saudacao nao pode sair depois que o expediente terminou.
        $perfil = new PerfilRepository($db);
        $loja = $perfil->buscar($restaurante);
        if ($loja === null || ($loja['status_rest'] ?? '') !== 'ativo'
            || !HorarioFuncionamento::abertoEm((bool) $loja['sempre_aberto'], $perfil->horarios($restaurante), new DateTimeImmutable('now'))) {
            DiagnosticoSaudacao::registrar($sessao, 'envio_fora_do_horario');
            return 0;
        }
        $agora = time();
        $q = $db->prepare("SELECT id, telefone, mensagem, tentativas FROM whatsapp_saudacao_diaria
            WHERE id_restaurante = ? AND status = 'pendente' AND proxima_tentativa_em <= ? AND criado_em >= ?
            ORDER BY id LIMIT 5");
        $q->execute([$restaurante, date('Y-m-d H:i:s', $agora), date('Y-m-d H:i:s', $agora - self::VALIDADE_MINUTOS * 60)]);
        $total = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
            // Reserva o envio (outro processo nao pega o mesmo); a reserva vence se este processo cair no meio.
            $claim = $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = 'enviando', tentativas = tentativas + 1, proxima_tentativa_em = ?
                WHERE id = ? AND id_restaurante = ? AND status = 'pendente'");
            $claim->execute([date('Y-m-d H:i:s', time() + self::LEASE_MINUTOS * 60), (int) $a['id'], $restaurante]);
            if ($claim->rowCount() !== 1) continue;
            $tentativa = (int) $a['tentativas'] + 1;
            try {
                $api->enviarTexto($sessao, $token, (string) $a['telefone'], (string) $a['mensagem']);
                $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = 'enviado', enviado_em = ?, ultimo_erro = NULL
                    WHERE id = ? AND id_restaurante = ? AND status = 'enviando'")
                    ->execute([date('Y-m-d H:i:s'), (int) $a['id'], $restaurante]);
                DiagnosticoSaudacao::registrar($sessao, 'enviada', (string) $a['telefone']);
                $total++;
            } catch (Throwable $e) {
                $definitivo = ($e instanceof IntegracaoErro && $e->destinoInvalido()) || $tentativa >= self::TENTATIVAS;
                $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = ?, ultimo_erro = ?, proxima_tentativa_em = ?
                    WHERE id = ? AND id_restaurante = ? AND status = 'enviando'")
                    ->execute([
                        $definitivo ? 'falhou' : 'pendente',
                        mb_substr($e->getMessage(), 0, 255),
                        date('Y-m-d H:i:s', time() + (self::ESPERA[$tentativa] ?? 0)),
                        (int) $a['id'],
                        $restaurante,
                    ]);
                DiagnosticoSaudacao::registrar($sessao, $definitivo ? 'falhou_envio' : 'falhou_envio_tenta_de_novo', (string) $a['telefone']);
            }
        }
        return $total;
    }

    /**
     * Encerra o que nao vai mais sair: na fila ha mais de 10 minutos, ou envio interrompido (reserva vencida; nao
     * repete porque o WhatsApp pode ter entregado antes da queda).
     */
    public static function expirar(PDO $db, ?int $restaurante = null): int
    {
        $filtro = $restaurante === null ? '' : ' AND id_restaurante = ' . (int) $restaurante;
        $agora = time();
        $velhas = $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = 'falhou', ultimo_erro = ?
            WHERE status = 'pendente' AND criado_em < ?" . $filtro);
        $velhas->execute(['Não saiu a tempo (mais de ' . self::VALIDADE_MINUTOS . ' minutos). O WhatsApp da loja estava fora do ar?',
            date('Y-m-d H:i:s', $agora - self::VALIDADE_MINUTOS * 60)]);
        $presas = $db->prepare("UPDATE whatsapp_saudacao_diaria SET status = 'falhou', ultimo_erro = ?
            WHERE status = 'enviando' AND proxima_tentativa_em < ?" . $filtro);
        $presas->execute(['Envio interrompido no meio. Não repetimos para o cliente não receber duas vezes.', date('Y-m-d H:i:s', $agora)]);
        return $velhas->rowCount() + $presas->rowCount();
    }

    /** @return list<int> lojas com boas-vindas aguardando nova tentativa (para o vigia) */
    public static function lojasComFila(PDO $db): array
    {
        $q = $db->prepare("SELECT DISTINCT id_restaurante FROM whatsapp_saudacao_diaria
            WHERE status = 'pendente' AND proxima_tentativa_em <= ? AND criado_em >= ?");
        $q->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - self::VALIDADE_MINUTOS * 60)]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Retencao limitada a 35 dias. Executar no cron de manutencao quando disponivel. */
    public static function limparAntigos(PDO $db): int
    {
        return $db->exec("DELETE FROM whatsapp_saudacao_diaria WHERE dia_local < DATE_SUB(CURDATE(), INTERVAL 35 DAY)") ?: 0;
    }
}
