<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\RateLimiter;
use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Services\Auditoria;
use Cardapim\Services\Email\Mailer;
use Cardapim\Services\Plataforma\MarcaService;

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
            'valores' => $valores ?? $atuais,
            'erros' => $erros,
            'email' => Mailer::configuracao(),
            'emailErro' => Session::flash('email_teste_erro'),
            'emailPara' => $this->emailDoUsuario(),
            'pageStyles' => ['css/plataforma-gestao.css'],
            'pageScripts' => ['js/forms.js', 'js/marca.js'],
        ], $status);
    }

    private function emailDoUsuario(): string
    {
        $q = $this->db->prepare('SELECT email FROM `user` WHERE id = ?');
        $q->execute([$this->usuario->id]);
        $email = (string) $q->fetchColumn();
        return str_ends_with($email, '.invalid') ? '' : $email;
    }
}
