<?php

declare(strict_types=1);

namespace Cardapim\Services\Impressao;

use Cardapim\Repositories\Desktop\CupomAparenciaRepository;
use Cardapim\Shared\Esquema;
use PDO;

/**
 * Aparencia do cupom desenhado pelo servidor (CupomImagem): fonte, peso do texto, peso dos destaques (itens, titulos e
 * totais) e tamanho da letra. O padrao vem do Deliveryzim; cada restaurante pode personalizar em Gerencia > Impressoras.
 * Fonte unica das opcoes: a tela, a validacao e o desenho usam estas constantes.
 */
final class AparenciaCupom
{
    /** Padrao do Deliveryzim (vale para quem nunca personalizou ou tocou em "Voltar ao padrao"). */
    public const PADRAO = ['fonte' => 'roboto-mono', 'peso_texto' => 400, 'peso_destaque' => 700, 'tamanho' => 'normal'];

    /**
     * Fontes disponiveis: arquivo de cada peso em resources/fonts e o tamanho base (pontos do GD) da letra Normal,
     * escolhido para caber ~47 letras por linha no papel de 80 mm.
     */
    public const FONTES = [
        'roboto-mono' => [
            'nome' => 'Roboto Mono', 'base' => 14.5, 'prefixo' => 'roboto-mono/RobotoMono-',
            'pesos' => [100 => 'Thin', 200 => 'ExtraLight', 300 => 'Light', 400 => 'Regular', 500 => 'Medium', 600 => 'SemiBold', 700 => 'Bold'],
        ],
        'poppins' => [
            'nome' => 'Poppins', 'base' => 15.5, 'prefixo' => 'Poppins-',
            'pesos' => [100 => 'Thin', 200 => 'ExtraLight', 300 => 'Light', 400 => 'Regular', 500 => 'Medium', 600 => 'SemiBold',
                700 => 'Bold', 800 => 'ExtraBold', 900 => 'Black'],
        ],
    ];

    public const ROTULOS_PESO = [100 => 'Fina', 200 => 'Extra fina', 300 => 'Leve', 400 => 'Regular', 500 => 'Média',
        600 => 'Seminegrito', 700 => 'Negrito', 800 => 'Extra negrito', 900 => 'Ultra negrito'];

    /** Tamanho da letra: rotulo e multiplicador do tamanho base. */
    public const TAMANHOS = ['pequena' => ['Pequena', 0.86], 'normal' => ['Normal', 1.0], 'grande' => ['Grande', 1.2]];

    /** Pesos abaixo disso podem sair apagados no papel termico (a tela avisa). */
    public const PESO_FRACO = 300;

