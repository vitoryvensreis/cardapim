<?php

declare(strict_types=1);

namespace Cardapim\Services\Pedidos;

use Cardapim\Modules\Entrega\Distancia;
use Cardapim\Modules\Entrega\TextoLocal;
use Cardapim\Services\Gerencia\PerfilService;

/** Endereco de entrega digitado pelo cliente: limpa e valida (mensagens por campo). */
final class Endereco
{
    /** @param array<string,mixed> $e @return array{dados: array<string,mixed>, erros: array<string,string>} */
    public static function normalizar(mixed $e, bool $exigir = true): array
    {
        $e = is_array($e) ? $e : [];
        $t = static fn (string $campo, int $max): string => TextoLocal::limpo(is_string($e[$campo] ?? null) ? $e[$campo] : '', $max);
        $cep = (string) preg_replace('/\D/', '', is_string($e['cep'] ?? null) ? $e['cep'] : '');
        $d = [
            'cep' => $cep,
            'logradouro' => $t('logradouro', 150),
            'numero' => $t('numero', 20),
            'complemento' => $t('complemento', 100),
            'bairro' => $t('bairro', 100),
            'cidade' => $t('cidade', 100),
            'estado' => strtoupper($t('estado', 2)),
            'referencia' => $t('referencia', 150),
            'latitude' => null,
            'longitude' => null,
        ];
        if (Distancia::coordenadaValida($e['latitude'] ?? null, $e['longitude'] ?? null)) {
            $d['latitude'] = round((float) $e['latitude'], 7);
            $d['longitude'] = round((float) $e['longitude'], 7);
        }
        $erros = [];
        if ($cep !== '' && preg_match('/\A[0-9]{8}\z/', $cep) !== 1) {
            $erros['cep'] = 'O CEP tem 8 números.';
        }
        if ($exigir) {
            if (mb_strlen($d['logradouro']) < 3) {
                $erros['logradouro'] = 'Digite a rua.';
            }
            if ($d['numero'] === '') {
                $erros['numero'] = 'Digite o número (ou S/N).';
            }
            if (mb_strlen($d['bairro']) < 2) {
                $erros['bairro'] = 'Digite o bairro.';
            }
            if (mb_strlen($d['cidade']) < 2) {
                $erros['cidade'] = 'Digite a cidade.';
            }
            if (!isset(PerfilService::UFS[$d['estado']])) {
                $erros['estado'] = 'Escolha o estado.';
            }
        }
        return ['dados' => $d, 'erros' => $erros];
    }
}
