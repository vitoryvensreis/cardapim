<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Services\Plataforma\AvisosLojaService;

/** Avisos para as lojas (/painel/avisos): pop-up para os gerentes, com periodo, vezes por dia e botao de acao. */
final class AvisosLojaController extends BasePlataforma
{
    public function lista(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        if (!AvisosLojaService::disponivel($this->db)) {
            return $this->tela('plataforma/avisos/lista', 'avisos', 'Avisos para as lojas', ['avisos' => [], 'indisponivel' => true, 'pageStyles' => ['css/aviso-loja.css']]);
        }
        return $this->tela('plataforma/avisos/lista', 'avisos', 'Avisos para as lojas', [
            'avisos' => (new AvisosLojaService($this->db))->listar(),
            'indisponivel' => false,
            'pageStyles' => ['css/aviso-loja.css'],
        ]);
    }

    public function novo(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        if (!AvisosLojaService::disponivel($this->db)) {
            return $this->voltar('/painel/avisos', 'erro', 'Os avisos ainda não foram liberados neste servidor.');
        }
        $hoje = new \DateTimeImmutable('now');
        return $this->formulario(null, [
            'inicio' => $hoje->format('Y-m-d\TH:i'),
            'fim' => $hoje->modify('+7 days')->setTime(23, 59)->format('Y-m-d\TH:i'),
            'vezes_por_dia' => '1',
            'botao_destino' => 'whatsapp',
        ]);
    }

    public function editar(string $id): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $aviso = $this->aviso($id);
        if ($aviso === null) {
            return $this->voltar('/painel/avisos', 'erro', 'Aviso não encontrado.');
        }
        return $this->formulario($aviso, [
            'titulo' => (string) $aviso['titulo'],
            'texto' => (string) ($aviso['texto'] ?? ''),
            'botao_texto' => (string) ($aviso['botao_texto'] ?? ''),
            'botao_destino' => (string) ($aviso['botao_destino'] ?? ''),
            'botao_url' => (string) ($aviso['botao_url'] ?? ''),
            'inicio' => date('Y-m-d\TH:i', (int) strtotime((string) $aviso['inicio'])),
            'fim' => date('Y-m-d\TH:i', (int) strtotime((string) $aviso['fim'])),
            'vezes_por_dia' => (string) $aviso['vezes_por_dia'],
        ]);
    }

    public function criar(): Response
    {
        return $this->gravar(null);
    }

    public function atualizar(string $id): Response
    {
        return $this->gravar($id);
    }

    public function alternar(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $aviso = $this->aviso($id);
        if ($aviso === null) {
            return $this->voltar('/painel/avisos', 'erro', 'Aviso não encontrado.');
        }
        return $this->responder((new AvisosLojaService($this->db, $this->usuario))->alternar((int) $aviso['id']), '/painel/avisos#aviso-' . (int) $aviso['id']);
    }

    public function excluir(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $aviso = $this->aviso($id);
        if ($aviso === null) {
            return $this->voltar('/painel/avisos', 'erro', 'Aviso não encontrado.');
        }
        return $this->responder((new AvisosLojaService($this->db, $this->usuario))->excluir((int) $aviso['id']), '/painel/avisos');
    }

    private function gravar(?string $idTexto): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        if (!AvisosLojaService::disponivel($this->db)) {
            return $this->voltar('/painel/avisos', 'erro', 'Os avisos ainda não foram liberados neste servidor.');
        }
        $aviso = null;
        if ($idTexto !== null) {
            $aviso = $this->aviso($idTexto);
            if ($aviso === null) {
                return $this->voltar('/painel/avisos', 'erro', 'Aviso não encontrado.');
            }
        }
        $imagem = is_array($_FILES['imagem'] ?? null) ? $_FILES['imagem'] : null;
        $res = (new AvisosLojaService($this->db, $this->usuario))->salvar(
            $aviso !== null ? (int) $aviso['id'] : null,
            $_POST,
            $imagem,
            ($_POST['remover_imagem'] ?? '') === '1',
        );
        if (!$res->ok) {
            return $this->formulario($aviso, $_POST, $res->erros, 422);
        }
        return $this->responder($res, '/painel/avisos#aviso-' . (int) $res->id);
    }

    /** @param array<string,mixed>|null $aviso @param array<string,mixed> $valores @param array<string,string> $erros */
    private function formulario(?array $aviso, array $valores, array $erros = [], int $status = 200): Response
    {
        return $this->tela('plataforma/avisos/form', 'avisos', $aviso === null ? 'Novo aviso' : 'Editar aviso', [
            'aviso' => $aviso,
            'valores' => $valores,
            'erros' => $erros,
            'pageStyles' => ['css/popup-gerencia.css', 'css/aviso-loja.css'],
            'pageScripts' => ['js/forms.js', 'js/avisos-loja-form.js'],
        ], $status);
    }

    /** @return array<string,mixed>|null */
    private function aviso(string $id): ?array
    {
        if (!ctype_digit($id) || !AvisosLojaService::disponivel($this->db)) {
            return null;
        }
        return (new AvisosLojaService($this->db))->buscar((int) $id);
    }
}
