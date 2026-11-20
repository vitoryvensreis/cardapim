<?php

declare(strict_types=1);

namespace Cardapim\Services\Plataforma;

use Cardapim\Core\Config;
use Cardapim\Core\RateLimiter;
use Cardapim\Modules\Integracoes\IntegracaoErro;
use Cardapim\Repositories\Plataforma\SmsGatewayRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\UsuarioLogado;
use Cardapim\Services\Cliente\SmsPlataforma;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Http\ClienteHttp;
use Cardapim\Shared\Validation\Documento;
use PDO;

/**
 * SMS da plataforma (/painel/integracoes/sms): endereco do SMSGate + usuario e senha do app no celular.
 * A senha nunca volta para a tela e nunca vai para os logs. Mensagem de teste limitada a 10 por hora.
 */
final class SmsGatewayService
{
    public const TESTES_POR_HORA = 10;
    private const CAMINHO_API = '/api/3rdparty/v1';

    private readonly SmsGatewayRepository $repo;

    public function __construct(private readonly PDO $db, private readonly UsuarioLogado $usuario, private readonly ?ClienteHttp $http = null)
    {
        $this->repo = new SmsGatewayRepository($db);
    }

    /** @return array{origem:string, url:string, usuario:string, salvo_em:string, salvo_por:string, ultimo_teste:string} origem: painel | env | '' */
    public function resumo(): array
    {
        $salvo = $this->repo->buscar();
        if ($salvo !== null) {
            return ['origem' => 'painel', 'url' => $salvo['url'], 'usuario' => $salvo['usuario'], 'salvo_em' => $salvo['salvo_em'],
                'salvo_por' => $salvo['salvo_por'], 'ultimo_teste' => $salvo['ultimo_teste']];
        }
        $env = Config::get('SMSGATE_URL') !== '' && Config::get('SMSGATE_USUARIO') !== '' && Config::get('SMSGATE_SENHA') !== '';
        return ['origem' => $env ? 'env' : '', 'url' => $env ? Config::get('SMSGATE_URL') : '', 'usuario' => '', 'salvo_em' => '', 'salvo_por' => '', 'ultimo_teste' => ''];
    }

    /** Endereco so com o dominio ganha o caminho da API sozinho. Senha em branco = mantem a salva. */
    public function salvar(string $url, string $usuario, string $senha): Resultado
    {
        $url = rtrim(trim($url), '/');
        $usuario = trim($usuario);
        $senha = trim($senha);
        $atual = $this->repo->buscar();
        $erros = [];
        $partes = parse_url($url);
        $local = in_array(strtolower((string) ($partes['host'] ?? '')), ['127.0.0.1', 'localhost'], true); // so testes/desenvolvimento
        if ($url === '' || !is_array($partes) || !(($partes['scheme'] ?? '') === 'https' || ($local && ($partes['scheme'] ?? '') === 'http')) || !isset($partes['host'])
            || preg_match('/\A[a-z0-9.-]+\z/i', (string) $partes['host']) !== 1 || isset($partes['user']) || isset($partes['query']) || isset($partes['fragment'])) {
            $erros['url'] = 'Digite o endereço com https://. Exemplo: https://sms.escotilhaprodutora.com';
        } elseif (($partes['path'] ?? '') === '') {
            $url .= self::CAMINHO_API;
        }
        if (preg_match('/\A\S{3,100}\z/', $usuario) !== 1) {
            $erros['usuario'] = 'Cole o usuário que aparece no app, em Settings > Cloud Server.';
        }
        if ($senha === '' && $atual === null) {
            $erros['senha'] = 'Cole a senha que aparece no app, em Settings > Cloud Server.';
        } elseif ($senha !== '' && preg_match('/\A\S{4,200}\z/', $senha) !== 1) {
            $erros['senha'] = 'Confira a senha: sem espaços, com pelo menos 4 caracteres.';
        }
        if ($erros !== []) {
            return Resultado::falha('Confira os campos destacados.', $erros);
        }
        $this->repo->salvar([
            'url' => $url,
            'usuario' => $usuario,
            'senha' => $senha !== '' ? $senha : (string) $atual['senha'],
            'salvo_por' => trim($this->usuario->nome . ' ' . $this->usuario->sobrenome),
            'ultimo_teste' => $atual !== null && $atual['url'] === $url && $atual['usuario'] === $usuario && $senha === '' ? $atual['ultimo_teste'] : '',
        ]);
        Auditoria::registrar($this->usuario, 'SMS da plataforma configurado', implode('; ', [
            $atual === null ? 'Configurado pela primeira vez' : 'Configuração atualizada',
            'Endereço: ' . $url,
            $senha !== '' ? 'Senha ' . ($atual === null ? 'cadastrada' : 'trocada') : 'Senha mantida',
        ]));
        return Resultado::sucesso('SMS configurado. Envie uma mensagem de teste para conferir.');
    }

    public function testar(string $telefone): Resultado
    {
        $digitos = Documento::normalizarTelefone($telefone);
        if ($digitos === null) {
            return Resultado::falha('Confira os campos destacados.', ['telefone' => 'Digite o número com DDD. Exemplo: (85) 99999-0000']);
        }
        $api = SmsPlataforma::api($this->db, $this->http);
        if (!$api->configurado()) {
            return Resultado::falha('Configure o SMS antes de enviar a mensagem de teste.');
        }
        $limite = RateLimiter::padrao(self::TESTES_POR_HORA, 3600);
        if ($limite->excedido('sms-teste|plataforma')) {
            return Resultado::falha('Você já enviou muitas mensagens de teste nesta hora. Tente mais tarde.');
        }
        try {
            $api->enviar('55' . $digitos, 'Teste do SMS do ' . marca() . '. Se voce recebeu esta mensagem, o envio por SMS esta funcionando.', 600);
        } catch (IntegracaoErro $erro) {
            return Resultado::falha($erro->getMessage());
        }
        $limite->permitir('sms-teste|plataforma');
        $salvo = $this->repo->buscar();
        if ($salvo !== null) {
            $this->repo->salvar([...$salvo, 'ultimo_teste' => date('Y-m-d H:i:s')]);
        }
        Auditoria::registrar($this->usuario, 'SMS da plataforma: mensagem de teste', 'Para o número com final ' . substr($digitos, -4) . '.');
        return Resultado::sucesso('SMS enviado para ' . Documento::formatarTelefone($digitos) . '. Confira no celular em alguns segundos.');
    }

    public function remover(): Resultado
    {
        if ($this->repo->buscar() === null) {
            return Resultado::falha('Não há configuração de SMS salva no painel.');
        }
        $this->repo->remover();
        Auditoria::registrar($this->usuario, 'SMS da plataforma removido', 'Configuração apagada do painel.');
        $env = Config::get('SMSGATE_URL') !== '' && Config::get('SMSGATE_USUARIO') !== '' && Config::get('SMSGATE_SENHA') !== '';
        return Resultado::sucesso('Configuração do SMS apagada.' . ($env ? ' O sistema volta a usar a do arquivo de configuração do servidor.' : ' Os códigos voltam a ir só pelo WhatsApp.'));
    }
}
