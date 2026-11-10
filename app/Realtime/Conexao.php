<?php

declare(strict_types=1);

namespace Cardapim\Realtime;

/** Estado de um navegador conectado ao servidor WebSocket. */
final class Conexao
{
    public string $entrada = '';
    public string $saida = '';
    public bool $aberta = false;        // aperto de mao concluido
    public bool $encerrando = false;    // fecha assim que terminar de enviar a saida
    public int $idUsuario = 0;
    public int $idRestaurante = 0;
    public string $cargo = '';
    /** @var list<string> */
    public array $permitidos = [];
    /** @var array<string,true> */
    public array $assinados = [];
    public float $ultimoSinal;
    public float $criadaEm;
    public string $fragmentos = '';
    public int $opcodeFragmentado = 0;
    /** @var list<float> */
    public array $mensagens = [];

    /** @param resource $socket */
    public function __construct(public $socket, public readonly int $id, public string $ip)
    {
        $this->criadaEm = $this->ultimoSinal = microtime(true);
    }
}
