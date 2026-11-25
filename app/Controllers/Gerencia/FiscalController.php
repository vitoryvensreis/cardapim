<?php
declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\FiscalService;
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
        $fiscal = null;
        if (Esquema::tem($this->db, 'fiscal_restaurante')) {
            try {
                $fiscal = $this->servico()->estado();
            } catch (Throwable $e) {
                error_log('[Cardapim] Fiscal restaurante: falha de leitura (' . get_class($e) . ').');
            }
        }
        return $this->tela('gerencia/fiscal/inicio', 'fiscal', 'Fiscal', ['fiscal' => $fiscal, 'pageStyles' => ['css/gerencia-fiscal.css']]);
    }

    private function acao(string $slug, callable $fn): Response
    {
        if ($r = $this->entrar($slug, true)) { return $r; }
        $url = $this->url('fiscal');
        if (!Esquema::tem($this->db, 'fiscal_restaurante')) {
            return $this->voltar($url, 'erro', 'A configuração fiscal ainda está sendo disponibilizada.');
        }
        try {
            return $this->responder($fn($this->servico()), $url);
        } catch (Throwable $e) {
            error_log('[Cardapim] Fiscal restaurante: falha na operação (' . get_class($e) . ').');
            return $this->voltar($url, 'erro', 'Não foi possível concluir. Confira o vínculo, a conexão da plataforma e os dados informados. Tente novamente.');
        }
    }

    public function habilitar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->habilitar($this->marcado('habilitado'))); }
    public function empresa(string $slug): Response { return $this->acao($slug, fn ($s) => $s->empresa($_POST)); }
    public function conectar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->conectar()); }
    public function ambiente(string $slug): Response { return $this->acao($slug, fn ($s) => $s->ambiente($this->post('ambiente'))); }
    public function configurar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->configurar($_POST)); }
    public function certificado(string $slug): Response {
        return $this->acao($slug, fn ($s) => $s->certificado(is_array($_FILES['certificado'] ?? null) ? $_FILES['certificado'] : [], is_string($_POST['senha'] ?? null) ? $_POST['senha'] : ''));
    }
    public function enviarConfig(string $slug): Response { return $this->acao($slug, fn ($s) => $s->enviarConfig()); }
    public function testar(string $slug): Response { return $this->acao($slug, fn ($s) => $s->testar()); }
}
