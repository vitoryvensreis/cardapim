<?php

declare(strict_types=1);

namespace Cardapim\Services\WhatsApp;

use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Modules\Integracoes\ProvedorWhatsApp;
use Cardapim\Modules\Integracoes\RecuperacaoDeSessao;
use Cardapim\Modules\Integracoes\WhatsAppProvedores;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Realtime\TempoReal;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\Gerencia\PerfilRepository;
use Cardapim\Repositories\LogUserRepository;
use Closure;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Teste de ponta a ponta do WhatsApp das lojas (rodado pelo vigia, a cada minuto):
 * no horario de funcionamento, a cada 30 minutos o WhatsApp da PLATAFORMA manda uma mensagem curta com um codigo
 * para o WhatsApp da loja. Se em 2 minutos a mensagem nao chegar ao sistema pelo webhook da loja, a loja esta
 * "conectada mas sem receber" (caso de 27/11): reinicia a sessao e testa de novo em 3 minutos. Na 2a falha seguida,
 * alerta a loja. A mensagem de teste nunca dispara boas-vindas.
 * Estado em integracoes.dados.teste da loja: {codigo, enviado_em, recebido_em, proximo_em, falhas} (timestamps).
 */
final class TesteWhatsApp
{
    public const INTERVALO = 1800;
    public const PRAZO = 120;
    public const NOVA_TENTATIVA = 180;
    public const FALHAS_PARA_ALERTA = 2;
    private const PADRAO_CODIGO = '/\bDZT-([A-Z0-9]{6})\b/';

    private readonly IntegracaoRepository $integracoes;
    /** @var Closure(array<string,mixed>):ProvedorWhatsApp */
    private readonly Closure $provedor;

    /** @param (Closure(array<string,mixed>):ProvedorWhatsApp)|null $provedor servidor da integracao (testes) */
    public function __construct(private readonly PDO $db, ?Closure $provedor = null)
    {
        $this->integracoes = new IntegracaoRepository($db);
        $this->provedor = $provedor ?? static fn (array $dados): ProvedorWhatsApp => WhatsAppProvedores::daIntegracao($dados);
    }

    public static function texto(string $codigo): string
    {
        return 'Teste automático do ' . marca() . ': confere se o WhatsApp da loja está recebendo mensagens. Pode ignorar. DZT-' . $codigo;
    }

    /** Codigo do teste dentro de uma mensagem recebida (null = nao e mensagem de teste). */
    public static function codigoNaMensagem(?string $texto): ?string
    {
        return is_string($texto) && preg_match(self::PADRAO_CODIGO, $texto, $m) === 1 ? $m[1] : null;
    }

    /**
     * Regra pura: o que fazer com o teste desta loja agora.
     * @param array<string,mixed> $teste estado gravado (vazio = nunca testou)
     * @return 'enviar'|'falhou'|'esperar'
     */
    public static function decidir(array $teste, int $agora): string
    {
        $enviado = (int) ($teste['enviado_em'] ?? 0);
        $pendente = $enviado > 0 && empty($teste['recebido_em']);
        if ($pendente) {
            return $agora - $enviado >= self::PRAZO ? 'falhou' : 'esperar';
        }
        return $agora >= (int) ($teste['proximo_em'] ?? 0) ? 'enviar' : 'esperar';
    }

    /**
     * Mensagem recebida pelo webhook da loja: se for o teste pendente, marca como recebido e tira o alerta de
     * "sem receber". true = era mensagem de teste (nao vira boas-vindas).
     * @param array<string,mixed> $evento formato da SaudacaoWhatsApp, com body
     */
    public function recebido(int $idRestaurante, array $evento): bool
    {
        $codigo = self::codigoNaMensagem(is_string($evento['body'] ?? null) ? $evento['body'] : null);
        if ($codigo === null) {
            return false;
        }
        $atual = $this->integracoes->buscar($idRestaurante, 'whatsapp');
        $teste = is_array($atual['dados']['teste'] ?? null) ? $atual['dados']['teste'] : [];
        if (($teste['codigo'] ?? null) !== $codigo || !empty($teste['recebido_em'])) {
            return true; // teste antigo ou repetido: so nao responde
        }
        $agora = time();
        $campos = ['dados' => [...$atual['dados'], 'teste' => [
            ...$teste, 'recebido_em' => $agora, 'proximo_em' => $agora + self::INTERVALO, 'falhas' => 0,
        ]]];
        $recuperou = $atual['status'] === 'erro' && $atual['ultimo_erro'] === VigiaWhatsApp::ALERTA_NAO_RECEBE;
        if ($recuperou) {
            $campos += ['status' => 'conectado', 'ultimo_erro' => null, 'verificado_em' => date('Y-m-d H:i:s')];
        }
        $this->integracoes->salvar($idRestaurante, 'whatsapp', $campos);
        if ($recuperou) {
            $this->registrar($idRestaurante, 'WhatsApp voltou a receber mensagens', 'O teste automático chegou de novo.');
            TempoReal::publicar($idRestaurante, 'whatsapp', 'integracao.whatsapp.estado', ['estado' => 'conectado', 'autor' => 0]);
        }
        return true;
    }

