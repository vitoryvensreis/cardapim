<?php

declare(strict_types=1);

namespace Cardapim\Services\Cliente;

use Cardapim\Core\RateLimiter;
use Cardapim\Core\Session;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Shared\Validation\Documento;

/**
 * Codigo de 6 digitos para o cliente entrar ou se cadastrar.
 * Envio: primeiro pelo WhatsApp da plataforma; se ele estiver indisponivel ou falhar, o MESMO codigo vai
 * por SMS automaticamente (SMSGate). O canal usado volta na resposta (canal: whatsapp | sms).
 * O codigo fica so na sessao deste navegador (HMAC, nunca em texto), vale 10 minutos e aceita 5 tentativas.
 * Limites: novo envio so depois de 60 s; 5 envios por numero e 20 por IP a cada hora.
 */
final class CodigoVerificacao
{
    public const VALIDADE = 600;
    public const ESPERA_REENVIO = 60;
    public const MAX_TENTATIVAS = 5;
    private const CHAVE = 'cliente_codigo';

    /** @param CanalCodigo|null $sms canal de reserva (null = so WhatsApp) */
    public function __construct(
        private readonly CanalCodigo $whatsapp,
        private readonly ?\Closure $gerador = null,
        private readonly ?CanalCodigo $sms = null,
    ) {
    }

    /** Algum canal pode entregar o codigo agora? */
    public function disponivel(): bool
    {
        return $this->whatsapp->disponivel() || ($this->sms?->disponivel() ?? false);
    }

    /**
     * @return array{ok:bool, mensagem:string, campo?:string, espera?:int, telefone?:string, canal?:string, status?:int}
     *         telefone = numero formatado para mostrar na tela; canal = whatsapp | sms
     */
    public function solicitar(string $telefoneDigitado, string $ip): array
    {
        $telefone = Documento::normalizarTelefone($telefoneDigitado);
        if ($telefone === null) {
            return ['ok' => false, 'campo' => 'telefone', 'status' => 422, 'mensagem' => 'Confira o número: DDD + número do celular. Exemplo: (85) 99999-0000.'];
        }
        $atual = Session::get(self::CHAVE);
        if (is_array($atual) && ($atual['tel'] ?? '') === $telefone) {
            $falta = (int) $atual['enviado_em'] + self::ESPERA_REENVIO - time();
            if ($falta > 0) {
                return ['ok' => false, 'status' => 429, 'espera' => $falta, 'mensagem' => 'Aguarde ' . $falta . ' segundos para pedir outro código.'];
            }
        }
        $porNumero = RateLimiter::padrao(5, 3600);
        $porIp = RateLimiter::padrao(20, 3600);
        if ($porNumero->excedido('cliente-codigo|' . $telefone) || $porIp->excedido('cliente-codigo-ip|' . $ip)) {
            return ['ok' => false, 'status' => 429, 'mensagem' => 'Muitos códigos pedidos em pouco tempo. Tente de novo em uma hora.'];
        }
        $canais = array_values(array_filter([$this->whatsapp, $this->sms], static fn (?CanalCodigo $c): bool => $c !== null && $c->disponivel()));
        if ($canais === []) {
            return ['ok' => false, 'status' => 503, 'mensagem' => 'O envio de códigos está indisponível no momento. Tente de novo em alguns minutos.'];
        }
        $porNumero->permitir('cliente-codigo|' . $telefone);
        $porIp->permitir('cliente-codigo-ip|' . $ip);

        $codigo = $this->gerador !== null ? (string) ($this->gerador)() : sprintf('%06d', random_int(0, 999999));
        $canal = null;
        foreach ($canais as $c) {
            try {
                $c->enviarCodigo($telefone, $codigo);
                $canal = $c->canal();
                break;
            } catch (IntegracaoErro $erro) {
                error_log('[Deliveryzim] Codigo do cliente: falha no canal ' . $c->canal() . ' (' . get_class($erro) . '), tentando o proximo.');
            }
        }
        if ($canal === null) {
            return ['ok' => false, 'status' => 503, 'mensagem' => 'Não conseguimos enviar o código para este número. Confira o número e tente de novo.'];
        }
        Session::set(self::CHAVE, [
            'tel' => $telefone,
            'hash' => self::hash($codigo),
            'expira' => time() + self::VALIDADE,
            'tentativas' => 0,
            'enviado_em' => time(),
            'canal' => $canal,
        ]);
        return ['ok' => true, 'espera' => self::ESPERA_REENVIO, 'telefone' => Documento::formatarTelefone($telefone), 'canal' => $canal,
            'mensagem' => $canal === 'sms' ? 'Enviamos um código por SMS para o seu celular.' : 'Enviamos um código para o seu WhatsApp.'];
    }

    /** @return array{ok:bool, mensagem:string, telefone?:string, status?:int} telefone = digitos verificados */
    public function verificar(string $codigoDigitado): array
    {
        $atual = Session::get(self::CHAVE);
        if (!is_array($atual) || !isset($atual['tel'], $atual['hash'], $atual['expira'])) {
            return ['ok' => false, 'status' => 422, 'mensagem' => 'Peça um código para continuar.'];
        }
        if ((int) $atual['expira'] < time()) {
            Session::remover(self::CHAVE);
            return ['ok' => false, 'status' => 422, 'mensagem' => 'Este código venceu. Peça um novo.'];
        }
        $codigo = preg_replace('/\D/', '', $codigoDigitado) ?? '';
        if (strlen($codigo) !== 6) {
            return ['ok' => false, 'status' => 422, 'mensagem' => 'Digite os 6 números do código.'];
        }
        if (!hash_equals((string) $atual['hash'], self::hash($codigo))) {
            $atual['tentativas'] = (int) ($atual['tentativas'] ?? 0) + 1;
            if ($atual['tentativas'] >= self::MAX_TENTATIVAS) {
                Session::remover(self::CHAVE);
                return ['ok' => false, 'status' => 429, 'mensagem' => 'Código errado várias vezes. Peça um novo código.'];
            }
            Session::set(self::CHAVE, $atual);
            $restam = self::MAX_TENTATIVAS - $atual['tentativas'];
            return ['ok' => false, 'status' => 422, 'mensagem' => 'Código incorreto. Você ainda tem ' . $restam . ' tentativa' . ($restam > 1 ? 's' : '') . '.'];
        }
        Session::remover(self::CHAVE);
        return ['ok' => true, 'telefone' => (string) $atual['tel'], 'mensagem' => 'Número confirmado.'];
    }

    public static function mensagem(string $codigo): string
    {
        return "*{$codigo}* é o seu código para entrar no " . marca() . ".\n\nEle vale por 10 minutos. Não passe este código para ninguém, nem para quem disser que é do restaurante.";
    }

    private static function hash(string $codigo): string
    {
        return hash_hmac('sha256', $codigo, Cripto::derivarChave('cliente-codigo-v1'));
    }
}
