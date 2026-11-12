<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Controllers\Concerns\FluxoMinhaConta;
use Cardapim\Core\Response;

/** Minha conta do gerente: dados, senha, verificacao em duas etapas e aparelhos conectados (fluxo em FluxoMinhaConta). */
final class MinhaContaController extends BaseGerencia
{
    use FluxoMinhaConta;

    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->contaMostrar();
    }

    public function dados(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaDados();
    }

    public function senha(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaSenha();
    }

    public function appIniciar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->contaAppIniciar();
    }

    public function appConfirmar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaAppConfirmar();
    }

    public function emailEnviar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaEmailEnviar();
    }

    public function emailForm(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->contaEmailForm();
    }

    public function emailConfirmar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaEmailConfirmar();
    }

    public function codigos(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return $this->contaCodigos();
    }

    public function novosCodigos(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaNovosCodigos();
    }

    public function desativar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaDesativar();
    }

    public function sairAparelho(string $slug, string $idTexto): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaSairAparelho($idTexto);
    }

    public function sairOutros(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->contaSairOutros();
    }

    protected function contaUrl(string $complemento = ''): string
    {
        return $this->url('minha-conta' . ($complemento !== '' ? '/' . $complemento : ''));
    }

    protected function contaAjudaLogin(): string
    {
        return 'Para trocar o usuário ou o e-mail, fale com o suporte.';
    }
}
