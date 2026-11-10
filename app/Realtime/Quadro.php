<?php

declare(strict_types=1);

namespace Cardapim\Realtime;

/**
 * Protocolo WebSocket (RFC 6455): aperto de mao e quadros (frames).
 * Somente o necessario para o servidor: texto, ping/pong, close e fragmentacao.
 */
final class Quadro
{
    public const TEXTO = 0x1;
    public const BINARIO = 0x2;
    public const CONTINUACAO = 0x0;
    public const FECHAR = 0x8;
    public const PING = 0x9;
    public const PONG = 0xA;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** Valor do cabecalho Sec-WebSocket-Accept. */
    public static function chaveAceite(string $chaveCliente): string
    {
        return base64_encode(sha1(trim($chaveCliente) . self::GUID, true));
    }

    public static function chaveClienteValida(string $chave): bool
    {
        $bin = base64_decode(trim($chave), true);
        return $bin !== false && strlen($bin) === 16;
    }

    /** Quadro do servidor (sem mascara, FIN=1). */
    public static function montar(string $dados, int $opcode = self::TEXTO): string
    {
        $tamanho = strlen($dados);
        $cabecalho = chr(0x80 | ($opcode & 0x0F));
        if ($tamanho < 126) {
            $cabecalho .= chr($tamanho);
        } elseif ($tamanho < 65536) {
            $cabecalho .= chr(126) . pack('n', $tamanho);
        } else {
            $cabecalho .= chr(127) . pack('J', $tamanho);
        }
        return $cabecalho . $dados;
    }

    public static function fechar(int $codigo = 1000, string $motivo = ''): string
    {
        return self::montar(pack('n', $codigo) . substr($motivo, 0, 120), self::FECHAR);
    }

    /** Quadro do cliente (com mascara), usado pelos testes e pelo cliente de linha de comando. */
    public static function montarCliente(string $dados, int $opcode = self::TEXTO, bool $fin = true): string
    {
        $tamanho = strlen($dados);
        $cabecalho = chr(($fin ? 0x80 : 0) | ($opcode & 0x0F));
        if ($tamanho < 126) {
            $cabecalho .= chr(0x80 | $tamanho);
        } elseif ($tamanho < 65536) {
            $cabecalho .= chr(0x80 | 126) . pack('n', $tamanho);
        } else {
            $cabecalho .= chr(0x80 | 127) . pack('J', $tamanho);
        }
        $mascara = random_bytes(4);
        return $cabecalho . $mascara . self::aplicarMascara($dados, $mascara);
    }

    /**
     * Le quantos quadros completos houver no buffer (o resto fica para a proxima leitura).
     * @return array{quadros: list<array{fin:bool,opcode:int,dados:string}>, resto:string, erro:?int}
     *         erro = codigo de fechamento (1002 protocolo, 1009 grande demais)
     */
    public static function ler(string $buffer, bool $exigirMascara, int $maximo): array
    {
        $quadros = [];
        $pos = 0;
        $total = strlen($buffer);
        while ($total - $pos >= 2) {
            $b1 = ord($buffer[$pos]);
            $b2 = ord($buffer[$pos + 1]);
            if (($b1 & 0x70) !== 0) {
                return ['quadros' => $quadros, 'resto' => '', 'erro' => 1002]; // RSV sem extensao negociada
            }
            $mascarado = ($b2 & 0x80) !== 0;
            if ($exigirMascara !== $mascarado) {
                return ['quadros' => $quadros, 'resto' => '', 'erro' => 1002];
            }
            $tamanho = $b2 & 0x7F;
            $offset = 2;
            if ($tamanho === 126) {
                if ($total - $pos < 4) {
                    break;
                }
                $tamanho = unpack('n', substr($buffer, $pos + 2, 2))[1];
                $offset = 4;
            } elseif ($tamanho === 127) {
                if ($total - $pos < 10) {
                    break;
                }
                $tamanho = unpack('J', substr($buffer, $pos + 2, 8))[1];
                $offset = 10;
            }
            if ($tamanho > $maximo || $tamanho < 0) {
                return ['quadros' => $quadros, 'resto' => '', 'erro' => 1009];
            }
            $opcode = $b1 & 0x0F;
            if ($opcode >= 0x8 && ($tamanho > 125 || ($b1 & 0x80) === 0)) {
                return ['quadros' => $quadros, 'resto' => '', 'erro' => 1002]; // controle: curto e sem fragmentar
            }
            $precisa = $offset + ($mascarado ? 4 : 0) + $tamanho;
            if ($total - $pos < $precisa) {
                break;
            }
            $dados = substr($buffer, $pos + $offset + ($mascarado ? 4 : 0), $tamanho);
            if ($mascarado) {
                $dados = self::aplicarMascara($dados, substr($buffer, $pos + $offset, 4));
            }
            $quadros[] = ['fin' => ($b1 & 0x80) !== 0, 'opcode' => $opcode, 'dados' => $dados];
            $pos += $precisa;
        }
        return ['quadros' => $quadros, 'resto' => (string) substr($buffer, $pos), 'erro' => null];
    }

    private static function aplicarMascara(string $dados, string $mascara): string
    {
        $tamanho = strlen($dados);
        if ($tamanho === 0) {
            return '';
        }
        $chave = str_repeat($mascara, intdiv($tamanho, 4) + 1);
        return $dados ^ substr($chave, 0, $tamanho);
    }
}
