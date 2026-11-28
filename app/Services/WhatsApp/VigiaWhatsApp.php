<?php

declare(strict_types=1);

namespace Cardapim\Services\WhatsApp;

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\ProvedorWhatsApp;
use Cardapim\Modules\Integracoes\RecuperacaoDeSessao;
use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Repositories\LogUserRepository;
use Closure;
use PDO;
use Throwable;

/**
 * Vigia do WhatsApp (bin/whatsapp-vigia.php, a cada minuto). Para cada WhatsApp em uso num servidor que permite
 * recuperar a sessao (WAHA):
 * - sessao parada ou com falha: reinicia sozinho (o celular continua vinculado, ninguem precisa ir a loja);
 * - continua caida depois de 3 rodadas, celular desvinculado (pede QR Code) ou confirmacao pendente no celular:
 *   marca o WhatsApp com alerta ("erro") e avisa a tela, com o que fazer;
 * - voltou a funcionar: tira o alerta sozinho.
 * Servidor do WhatsApp fora do ar nao conta como falha da loja (nada muda; a proxima rodada confere de novo).
 * Ao final, tenta de novo as boas-vindas que falharam (SaudacaoWhatsApp).
 */
final class VigiaWhatsApp
{
    public const FALHAS_PARA_ALERTA = 3;

    private const ALERTA_DESVINCULADO = 'O celular desconectou o WhatsApp da loja. Abra esta tela e conecte de novo (QR Code ou código).';
    private const ALERTA_PASSKEY = 'O WhatsApp pediu uma confirmação no celular da loja. Abra o WhatsApp no aparelho e confirme.';
    private const ALERTA_CAIU = 'O WhatsApp da loja caiu e não voltou sozinho. Confira se o celular está ligado e com internet; se continuar, conecte de novo.';

    private readonly IntegracaoRepository $integracoes;
    /** @var Closure(array<string,mixed>):ProvedorWhatsApp */
    private readonly Closure $provedor;

    /** @param (Closure(array<string,mixed>):ProvedorWhatsApp)|null $provedor servidor da integracao (testes) */
    public function __construct(private readonly PDO $db, ?Closure $provedor = null)
    {
        $this->integracoes = new IntegracaoRepository($db);
        $this->provedor = $provedor ?? static fn (array $dados): ProvedorWhatsApp => WhatsAppProvedores::daIntegracao($dados);
    }

    /**
     * O que fazer com a situacao vista agora. Regra pura (testada sem rede nem banco).
     * @param string $servidor status do provedor: CONNECTED, QRCODE, INITIALIZING, PASSKEY ou CLOSED
     * @param string $integracao status gravado: conectado ou erro
     * @param int $falhas rodadas seguidas sem conexao antes desta
     * @param ?string $alertaAtual ultimo_erro gravado
     * @return array{falhas:int, reiniciar:bool, alerta:?string, recuperou:bool}
     */
    public static function decidir(string $servidor, string $integracao, int $falhas, ?string $alertaAtual = null): array
    {
        $r = ['falhas' => 0, 'reiniciar' => false, 'alerta' => null, 'recuperou' => false];
        if ($servidor !== 'CONNECTED' && $integracao === 'erro' && $alertaAtual === self::ALERTA_DESVINCULADO) {
            // Celular desvinculado: so uma pessoa resolve (QR Code ou codigo). Reiniciar so geraria outro QR Code.
            return ['falhas' => $falhas + 1, 'reiniciar' => false, 'alerta' => self::ALERTA_DESVINCULADO, 'recuperou' => false];
        }
        switch ($servidor) {
            case 'CONNECTED':
                $r['recuperou'] = $integracao === 'erro';
                return $r;
            case 'QRCODE': // so pede QR Code quem foi desvinculado no celular: reiniciar nao resolve
                return ['falhas' => $falhas + 1, 'reiniciar' => false, 'alerta' => self::ALERTA_DESVINCULADO, 'recuperou' => false];
            case 'PASSKEY':
                return ['falhas' => $falhas + 1, 'reiniciar' => false, 'alerta' => self::ALERTA_PASSKEY, 'recuperou' => false];
            case 'INITIALIZING': // iniciando: espera; preso iniciando por varias rodadas, reinicia
                $falhas++;
                return ['falhas' => $falhas, 'reiniciar' => $falhas >= 2 && self::hora($falhas),
                    'alerta' => $falhas >= self::FALHAS_PARA_ALERTA ? self::ALERTA_CAIU : null, 'recuperou' => false];
            default: // CLOSED: parada ou com falha
                $falhas++;
                return ['falhas' => $falhas, 'reiniciar' => self::hora($falhas),
                    'alerta' => $falhas >= self::FALHAS_PARA_ALERTA ? self::ALERTA_CAIU : null, 'recuperou' => false];
        }
    }

    /** Reinicia nas primeiras rodadas; depois so de 10 em 10 minutos, para nao martelar o servidor. */
    private static function hora(int $falhas): bool
    {
        return $falhas <= self::FALHAS_PARA_ALERTA + 2 || $falhas % 10 === 0;
    }

