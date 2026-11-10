<?php

declare(strict_types=1);

namespace Cardapim\Modules\Entrega;

/**
 * Decide se o restaurante entrega em um endereco e qual area (taxa) vale.
 * Regra (simples de explicar ao gerente):
 * 1) bairro cadastrado (mesmo nome e cidade) tem prioridade;
 * 2) senao, vale a MENOR faixa de km que alcanca o endereco (ate 3 km, ate 6 km...);
 * 3) nenhum dos dois = nao entrega.
 */
final class AreaEntrega
{
    /**
     * @param list<array{id:int,tipo:string,bairro:?string,cidade:?string,raio_km:?string,taxa:string,pedido_minimo:?string,ativo:int|string}> $areas
     * @return array{area: array<string,mixed>|null, motivo: string}
     *   motivo: bairro | raio | fora_bairro | fora_raio | sem_localizacao | sem_areas
     */
    public static function escolher(array $areas, ?string $bairro, ?string $cidade, ?float $distanciaKm): array
    {
        $ativas = array_values(array_filter($areas, static fn (array $a): bool => (int) $a['ativo'] === 1));
        if ($ativas === []) {
            return ['area' => null, 'motivo' => 'sem_areas'];
        }
        $chaveBairro = TextoLocal::chave($bairro);
        $chaveCidade = TextoLocal::chave($cidade);
        $temRaio = false;
        if ($chaveBairro !== '') {
            foreach ($ativas as $a) {
                if ($a['tipo'] !== 'bairro') {
                    continue;
                }
                $cidadeArea = TextoLocal::chave($a['cidade'] ?? '');
                if (TextoLocal::chave($a['bairro']) === $chaveBairro && ($cidadeArea === '' || $chaveCidade === '' || $cidadeArea === $chaveCidade)) {
                    return ['area' => $a, 'motivo' => 'bairro'];
                }
            }
        }
        $faixas = array_values(array_filter($ativas, static fn (array $a): bool => $a['tipo'] === 'raio'));
        usort($faixas, static fn (array $x, array $y): int => (float) $x['raio_km'] <=> (float) $y['raio_km']);
        $temRaio = $faixas !== [];
        if ($temRaio && $distanciaKm !== null) {
            foreach ($faixas as $a) {
                if ($distanciaKm <= (float) $a['raio_km'] + 0.0001) {
                    return ['area' => $a, 'motivo' => 'raio'];
                }
            }
            return ['area' => null, 'motivo' => 'fora_raio'];
        }
        if ($temRaio) {
            return ['area' => null, 'motivo' => 'sem_localizacao'];
        }
        return ['area' => null, 'motivo' => 'fora_bairro'];
    }

    /** Texto curto da area para o cliente e para o pedido. @param array<string,mixed> $a */
    public static function descricao(array $a): string
    {
        if ($a['tipo'] === 'bairro') {
            return 'Bairro ' . $a['bairro'] . (($a['cidade'] ?? '') !== '' && $a['cidade'] !== null ? ' (' . $a['cidade'] . ')' : '');
        }
        return 'Até ' . self::km((string) $a['raio_km']) . ' do restaurante';
    }

    public static function km(string $decimal): string
    {
        $v = rtrim(rtrim(number_format((float) $decimal, 2, ',', ''), '0'), ',');
        return $v . ' km';
    }

    public static function precisaDeLocalizacao(array $areas): bool
    {
        foreach ($areas as $a) {
            if ($a['tipo'] === 'raio' && (int) $a['ativo'] === 1) {
                return true;
            }
        }
        return false;
    }
}
