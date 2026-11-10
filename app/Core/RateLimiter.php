<?php

declare(strict_types=1);

namespace Cardapim\Core;

/**
 * Limite simples de requisicoes por chave (janela fixa), gravado em storage/cache.
 * Sem Redis por enquanto; quando o Redis entrar, trocar a implementacao mantendo a interface.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly int $maxTentativas,
        private readonly int $janelaSegundos,
    ) {
    }

    public static function padrao(int $maxTentativas, int $janelaSegundos): self
    {
        return new self(dirname(__DIR__, 2) . '/storage/cache/rate-limit', $maxTentativas, $janelaSegundos);
    }

    /** Registra uma tentativa e informa se ainda esta dentro do limite. */
    public function permitir(string $chave): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            error_log('[Cardapim] Rate limit sem diretorio gravavel.');
            return true;
        }
        $file = $this->directory . '/' . hash('sha256', $chave) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            error_log('[Cardapim] Rate limit sem arquivo gravavel.');
            return true;
        }
        try {
            flock($handle, LOCK_EX);
            $agora = time();
            $data = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($data) || !isset($data['inicio'], $data['total']) || $agora - (int) $data['inicio'] >= $this->janelaSegundos) {
                $data = ['inicio' => $agora, 'total' => 0];
            }
            $data['total'] = (int) $data['total'] + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));
            return $data['total'] <= $this->maxTentativas;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