    /** @return array{verificados:int, reiniciados:int, alertas:int, recuperados:int, boas_vindas:int} */
    public function rodar(): array
    {
        $total = ['verificados' => 0, 'reiniciados' => 0, 'alertas' => 0, 'recuperados' => 0, 'boas_vindas' => 0];
        foreach ($this->integracoes->whatsappEmUso() as $id) {
            try {
                $r = $this->verificar($id);
            } catch (Throwable $e) {
                error_log('[Cardapim] Vigia WhatsApp (' . ($id ?? 'plataforma') . '): ' . get_class($e));
                continue;
            }
            if ($r === null) {
                continue;
            }
            $total['verificados']++;
            $total['reiniciados'] += $r['reiniciou'] ? 1 : 0;
            $total['alertas'] += $r['alertou'] ? 1 : 0;
            $total['recuperados'] += $r['recuperou'] ? 1 : 0;
        }
        \Cardapim\Services\Pedidos\SaudacaoWhatsApp::expirar($this->db);
        foreach (\Cardapim\Services\Pedidos\SaudacaoWhatsApp::lojasComFila($this->db) as $loja) {
            try {
                $total['boas_vindas'] += \Cardapim\Services\Pedidos\SaudacaoWhatsApp::enviarPendentes($this->db, $loja);
            } catch (Throwable $e) {
                error_log('[Cardapim] Vigia WhatsApp, boas-vindas (' . $loja . '): ' . get_class($e));
            }
        }
        return $total;
    }

    /**
     * Confere um WhatsApp. null = nao se aplica (servidor sem recuperacao, sem sessao ou servidor fora do ar).
     * @return array{reiniciou:bool, alertou:bool, recuperou:bool}|null
     */
    public function verificar(?int $id): ?array
    {
        $atual = $this->integracoes->buscar($id, 'whatsapp');
        $token = (string) ($this->integracoes->credenciais($id, 'whatsapp')['token'] ?? '');
        $sessao = $atual['dados']['sessao'] ?? null;
        if (!in_array($atual['status'], ['conectado', 'erro'], true) || !is_string($sessao) || $token === '') {
            return null;
        }
        $api = ($this->provedor)($atual['dados']);
        if (!$api instanceof RecuperacaoDeSessao || !$api->configurado()) {
            return null;
        }
        try {
            $estado = $api->estado($sessao, $token);
        } catch (IntegracaoErro) {
            return null; // servidor do WhatsApp fora do ar: nao e culpa da loja
        }
        $falhasAntes = (int) ($atual['dados']['vigia_falhas'] ?? 0);
        $d = self::decidir($estado['status'], $atual['status'], $falhasAntes, $atual['ultimo_erro']);
        $reiniciou = false;
        if ($d['reiniciar']) {
            try {
                $api->reiniciar($sessao, $token);
                $reiniciou = true;
            } catch (IntegracaoErro) {
                // tenta de novo na proxima rodada
            }
        }
        $dados = $atual['dados'];
        unset($dados['vigia_falhas']);
        if ($d['falhas'] > 0) {
            $dados['vigia_falhas'] = $d['falhas'];
        }
        $campos = [];
        if ($dados !== $atual['dados']) {
            $campos['dados'] = $dados;
        }
        $alertou = $d['alerta'] !== null && ($atual['status'] !== 'erro' || $atual['ultimo_erro'] !== $d['alerta']);
        if ($alertou) {
            $campos += ['status' => 'erro', 'ultimo_erro' => $d['alerta']];
        }
        if ($d['recuperou']) {
            $campos += ['status' => 'conectado', 'ultimo_erro' => null, 'verificado_em' => date('Y-m-d H:i:s')];
        }
        if ($campos !== []) {
            $this->integracoes->salvar($id, 'whatsapp', $campos);
        }
        if ($reiniciou && $falhasAntes === 0) {
            $this->registrar($id, 'WhatsApp: conexão reiniciada automaticamente', 'A sessão estava parada no servidor; o sistema reiniciou sem precisar do celular.');
        }
        if ($alertou) {
            $this->registrar($id, 'WhatsApp: precisa de atenção', $d['alerta']);
            $this->avisarTela($id, 'erro');
        }
        if ($d['recuperou']) {
            $this->registrar($id, 'WhatsApp reconectado automaticamente', 'A conexão voltou sozinha.');
            $this->avisarTela($id, 'conectado');
        }
        return ['reiniciou' => $reiniciou, 'alertou' => $alertou, 'recuperou' => $d['recuperou']];
    }

    private function registrar(?int $id, string $titulo, string $descricao): void
    {
        $nome = 'Plataforma';
        if ($id !== null) {
            $nome = (string) ((new PerfilRepository($this->db))->buscar($id)['nome'] ?? ('Restaurante ' . $id));
        }
        try {
            // Acao do sistema: sem usuario (id_user NULL), com o nome da loja no fim, como no resto do log.
            (new LogUserRepository($this->db))->registrar($titulo, $descricao . ' [' . $nome . ']', null, null);
        } catch (Throwable $e) {
            error_log('[Cardapim] Vigia WhatsApp, log: ' . get_class($e));
        }
    }

    private function avisarTela(?int $id, string $estado): void
    {
        if ($id !== null) {
            TempoReal::publicar($id, 'whatsapp', 'integracao.whatsapp.estado', ['estado' => $estado, 'autor' => 0]);
        }
    }
}
