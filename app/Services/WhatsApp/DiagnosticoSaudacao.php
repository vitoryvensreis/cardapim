<?php

declare(strict_types=1);

namespace Cardapim\Services\WhatsApp;

use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use PDO;
use Throwable;

/**
 * O que aconteceu com as ultimas mensagens recebidas pelo WhatsApp da loja (boas-vindas), para o gerente ver em
 * Integracoes > WhatsApp sem precisar do suporte. Sem dados pessoais: guarda so a hora, o resultado e se o contato
 * veio como numero ou como LID. Arquivo pequeno por loja em storage/cache (as 20 ultimas), nunca no banco.
 */
final class DiagnosticoSaudacao
{
    private const MAX = 20;

    /** resultado => [texto para o gerente, tipo da etiqueta: ok|atencao|alerta|neutra] */
    public const RESULTADOS = [
        'enfileirada' => ['Boas-vindas a caminho', 'ok'],
        'enviada' => ['Boas-vindas enviada', 'ok'],
        'falhou_envio' => ['O WhatsApp não conseguiu enviar', 'alerta'],
        'ja_registrada_hoje' => ['Já recebeu as boas-vindas hoje', 'neutra'],
        'fora_do_horario' => ['Fora do horário de funcionamento', 'atencao'],
        'automacao_desligada' => ['Boas-vindas desligada', 'atencao'],
        'restaurante_inativo' => ['Restaurante inativo', 'alerta'],
        'sessao_sem_restaurante_ativo' => ['WhatsApp da loja não está como conectado no sistema', 'alerta'],
        'envio_sem_conexao' => ['Não enviou: WhatsApp da loja não está como conectado no sistema', 'alerta'],
        'envio_fora_do_horario' => ['Não enviou: o horário acabou antes do envio', 'atencao'],
        'token_recusado' => ['Mensagem recusada: chave do WhatsApp não confere', 'alerta'],
        'data_invalida_ausente_ou_antiga' => ['Mensagem antiga ou sem horário (ignorada)', 'neutra'],
        'mensagem_propria' => ['Mensagem enviada pela própria loja', 'neutra'],
        'lid_formato_invalido' => ['Contato com identificação desconhecida', 'alerta'],
        'remetente_ou_tipo_invalido' => ['Grupo, status ou número de fora do Brasil', 'neutra'],
        'sessao_ausente' => ['Mensagem sem identificação da loja', 'alerta'],
    ];

    public static function registrar(string $sessao, string $resultado, ?string $origem = null): void
    {
        $id = WhatsAppProvedores::restauranteDaSessao($sessao);
        if ($id === null) {
            return;
        }
        try {
            $arquivo = self::arquivo($id, true);
            $h = fopen($arquivo, 'c+');
            if ($h === false) {
                return;
            }
            try {
                flock($h, LOCK_EX);
                $lista = json_decode((string) stream_get_contents($h), true);
                $lista = is_array($lista) ? $lista : [];
                array_unshift($lista, [
                    'em' => date('Y-m-d H:i:s'),
                    'resultado' => $resultado,
                    'contato' => $origem === null ? null : (str_ends_with($origem, '@lid') ? 'lid' : 'numero'),
                ]);
                $lista = array_slice($lista, 0, self::MAX);
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, (string) json_encode($lista, JSON_UNESCAPED_UNICODE));
                fflush($h);
                flock($h, LOCK_UN);
            } finally {
                fclose($h);
            }
        } catch (Throwable) {
            // Diagnostico nunca atrapalha o webhook.
        }
    }

    /** @return list<array{em:string,resultado:string,texto:string,tipo:string,contato:?string}> */
    public static function ultimos(int $idRestaurante): array
    {
        $lista = json_decode((string) @file_get_contents(self::arquivo($idRestaurante, false)), true);
        if (!is_array($lista)) {
            return [];
        }
        $saida = [];
        foreach ($lista as $l) {
            if (!is_array($l) || !is_string($l['em'] ?? null) || !is_string($l['resultado'] ?? null)) {
                continue;
            }
            [$texto, $tipo] = self::RESULTADOS[$l['resultado']] ?? [$l['resultado'], 'neutra'];
            $saida[] = ['em' => $l['em'], 'resultado' => $l['resultado'], 'texto' => $texto, 'tipo' => $tipo,
                'contato' => is_string($l['contato'] ?? null) ? $l['contato'] : null];
        }
        return $saida;
    }

    /**
     * Ultimas boas-vindas da fila (sem o numero inteiro: so os 4 ultimos digitos).
     * @return list<array{em:string,status:string,erro:?string,contato:string}>
     */
    public static function envios(PDO $db, int $idRestaurante): array
    {
        try {
            $q = $db->prepare('SELECT telefone, status, ultimo_erro, criado_em, enviado_em FROM whatsapp_saudacao_diaria
                WHERE id_restaurante = ? ORDER BY id DESC LIMIT 10');
            $q->execute([$idRestaurante]);
        } catch (Throwable) {
            return [];
        }
        return array_map(static function (array $r): array {
            $tel = (string) $r['telefone'];
            return [
                'em' => (string) ($r['enviado_em'] ?? $r['criado_em']),
                'status' => (string) $r['status'],
                'erro' => $r['ultimo_erro'] !== null ? (string) $r['ultimo_erro'] : null,
                'contato' => str_ends_with($tel, '@lid') ? 'Contato com LID' : 'Final ' . substr(preg_replace('/\D+/', '', $tel) ?? '', -4),
            ];
        }, $q->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function arquivo(int $id, bool $criar): string
    {
        $dir = dirname(__DIR__, 3) . '/storage/cache/whatsapp-saudacao';
        if ($criar && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/r' . $id . '.json';
    }
}
