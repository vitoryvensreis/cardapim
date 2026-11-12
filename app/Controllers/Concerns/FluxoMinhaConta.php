<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Concerns;

use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Repositories\ContaRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Auth\DoisFatores;
use Cardapim\Services\Auth\LoginLembrado;
use Cardapim\Services\Gerencia\ContaService;
use Cardapim\Shared\Seguranca\Cripto;
use Cardapim\Shared\Seguranca\Totp;
use Cardapim\Shared\Validation\Documento;

/**
 * "Minha conta" da propria pessoa logada (dados, senha, verificacao em duas etapas, aparelhos e acessos),
 * usada pelo painel do gerente e pelo painel da plataforma. Quem usa ja conferiu o acesso (e o CSRF nos POST)
 * e fornece $db, $usuario, voltar(), tela(), contaUrl() e contaAjudaLogin().
 */
trait FluxoMinhaConta
{
    /** Endereco da Minha conta (com o complemento). */
    abstract protected function contaUrl(string $complemento = ''): string;

    /** Frase de como trocar usuario e e-mail (muda conforme o painel). */
    abstract protected function contaAjudaLogin(): string;

    private const SESSAO_TOTP = 'totp_configuracao';
    private const SESSAO_CODIGOS = 'codigos_recuperacao_novos';

    protected function contaMostrar(): Response
    {
        return $this->contaPagina([], []);
    }

    protected function contaDados(): Response
    {
        $resultado = (new ContaService($this->db, $this->usuario))->salvarDados($_POST);
        if ($resultado->ok) {
            return $this->voltar($this->contaUrl(), 'sucesso', $resultado->mensagem);
        }
        return $this->contaPagina(['dados' => $resultado->erros], ['dados' => $_POST], 422);
    }

    protected function contaSenha(): Response
    {
        $resultado = (new ContaService($this->db, $this->usuario))->trocarSenha(
            (string) ($_POST['senha_atual'] ?? ''),
            (string) ($_POST['senha_nova'] ?? ''),
            (string) ($_POST['senha_confirmacao'] ?? ''),
            self::contaSeletorAtual(),
        );
        if ($resultado->ok) {
            Session::renovarId();
            return $this->voltar($this->contaUrl() . '#senha', 'sucesso', $resultado->mensagem);
        }
        return $this->contaPagina(['senha' => $resultado->erros], [], 422);
    }

    /* ---------- Verificacao em duas etapas: aplicativo ---------- */

