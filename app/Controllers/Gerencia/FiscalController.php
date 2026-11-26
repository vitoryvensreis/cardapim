<?php
declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Services\Gerencia\FiscalService;
use Cardapim\Services\Gerencia\FiscalAcesso;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\Resultado;
use Cardapim\Shared\Esquema;
use Throwable;

final class FiscalController extends BaseGerencia
{
    private function servico(): FiscalService
    {
        return new FiscalService($this->db, $this->usuario, $this->restaurante['id']);
    }

    public function inicio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) { return $r; }
        if (!FiscalAcesso::permitido($this->db,$this->restaurante['id'])) { return Guard::proibido($this->usuario,'Módulo indisponível para este restaurante.'); }
        $fiscal = null;
        if (Esquema::tem($this->db, 'fiscal_restaurante')) {
            try {
                $fiscal = $this->servico()->estado();
            } catch (Throwable $e) {
                error_log('[Cardapim] Fiscal restaurante: falha de leitura (' . get_class($e) . ').');
            }
        }
        if ($fiscal !== null) {
            $rascunho = Session::flash('fiscal_rascunho_' . $this->restaurante['id']);
            $fiscal['auto_consulta'] = !$fiscal['empresa_salva'] && !is_array($rascunho);
            if (is_array($rascunho)) { $fiscal['empresa'] = array_replace($fiscal['empresa'],$rascunho); }
        }
        return $this->tela('gerencia/fiscal/inicio', 'fiscal', 'Fiscal', ['fiscal' => $fiscal, 'fiscalProdutosDisponivel' => \Cardapim\Services\Gerencia\FiscalProdutosService::disponivel($this->db,$this->restaurante['id']), 'pageStyles' => ['css/gerencia-fiscal.css'], 'pageScripts' => ['js/gerencia-fiscal.js']]);
    }

    private function acao(string $slug, callable $fn): Response
    {
        if ($r = $this->entrar($slug, true)) { return $r; }
        if (!FiscalAcesso::permitido($this->db,$this->restaurante['id'])) { return Guard::proibido($this->usuario,'Módulo indisponível para este restaurante.'); }
        $url = $this->url('fiscal');
        if (!Esquema::tem($this->db, 'fiscal_restaurante')) {
            return $this->voltar($url, 'erro', 'A configuração fiscal ainda está sendo disponibilizada.');
        }
        try {
            return $this->responder($fn($this->servico()), $url);
        } catch (Throwable $e) {
            error_log('[Cardapim] Fiscal restaurante: falha na operação (' . get_class($e) . ($e instanceof \Cardapim\Modules\Integracoes\NextFiscalErro && $e->codigoFornecedor !== '' ? ' ' . $e->codigoFornecedor : '') . ').');
            return $this->voltar($url, 'erro', \Cardapim\Modules\Fiscal\MensagemFiscal::para($e, 'Não foi possível concluir. Confira os dados informados e tente novamente.'));
        }
    }

    public function consultarEmpresa(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) { return $r; }
        if (!FiscalAcesso::permitido($this->db,$this->restaurante['id'])) { return Guard::proibido($this->usuario,'Módulo indisponível para este restaurante.'); }
        $headers = ['Cache-Control'=>'no-store'];
        if (!Esquema::tem($this->db,'fiscal_restaurante')) {
            return Response::json(['ok'=>false],503,$headers);
        }
        try {
            return Response::json(['ok'=>true,'dados'=>$this->servico()->consultarEmpresa($this->marcado('atualizar'))],200,$headers);
        } catch (Throwable $e) {
            error_log('[Cardapim] Consulta cadastral fiscal indisponível (' . get_class($e) . ').');
            return Response::json(['ok'=>false,'mensagem'=>'Consulta indisponível. Preencha manualmente ou tente novamente.'],422,$headers);
        }
    }

    public function habilitar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->habilitar($this->marcado('habilitado'))); }
    public function empresa(string $slug): Response
    {
        return $this->acao($slug, function ($s) {
            $res = $s->empresa($_POST);
            if (!$res->ok) {
                $rascunho = [];
                foreach (['razao','nome','email','cep','logradouro','numero','complemento','bairro','cidade','estado','crt','ie','ie_situacao'] as $c) {
                    if (is_string($_POST[$c] ?? null)) { $rascunho[$c] = mb_substr($_POST[$c],0,160); }
                }
                Session::set('fiscal_rascunho_' . $this->restaurante['id'],$rascunho);
            }
            return $res;
        });
    }
    public function conectar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->conectar()); }
    public function ambiente(string $slug): Response { return $this->acao($slug, fn ($s) => $s->ambiente($this->post('ambiente'))); }
    public function configurar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->configurar($_POST)); }
    public function certificado(string $slug): Response {
        return $this->acao($slug, fn ($s) => $s->certificado(is_array($_FILES['certificado'] ?? null) ? $_FILES['certificado'] : [], is_string($_POST['senha'] ?? null) ? $_POST['senha'] : ''));
    }
    public function enviarConfig(string $slug): Response { return $this->acao($slug, fn ($s) => $s->enviarConfig()); }
    public function testar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->testar()); }
}
