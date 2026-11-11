<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Services\Plataforma\MarcaService;

/** Configuracoes da plataforma (/painel/configuracoes): nome, textos e logos usados em todo o sistema. */
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
            'pageScripts' => ['js/forms.js', 'js/marca.js'],
        ], $status);
    }
}