    /**
     * Uma rodada para uma loja. null = nao se aplica (loja fechada, sem WAHA, sem numero, plataforma desconectada).
     * @return 'enviado'|'falhou'|'alerta'|'esperar'|null
     */
    public function rodar(int $idRestaurante, ?DateTimeImmutable $agora = null): ?string
    {
        $agora ??= new DateTimeImmutable('now');
        $atual = $this->integracoes->buscar($idRestaurante, 'whatsapp');
        $token = (string) ($this->integracoes->credenciais($idRestaurante, 'whatsapp')['token'] ?? '');
        $sessao = $atual['dados']['sessao'] ?? null;
        $numero = $atual['dados']['numero'] ?? null;
        if (!in_array($atual['status'], ['conectado', 'erro'], true) || !is_string($sessao) || $token === '' || !is_string($numero)
            || WhatsAppProvedores::legado($atual['dados'])) {
            return null;
        }
        // So testa quem ja passou no vigia (conectado) ou esta com o alerta do proprio teste.
        if ($atual['status'] === 'erro' && $atual['ultimo_erro'] !== self::alerta()) {
            return null;
        }
        $api = ($this->provedor)($atual['dados']);
        if (!$api instanceof RecuperacaoDeSessao || !$api->configurado() || !$this->aberta($idRestaurante, $agora)) {
            return null;
        }
        $teste = is_array($atual['dados']['teste'] ?? null) ? $atual['dados']['teste'] : [];
        $acao = self::decidir($teste, $agora->getTimestamp());
        if ($acao === 'esperar') {
            return 'esperar';
        }
        if ($acao === 'falhou') {
            $falhas = (int) ($teste['falhas'] ?? 0) + 1;
            try {
                $api->reiniciar($sessao, $token);
            } catch (IntegracaoErro) {
                // o vigia tenta de novo
            }
            $campos = ['dados' => [...$atual['dados'], 'teste' => [
                'falhas' => $falhas, 'proximo_em' => $agora->getTimestamp() + self::NOVA_TENTATIVA,
            ]]];
            $alertar = $falhas >= self::FALHAS_PARA_ALERTA && $atual['status'] !== 'erro';
            if ($alertar) {
                $campos += ['status' => 'erro', 'ultimo_erro' => self::alerta()];
            }
            $this->integracoes->salvar($idRestaurante, 'whatsapp', $campos);
            if ($falhas === 1) {
                $this->registrar($idRestaurante, 'WhatsApp: teste automático não chegou', 'O sistema reiniciou a conexão e testa de novo em 3 minutos.');
            }
            if ($alertar) {
                $this->registrar($idRestaurante, 'WhatsApp: precisa de atenção', self::alerta());
                TempoReal::publicar($idRestaurante, 'whatsapp', 'integracao.whatsapp.estado', ['estado' => 'erro', 'autor' => 0]);
                return 'alerta';
            }
            return 'falhou';
        }
        // enviar: pelo WhatsApp da plataforma
        $plat = $this->integracoes->buscar(null, 'whatsapp');
        $platToken = (string) ($this->integracoes->credenciais(null, 'whatsapp')['token'] ?? '');
        $platSessao = $plat['dados']['sessao'] ?? null;
        $platNumero = $plat['dados']['numero'] ?? null;
        if ($plat['status'] !== 'conectado' || !is_string($platSessao) || $platToken === '' || $platNumero === $numero
            || WhatsAppProvedores::legado($plat['dados'])) {
            return null;
        }
        $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        try {
            ($this->provedor)($plat['dados'])->enviarTexto($platSessao, $platToken, $numero, self::texto($codigo));
        } catch (Throwable) {
            return null; // problema no WhatsApp da plataforma: nao e culpa da loja
        }
        $this->integracoes->salvar($idRestaurante, 'whatsapp', ['dados' => [...$atual['dados'], 'teste' => [
            'codigo' => $codigo, 'enviado_em' => $agora->getTimestamp(), 'recebido_em' => null,
            'proximo_em' => $agora->getTimestamp() + self::INTERVALO, 'falhas' => (int) ($teste['falhas'] ?? 0),
        ]]]);
        return 'enviado';
    }

    public static function alerta(): string
    {
        return VigiaWhatsApp::ALERTA_NAO_RECEBE;
    }

    private function aberta(int $id, DateTimeImmutable $agora): bool
    {
        $perfil = new PerfilRepository($this->db);
        $loja = $perfil->buscar($id);
        return $loja !== null && ($loja['status_rest'] ?? '') === 'ativo'
            && HorarioFuncionamento::abertoEm((bool) $loja['sempre_aberto'], $perfil->horarios($id), $agora);
    }

    private function registrar(int $id, string $titulo, string $descricao): void
    {
        try {
            $nome = (string) ((new PerfilRepository($this->db))->buscar($id)['nome'] ?? ('Restaurante ' . $id));
            (new LogUserRepository($this->db))->registrar($titulo, $descricao . ' [' . $nome . ']', null, null);
        } catch (Throwable $e) {
            error_log('[Cardapim] Teste WhatsApp, log: ' . get_class($e));
        }
    }
}
