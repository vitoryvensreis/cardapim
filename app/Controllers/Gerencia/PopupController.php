<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Services\Gerencia\PopupService;
use Cardapim\Shared\MidiaUrl;

/** Clientes > Popup do cardapio: banner 1:1 ou texto que abre quando o cliente entra no link de pedidos. */
final class PopupController extends BaseGerencia
{
    public function mostrar(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $c = $this->servico()->config();
        return $this->pagina([
            'ativo' => $c['ativo'] ? '1' : '', 'tipo' => $c['tipo'], 'titulo' => $c['titulo'], 'texto' => $c['texto'],
            'inicio' => (string) ($c['inicio'] ?? ''), 'fim' => (string) ($c['fim'] ?? ''),
        ], []);
    }

    public function salvar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $arquivo = is_array($_FILES['imagem'] ?? null) ? $_FILES['imagem'] : ['error' => UPLOAD_ERR_NO_FILE];
        $res = $this->servico()->salvar($_POST, $arquivo);
        if ($res->ok) {
            return $this->voltar($this->url('popup'), 'sucesso', $res->mensagem);
        }
        $enviado = [];
        foreach (['ativo' => 2, 'tipo' => 10, 'titulo' => 80, 'texto' => 400, 'inicio' => 10, 'fim' => 10] as $campo => $max) {
            $enviado[$campo] = mb_substr(is_string($_POST[$campo] ?? null) ? $_POST[$campo] : '', 0, $max);
        }
        return $this->pagina($enviado, $res->erros !== [] ? $res->erros : ['geral' => $res->mensagem], 422);
    }

    public function removerImagem(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        return $this->responder($this->servico()->removerImagem(), $this->url('popup'));
    }

    /** @param array<string,string> $valores @param array<string,string> $erros */
    private function pagina(array $valores, array $erros, int $status = 200): Response
    {
        $s = $this->servico();
        $c = $s->config();
        return $this->tela('gerencia/popup', 'popup', 'Popup do cardápio', [
            'valores' => $valores,
            'erros' => $erros,
            'config' => $c,
            'disponivel' => $s->disponivel(),
            'visivelHoje' => PopupService::visivel($c, date('Y-m-d')),
            'imagemAtual' => $c['imagem_path'] !== null ? MidiaUrl::publica($c['imagem_path']) : null,
            'linkCardapio' => '/' . rawurlencode($this->restaurante['slug']),
            'pageScripts' => ['js/forms.js', 'js/popup-gerencia.js'],
            'pageStyles' => ['css/popup-gerencia.css'],
        ], $status);
    }

    private function servico(): PopupService
    {
        return new PopupService($this->db, $this->restaurante['id'], $this->usuario);
    }
}
