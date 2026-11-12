<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Controllers\Concerns\FluxoMinhaConta;
use Cardapim\Core\Response;

/** Minha conta do administrador da plataforma (/painel/minha-conta): dados, senha, verificacao em duas etapas, aparelhos e acessos. */
final class MinhaContaController extends BasePlataforma
{
    use FluxoMinhaConta;

    public function mostrar(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->contaMostrar();
    }

    public function dados(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaDados();
    }

    public function senha(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaSenha();
    }

    public function appIniciar(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->contaAppIniciar();
    }

    public function appConfirmar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaAppConfirmar();
    }

    public function emailEnviar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaEmailEnviar();
    }

    public function emailForm(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->contaEmailForm();
    }

    public function emailConfirmar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaEmailConfirmar();
    }

    public function codigos(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->contaCodigos();
    }

    public function novosCodigos(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaNovosCodigos();
    }

    public function desativar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaDesativar();
    }

    public function sairAparelho(string $idTexto): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaSairAparelho($idTexto);
    }

    public function sairOutros(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        return $this->contaSairOutros();
    }

    protected function contaUrl(string $complemento = ''): string
    {
        return '/painel/minha-conta' . ($complemento !== '' ? '/' . $complemento : '');
    }

    protected function contaAjudaLogin(): string
    {
        return 'Para trocar o usuário ou o e-mail, peça a outro administrador em Usuários.';
    }
}
