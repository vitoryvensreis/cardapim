<?php
declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Services\Plataforma\TagsService;

final class TagsController extends BasePlataforma
{
    public function inicio(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->formulario(TagsService::atuais());
    }

    public function salvar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $entrada = $_POST['tags'] ?? null;
        if (!is_array($entrada)) {
            return $this->voltar('/painel/tags', 'erro', 'Configuração inválida.');
        }
        $res = (new TagsService($this->db, $this->usuario))->salvar($entrada);
        if (!$res->ok) {
            [$dados] = TagsService::validar($entrada);
            return $this->formulario($dados, $res->erros, 422);
        }
        return $this->responder($res, '/painel/tags');
    }

    private function formulario(array $dados, array $erros = [], int $status = 200): Response
    {
        return $this->tela('plataforma/tags', 'tags', 'Tags e rastreamento', [
            'tags' => $dados,
            'erros' => $erros,
            'pageStyles' => ['css/plataforma-tags.css'],
        ], $status);
    }
}
