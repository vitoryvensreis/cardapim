<?php

declare(strict_types=1);

namespace Cardapim\Services\WhatsApp;

/**
 * Converte o webhook "message" do WAHA para o formato de evento interno da SaudacaoWhatsApp
 * (event, session, from, fromMe, isGroupMsg, isStatus, t).
 *
 * WAHA: {event: "message", session, payload: {from, fromMe, timestamp, ...}}
 * Saida: {event: onmessage, session, from, fromMe, isGroupMsg, isStatus, t, body}
 *
 * Contato com LID: se o WAHA informar o numero real (campos alternativos do evento), usa o numero;
 * senao segue com o identificador @lid, que a saudacao ja aceita (e o WAHA envia para @lid).
 */
final class EventoWaha
{
    /** @return array<string,mixed>|null null = evento que nao interessa (nao e mensagem recebida) */
    public static function paraSaudacao(array $e): ?array
    {
        $sessao = $e['session'] ?? null;
        $p = $e['payload'] ?? null;
        if (($e['event'] ?? null) !== 'message' || !is_string($sessao) || $sessao === '' || !is_array($p)) {
            return null;
        }
        $origem = $p['from'] ?? null;
        if (!is_string($origem) || $origem === '') {
            return null;
        }
        if (str_ends_with($origem, '@lid')) {
            $origem = self::numeroReal($p) ?? $origem;
        }
        $origem = preg_replace('/@s\.whatsapp\.net\z/', '@c.us', $origem) ?? $origem;
        return [
            'event' => 'onmessage',
            'session' => $sessao,
            'from' => $origem,
            'fromMe' => ($p['fromMe'] ?? null) === true,
            'isGroupMsg' => str_ends_with($origem, '@g.us'),
            'isStatus' => $origem === 'status@broadcast',
            't' => self::instante($p['timestamp'] ?? null),
            // Texto so para reconhecer o teste automatico (TesteWhatsApp); nunca e gravado.
            'body' => is_string($p['body'] ?? null) ? mb_substr($p['body'], 0, 500) : null,
        ];
    }

    /** Numero real de um contato com LID, quando o evento traz (varia conforme o motor do WAHA). */
    private static function numeroReal(array $p): ?string
    {
        $dados = is_array($p['_data'] ?? null) ? $p['_data'] : [];
        $info = is_array($dados['Info'] ?? null) ? $dados['Info'] : [];
        $chave = is_array($dados['key'] ?? null) ? $dados['key'] : [];
        foreach ([$p['fromAlt'] ?? null, $info['SenderAlt'] ?? null, $info['ChatAlt'] ?? null, $chave['remoteJidAlt'] ?? null, $chave['senderPn'] ?? null] as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = explode(':', $v)[0]; // "5585...:12@s.whatsapp.net" (aparelho) -> numero
            if (preg_match('/\A([0-9]{10,15})(@(s\.whatsapp\.net|c\.us))?\z/', $v, $m) === 1) {
                return $m[1] . '@c.us';
            }
        }
        return null;
    }

    /** Segundos Unix (o WAHA pode mandar em milissegundos). */
    private static function instante(mixed $v): ?int
    {
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }
        if (is_float($v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v <= 0) {
            return null;
        }
        return $v > 100000000000 ? intdiv($v, 1000) : $v;
    }
}