    protected function contaAppIniciar(): Response
    {
        $conta = $this->contaRegistro();
        if ($conta['dois_fatores'] !== DoisFatores::DESATIVADO) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'aviso', 'A verificação em duas etapas já está ligada.');
        }
        if (!Cripto::disponivel()) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'erro', 'O aplicativo autenticador não está disponível agora. Use o código por e-mail.');
        }
        $config = Session::get(self::SESSAO_TOTP);
        if (!is_array($config) || ($config['expira'] ?? 0) < time()) {
            $config = ['segredo' => Totp::gerarSegredo(), 'expira' => time() + 900];
            Session::set(self::SESSAO_TOTP, $config);
        }
        return $this->tela('minha-conta/app', 'minha-conta', 'Ligar aplicativo autenticador', [
            'segredo' => $config['segredo'],
            'uri' => Totp::uri($config['segredo'], (string) $conta['email']),
            'erro' => Session::flash('erro_totp'),
            'pageScripts' => ['js/forms.js', 'vendor/qrcode-generator/qrcode.js', 'js/minha-conta.js'],
        ]);
    }

    protected function contaAppConfirmar(): Response
    {
        $conta = $this->contaRegistro();
        $config = Session::get(self::SESSAO_TOTP);
        if (!is_array($config) || ($config['expira'] ?? 0) < time()) {
            Session::remover(self::SESSAO_TOTP);
            return $this->voltar($this->contaUrl('dois-fatores/app'), 'aviso', 'O tempo para configurar acabou. Escaneie o novo QR Code.');
        }
        if ($conta['dois_fatores'] !== DoisFatores::DESATIVADO) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'aviso', 'A verificação em duas etapas já está ligada.');
        }
        $codigos = (new DoisFatores($this->db))->ativarApp($conta, (string) $config['segredo'], (string) ($_POST['codigo'] ?? ''));
        if ($codigos === null) {
            Session::set('erro_totp', 'Código incorreto. Confira se digitou o código do ' . marca() . ' que aparece agora no aplicativo.');
            return Response::redirect($this->contaUrl('dois-fatores/app'), 303);
        }
        Session::remover(self::SESSAO_TOTP);
        Auditoria::registrar($this->usuario, 'Verificação em duas etapas ligada', 'Método: aplicativo autenticador. 10 códigos de recuperação gerados.');
        return $this->contaCodigosNovos($codigos, 'Pronto! A verificação em duas etapas está ligada.');
    }

    /* ---------- Verificacao em duas etapas: e-mail ---------- */

    protected function contaEmailEnviar(): Response
    {
        $conta = $this->contaRegistro();
        if ($conta['dois_fatores'] !== DoisFatores::DESATIVADO) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'aviso', 'A verificação em duas etapas já está ligada.');
        }
        $envio = (new DoisFatores($this->db))->enviarCodigoEmail($conta, 'ativar');
        if (!$envio['ok']) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'erro', $envio['mensagem']);
        }
        return $this->voltar($this->contaUrl('dois-fatores/email'), 'sucesso', $envio['mensagem']);
    }

    protected function contaEmailForm(): Response
    {
        $conta = $this->contaRegistro();
        if ($conta['dois_fatores'] !== DoisFatores::DESATIVADO) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'aviso', 'A verificação em duas etapas já está ligada.');
        }
        return $this->tela('minha-conta/email', 'minha-conta', 'Ligar código por e-mail', [
            'email' => DoisFatores::emailMascarado((string) $conta['email']),
            'erro' => Session::flash('erro_email2fa'),
            'pageScripts' => ['js/forms.js'],
        ]);
    }

    protected function contaEmailConfirmar(): Response
    {
        $conta = $this->contaRegistro();
        if ($conta['dois_fatores'] !== DoisFatores::DESATIVADO) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'aviso', 'A verificação em duas etapas já está ligada.');
        }
        $codigos = (new DoisFatores($this->db))->ativarEmail($conta, (string) ($_POST['codigo'] ?? ''));
        if ($codigos === null) {
            Session::set('erro_email2fa', 'Código incorreto ou vencido. Confira o e-mail ou peça um novo código.');
            return Response::redirect($this->contaUrl('dois-fatores/email'), 303);
        }
        Auditoria::registrar($this->usuario, 'Verificação em duas etapas ligada', 'Método: código por e-mail. 10 códigos de recuperação gerados.');
        return $this->contaCodigosNovos($codigos, 'Pronto! A verificação em duas etapas está ligada.');
    }

    /* ---------- Codigos, desativar ---------- */

    protected function contaCodigos(): Response
    {
        $novos = Session::flash(self::SESSAO_CODIGOS);
        if (!is_array($novos) || !is_array($novos['codigos'] ?? null)) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'aviso', 'Por segurança, os códigos só aparecem uma vez. Gere novos se precisar.');
        }
        return $this->tela('minha-conta/codigos', 'minha-conta', 'Códigos de recuperação', [
            'codigos' => $novos['codigos'],
            'titulo' => (string) ($novos['titulo'] ?? 'Seus códigos de recuperação'),
            'pageScripts' => ['js/minha-conta.js'],
        ]);
    }

    protected function contaNovosCodigos(): Response
    {
        $conta = $this->contaRegistro();
        if ($conta['dois_fatores'] === DoisFatores::DESATIVADO) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'erro', 'Ligue a verificação em duas etapas primeiro.');
        }
        if (!(new ContaService($this->db, $this->usuario))->senhaConfere($conta, (string) ($_POST['senha'] ?? ''))) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'erro', 'Senha incorreta. Os códigos não foram trocados.');
        }
        $codigos = (new DoisFatores($this->db))->novosCodigos($this->usuario->id);
        Auditoria::registrar($this->usuario, 'Códigos de recuperação trocados', 'Os códigos antigos deixaram de valer.');
        return $this->contaCodigosNovos($codigos, 'Novos códigos gerados. Os antigos não valem mais.');
    }

    protected function contaDesativar(): Response
    {
        $conta = $this->contaRegistro();
        if ($conta['dois_fatores'] === DoisFatores::DESATIVADO) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'aviso', 'A verificação em duas etapas já está desligada.');
        }
        if (!(new ContaService($this->db, $this->usuario))->senhaConfere($conta, (string) ($_POST['senha'] ?? ''))) {
            return $this->voltar($this->contaUrl() . '#duas-etapas', 'erro', 'Senha incorreta. A verificação em duas etapas continua ligada.');
        }
        (new DoisFatores($this->db))->desativar($this->usuario->id);
        Auditoria::registrar($this->usuario, 'Verificação em duas etapas desligada', 'Método anterior: ' . DoisFatores::rotulo((string) $conta['dois_fatores']) . '. Códigos de recuperação apagados.');
        return $this->voltar($this->contaUrl() . '#duas-etapas', 'sucesso', 'Verificação em duas etapas desligada. Sua conta volta a pedir só a senha.');
    }

    /* ---------- Aparelhos ---------- */

    protected function contaSairAparelho(string $idTexto): Response
    {
        $id = ctype_digit($idTexto) && strlen($idTexto) <= 18 ? (int) $idTexto : null;
        $repo = new ContaRepository($this->db);
        $aparelho = $id === null ? null : $repo->aparelho($this->usuario->id, $id);
        if ($aparelho === null) {
            return $this->voltar($this->contaUrl() . '#aparelhos', 'erro', 'Aparelho não encontrado. Ele pode já ter sido desconectado.');
        }
        $repo->removerAparelho($this->usuario->id, (int) $id);
        Auditoria::registrar($this->usuario, 'Aparelho desconectado', (string) ($aparelho['navegador'] ?: 'Aparelho sem identificação') . '.');
        return $this->voltar($this->contaUrl() . '#aparelhos', 'sucesso', 'Aparelho desconectado. Ele vai pedir a senha no próximo acesso.');
    }

    protected function contaSairOutros(): Response
    {
        $total = (new ContaRepository($this->db))->removerOutrosAparelhos($this->usuario->id, self::contaSeletorAtual());
        Auditoria::registrar($this->usuario, 'Aparelhos desconectados', $total . ' aparelho(s) desconectados de uma vez.');
        return $this->voltar($this->contaUrl() . '#aparelhos', 'sucesso', $total > 0 ? $total . ' aparelho(s) desconectados.' : 'Não havia outros aparelhos conectados.');
    }

    /* ---------- Internos ---------- */

    /** @param list<string> $codigos */
    protected function contaCodigosNovos(array $codigos, string $titulo): Response
    {
        Session::set(self::SESSAO_CODIGOS, ['codigos' => $codigos, 'titulo' => $titulo]);
        return Response::redirect($this->contaUrl('codigos'), 303);
    }

    /** @return array<string,mixed> */
    protected function contaRegistro(): array
    {
        return (new ContaRepository($this->db))->buscar($this->usuario->id) ?? throw new \RuntimeException('Conta nao encontrada.');
    }

    /** Seletor do cookie "manter conectado" deste navegador (para nao desconectar o aparelho atual). */
    protected static function contaSeletorAtual(): ?string
    {
        $cookie = $_COOKIE[LoginLembrado::COOKIE] ?? '';
        return is_string($cookie) && preg_match('/\A([a-f0-9]{24}):/', $cookie, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param array<string,array<string,string>> $erros por formulario
     * @param array<string,array<string,mixed>> $enviado
     */
    protected function contaPagina(array $erros, array $enviado, int $status = 200): Response
    {
        $repo = new ContaRepository($this->db);
        $conta = $this->contaRegistro();
        $dados = $enviado['dados'] ?? [
            'nome' => $conta['nome'],
            'sobrenome' => $conta['sobrenome'],
            'whatsapp' => $conta['whatsapp'] ? Documento::formatarTelefone((string) $conta['whatsapp']) : '',
        ];
        return $this->tela('minha-conta/index', 'minha-conta', 'Minha conta', [
            'conta' => $conta,
            'dados' => array_map(static fn ($v): string => is_string($v) ? $v : '', $dados),
            'erros' => $erros,
            'codigosRestantes' => $conta['dois_fatores'] === DoisFatores::DESATIVADO ? 0 : $repo->codigosRestantes($this->usuario->id),
            'aparelhos' => $repo->aparelhos($this->usuario->id, date('Y-m-d H:i:s')),
            'seletorAtual' => self::contaSeletorAtual(),
            'acessos' => $repo->ultimosAcessos($this->usuario->id),
            'appDisponivel' => Cripto::disponivel(),
            'ajudaLogin' => $this->contaAjudaLogin(),
            'pageScripts' => ['js/forms.js'],
        ], $status);
    }
}