    /**
     * Confere o que veio do formulario. Devolve a aparencia limpa ou a mensagem de erro.
     * @param array<string,mixed> $dados
     * @return array{fonte:string,peso_texto:int,peso_destaque:int,tamanho:string}|string
     */
    public static function validar(array $dados): array|string
    {
        $fonte = is_string($dados['fonte'] ?? null) ? $dados['fonte'] : '';
        if (!isset(self::FONTES[$fonte])) {
            return 'Escolha a fonte do cupom.';
        }
        $pesos = self::FONTES[$fonte]['pesos'];
        $texto = filter_var($dados['peso_texto'] ?? null, FILTER_VALIDATE_INT);
        $destaque = filter_var($dados['peso_destaque'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($texto) || !isset($pesos[$texto]) || !is_int($destaque) || !isset($pesos[$destaque])) {
            return 'Escolha um peso disponível para a fonte ' . self::FONTES[$fonte]['nome'] . '.';
        }
        $tamanho = is_string($dados['tamanho'] ?? null) ? $dados['tamanho'] : '';
        if (!isset(self::TAMANHOS[$tamanho])) {
            return 'Escolha o tamanho da letra.';
        }
        return ['fonte' => $fonte, 'peso_texto' => $texto, 'peso_destaque' => $destaque, 'tamanho' => $tamanho];
    }

    /**
     * Aparencia do restaurante (ou o padrao, se nunca personalizou, se a migracao ainda nao rodou ou se algo gravado
     * nao vale mais).
     * @return array{fonte:string,peso_texto:int,peso_destaque:int,tamanho:string,personalizada:bool}
     */
    public static function doRestaurante(PDO $db, int $idRestaurante): array
    {
        $salva = null;
        try {
            if (Esquema::tem($db, 'cupom_aparencia')) {
                $salva = (new CupomAparenciaRepository($db))->buscar($idRestaurante);
            }
        } catch (\Throwable) {
            $salva = null;
        }
        $ok = is_array($salva) ? self::validar($salva) : null;
        return is_array($ok) ? $ok + ['personalizada' => true] : self::PADRAO + ['personalizada' => false];
    }

    /** Caminho do arquivo da fonte no peso pedido (peso que a fonte nao tem cai no mais proximo). */
    public static function arquivo(string $fonte, int $peso): string
    {
        $f = self::FONTES[$fonte] ?? self::FONTES[self::PADRAO['fonte']];
        if (!isset($f['pesos'][$peso])) {
            $proximo = array_keys($f['pesos'])[0];
            foreach (array_keys($f['pesos']) as $p) {
                if (abs($p - $peso) < abs($proximo - $peso)) {
                    $proximo = $p;
                }
            }
            $peso = $proximo;
        }
        return dirname(__DIR__, 3) . '/resources/fonts/' . $f['prefixo'] . $f['pesos'][$peso] . '.ttf';
    }

    /**
     * Opcoes para a tela: fontes com os pesos disponiveis (rotulo em portugues) e os tamanhos.
     * @return array{fontes:list<array{id:string,nome:string,pesos:list<array{valor:int,rotulo:string}>}>,tamanhos:list<array{id:string,rotulo:string}>}
     */
    public static function opcoes(): array
    {
        $fontes = [];
        foreach (self::FONTES as $id => $f) {
            $fontes[] = ['id' => $id, 'nome' => $f['nome'], 'pesos' => array_map(
                static fn (int $p): array => ['valor' => $p, 'rotulo' => self::ROTULOS_PESO[$p] . ' (' . $p . ')'],
                array_keys($f['pesos'])
            )];
        }
        $tamanhos = [];
        foreach (self::TAMANHOS as $id => [$rotulo]) {
            $tamanhos[] = ['id' => $id, 'rotulo' => $rotulo];
        }
        return ['fontes' => $fontes, 'tamanhos' => $tamanhos];
    }

    /**
     * Cupom de exemplo da previa (mesmos blocos que a impressao real usa).
     * @return list<array<string,mixed>>
     */
    public static function exemplo(string $restaurante): array
    {
        return [
            ['t' => 'centro', 'texto' => $restaurante],
            ['t' => 'linha'],
            ['t' => 'titulo', 'texto' => 'MESA 12'],
            ['t' => 'texto', 'texto' => 'Comanda: Ana · Pedido #128'],
            ['t' => 'texto', 'texto' => date('d/m/Y H:i') . ' · Garçom: Carlos'],
            ['t' => 'destaque', 'texto' => 'COZINHA'],
            ['t' => 'linha'],
            ['t' => 'item', 'qtd' => '2x', 'nome' => 'Espetinho de frango com bacon', 'valor' => 'R$ 24,00',
                'detalhes' => ['+ Farofa (2x)', '+ Vinagrete', 'Obs: sem cebola, bem passado']],
            ['t' => 'item', 'qtd' => '1x', 'nome' => 'Refrigerante lata', 'valor' => 'R$ 6,00', 'detalhes' => []],
            ['t' => 'linha'],
            ['t' => 'valor', 'rotulo' => 'Subtotal', 'valor' => 'R$ 30,00'],
            ['t' => 'valor', 'rotulo' => 'Total', 'valor' => 'R$ 30,00', 'forte' => true],
            ['t' => 'linha'],
            ['t' => 'centro', 'texto' => 'Obrigado pela preferência!'],
        ];
    }
}
