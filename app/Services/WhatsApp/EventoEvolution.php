<?php

declare(strict_types=1);

namespace Cardapim\Services\WhatsApp;

/**
 * Converte o webhook "messages.upsert" da Evolution API para o formato de evento que a
 * SaudacaoWhatsApp ja entende (o mesmo do WPPConnect). Assim a regra de boas-vindas e unica.
 *
 * Evolution: {event, instance, apikey, data: {key: {remoteJid, fromMe, ...}, messageTimestamp}}
 * Saida:     {event: onmessage, session, from, fromMe, isGroupMsg, isStatus, t}
 *
 * Contato com LID: se a Evolution informar o numero real (remoteJidAlt / senderPn), usa o numero;
 * senao segue com o identificador @lid, que a saudacao ja aceita.
 */
final class EventoEvolution
{
    /** @return array<string,mixed>|null null = evento que nao interessa (nao e mensagem recebida) */
    public static function paraSaudacao(array $p): ?array
    {
        $tipo = strtolower(str_replace('_', '.', (string) ($p['event'] ?? '')));
        $sessao = $p['instance'] ?? null;
        if ($tipo !== 'messages.upsert' || !is_string($sessao) || $sessao === '') {
            return null;
        }
        $d = $p['data'] ?? null;
        if (is_array($d) && array_is_list($d)) {
            $d = $d[0] ?? null; // algumas versoes mandam uma lista com uma mensagem
        }
        $chave = is_array($d) ? ($d['key'] ?? null) : null;
        $remoto = is_array($chave) ? ($chave['remoteJid'] ?? null) : null;
        if (!is_string($remoto) || $remoto === '') {
            return null;
        }
        if (str_ends_with($remoto, '@lid')) {
            $remoto = self::numeroReal($chave) ?? $remoto;
        }
        return [
            'event' => 'onmessage',
            'session' => $sessao,
            'from' => preg_replace('/@s\.whatsapp\.net\z/', '@c.us', $remoto),
            'fromMe' => ($chave['fromMe'] ?? null) === true,
            'isGroupMsg' => str_ends_with($remoto, '@g.us'),
            'isStatus' => $remoto === 'status@broadcast',
            't' => self::instante($d['messageTimestamp'] ?? null),
        ];
    }

    /** Token da instancia que a Evolution envia no corpo do webhook (campo apikey), se houver. */
    public static function tokenInstancia(array $p): ?string
    {
        $t = $p['apikey'] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    /** @param array<string,mixed> $chave */
    private static function numeroReal(array $chave): ?string
    {
        foreach (['remoteJidAlt', 'senderPn'] as $campo) {
            $v = $chave[$campo] ?? null;
            if (!is_string($v)) {
                continue;
            }
            if (preg_match('/\A[0-9]{10,15}\z/', $v) === 1) {
                return $v . '@s.whatsapp.net';
            }
            if (preg_match('/\A[0-9]{10,15}@s\.whatsapp\.net\z/', $v) === 1) {
                return $v;
            }
        }
        return null;
    }

    /** messageTimestamp pode vir como numero, texto ou {low, high} (Long do protobuf). */
    private static function instante(mixed $v): ?int
    {
        if (is_array($v)) {
            $v = $v['low'] ?? null;
        }
        if (is_int($v)) {
            return $v;
        }
        return is_string($v) && ctype_digit($v) ? (int) $v : null;
    }
}
