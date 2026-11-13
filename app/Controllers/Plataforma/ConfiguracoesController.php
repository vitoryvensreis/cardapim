<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Email\Mailer;
use Cardapim\Services\Plataforma\MarcaService;
use Cardapim\Services\Seo\Seo;

/** Configuracoes da plataforma (/painel/configuracoes): nome, textos e logos usados em todo o sistema, e o teste do e-mail. */
final class ConfiguracoesController extends BasePlataforma
{
    public function inicio(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->formulario();
    }

    public function textos(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $res = (new MarcaService($this->db, $this->usuario))->salvarTextos($_POST);
        if (!$res->ok) {
            return $this->formulario($_POST, $res->erros, 422);
        }
        return $this->responder($res, '/painel/configuracoes');
    }

    public function logo(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $chave = $this->post('chave');
        $arquivo = is_array($_FILES['arquivo'] ?? null) ? $_FILES['arquivo'] : [];
        $res = (new MarcaService($this->db, $this->usuario))->enviarLogo($chave, $arquivo);
        return $this->responder($res, '/painel/configuracoes#logo-' . preg_replace('/[^a-z_]/', '', $chave));
    }

    public function logoPadrao(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $chave = $this->post('chave');
        $res = (new MarcaService($this->db, $this->usuario))->voltarPadrao($chave);
        return $this->responder($res, '/painel/configuracoes#logo-' . preg_replace('/[^a-z_]/', '', $chave));
    }

    /** Titulo, descricao e frase do link e o codigo do Google (Google e redes sociais). */
    public function seo(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $res = (new MarcaService($this->db, $this->usuario))->salvarSeo($_POST);
        if (!$res->ok) {
            return $this->formulario($_POST, $res->erros, 422);
        }
        return $this->responder($res, '/painel/configuracoes#seo');
    }

    /** Imagem do link compartilhado enviada pelo adm (recortada para 1200 x 630). */
    public function compartilhamento(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $arquivo = is_array($_FILES['arquivo'] ?? null) ? $_FILES['arquivo'] : [];
        $res = (new MarcaService($this->db, $this->usuario))->enviarImagemCompartilhamento($arquivo);
        return $this->responder($res, '/painel/configuracoes#seo');
    }

    public function compartilhamentoPadrao(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $res = (new MarcaService($this->db, $this->usuario))->voltarImagemAutomatica();
        return $this->responder($res, '/painel/configuracoes#seo');
    }

    /** Manda um e-mail de teste e mostra o resultado (o motivo da falha nunca traz a senha). */
    public function emailTeste(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $para = mb_substr($this->post('para'), 0, 190);
        if (filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
            return $this->voltar('/painel/configuracoes#email', 'erro', 'Digite um e-mail válido para receber o teste.');
        }
        if (!RateLimiter::padrao(5, 600)->permitir('email-teste|' . $this->usuario->id)) {
            return $this->voltar('/painel/configuracoes#email', 'erro', 'Muitos testes seguidos. Espere alguns minutos e tente de novo.');
        }
        $ok = Mailer::enviar($para, 'Teste de e-mail · ' . marca(),
            "Olá!\n\nEste é um e-mail de teste da administração " . marca() . ".\nSe ele chegou, os avisos por e-mail (chamados, recuperação de senha e códigos de acesso) estão funcionando.\n\nEnviado em " . date('d/m/Y H:i') . '.');
        Auditoria::registrar($this->usuario, 'E-mail de teste', ($ok ? 'Enviado para ' : 'Falhou para ') . $para . '.');
        if (!$ok) {
            Session::set('email_teste_erro', mb_substr((string) (Mailer::ultimoErro() ?? 'Falha desconhecida.'), 0, 300));
            return $this->voltar('/painel/configuracoes#email', 'erro', 'O e-mail de teste não foi enviado. Veja o motivo em E-mail do sistema.');
        }
        return $this->voltar('/painel/configuracoes#email', 'sucesso', 'E-mail de teste enviado para ' . $para . '. Confira a caixa de entrada e o spam.');
    }

    /**
     * @param array<string,mixed>|null $valores
     * @param array<string,string> $erros
     */
    private function formulario(?array $valores = null, array $erros = [], int $status = 200): Response
    {
        $atuais = (new MarcaService($this->db, $this->usuario))->atuais();
        return $this->tela('plataforma/configuracoes', 'configuracoes', 'Configurações', [
            'atuais' => $atuais,
            // Com erro, mostra o que foi digitado no formulario enviado e o salvo nos outros.
            'valores' => $valores !== null ? array_merge($atuais, $valores) : $atuais,
            'previaImagem' => self::caminhoLocal(Seo::imagemPlataforma()['url']),
            'imagemEnviada' => Seo::imagemEnviada() !== null,
            'dominio' => Seo::dominio(),
            'erros' => $erros,
            'email' => Mailer::configuracao(),
            'emailErro' => Session::flash('email_teste_erro'),
            'emailPara' => $this->emailDoUsuario(),
            'pageStyles' => ['css/plataforma-gestao.css'],
            'pageScripts' => ['js/forms.js', 'js/marca.js'],
        ], $status);
    }

    /** "https://site/midia/cartao?v=1" -> "/midia/cartao?v=1" (a previa funciona em qualquer endereco do painel). */
    private static function caminhoLocal(string $url): string
    {
        $caminho = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        return ($caminho !== '' ? $caminho : '/') . (is_string($query) ? '?' . $query : '');
    }

    private function emailDoUsuario(): string
    {
        $q = $this->db->prepare('SELECT email FROM `user` WHERE id = ?');
        $q->execute([$this->usuario->id]);
        $email = (string) $q->fetchColumn();
        return str_ends_with($email, '.invalid') ? '' : $email;
    }
}
