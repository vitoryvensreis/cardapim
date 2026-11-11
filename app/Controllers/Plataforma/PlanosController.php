<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Config;
use Cardapim\Core\Response;
use Cardapim\Repositories\PlanoRepository;
use Cardapim\Services\Plataforma\PlanoService;
use Cardapim\Shared\Numero;

/** Planos da plataforma (/painel/planos): lista, criar, editar, pausar, link de convite e excluir. */
final class PlanosController extends BasePlataforma
{
    public function lista(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $planos = (new PlanoRepository($this->db))->listarTodos();
        foreach ($planos as &$p) {
            $p['link'] = $p['codigo_convite'] !== null ? self::linkConvite((string) $p['codigo_convite']) : null;
        }
        unset($p);
        return $this->tela('plataforma/planos/lista', 'planos', 'Planos', ['planos' => $planos, 'pageScripts' => ['js/planos.js']]);
    }

    public function novo(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        return $this->formulario(null, ['periodo' => 'mensal', 'visibilidade' => 'publico', 'ativo' => '1']);
    }

    public function editar(string $id): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $plano = $this->plano($id);
        if ($plano === null) {
            return $this->voltar('/painel/planos', 'erro', 'Plano não encontrado.');
        }
        return $this->formulario($plano, [
            'nome' => (string) $plano['nome'],
            'descricao' => (string) ($plano['descricao'] ?? ''),
            'valor' => Numero::dinheiroCampo((string) $plano['valor']),
            'periodo' => (string) $plano['periodo'],
            'visibilidade' => (int) $plano['visibilidade'] === 1 ? 'publico' : 'privado',
            'ativo' => (int) $plano['ativo'] === 1 ? '1' : '',
        ]);
    }

    public function criar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $res = (new PlanoService($this->db, $this->usuario))->salvar(null, $_POST);
        if (!$res->ok) {
            return $this->formulario(null, $_POST, $res->erros, 422);
        }
        return $this->responder($res, '/painel/planos#plano-' . (int) $res->id);
    }

    public function atualizar(string $id): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $plano = $this->plano($id);
        if ($plano === null) {
            return $this->voltar('/painel/planos', 'erro', 'Plano não encontrado.');
        }
        $res = (new PlanoService($this->db, $this->usuario))->salvar((int) $plano['id'], $_POST);
        if (!$res->ok) {
            return $this->formulario($plano, $_POST, $res->erros, 422);
        }
        return $this->responder($res, '/painel/planos#plano-' . (int) $plano['id']);
    }

    public function convite(string $id): Response
    {
        return $this->acao($id, 'novoLink');
    }

    public function alternar(string $id): Response
    {
        return $this->acao($id, 'alternar');
    }

    public function excluir(string $id): Response
    {
        return $this->acao($id, 'excluir');
    }

    /** Link completo do convite (APP_URL + /cadastro?convite=...), para copiar e mandar ao restaurante. */
    public static function linkConvite(string $codigo): string
    {
        $base = rtrim(Config::get('APP_URL'), '/');
        return (preg_match('~\Ahttps?://~', $base) === 1 ? $base : '') . '/cadastro?convite=' . $codigo;
    }

    private function acao(string $id, string $metodo): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $plano = $this->plano($id);
        if ($plano === null) {
            return $this->voltar('/painel/planos', 'erro', 'Plano não encontrado.');
        }
        $res = (new PlanoService($this->db, $this->usuario))->{$metodo}((int) $plano['id']);
        return $this->responder($res, '/painel/planos' . ($metodo === 'excluir' && $res->ok ? '' : '#plano-' . (int) $plano['id']));
    }

    /** @return array<string,mixed>|null */
    private function plano(string $id): ?array
    {
        return ctype_digit($id) && strlen($id) <= 18 ? (new PlanoRepository($this->db))->buscar((int) $id) : null;
    }

    /**
     * @param array<string,mixed>|null $plano
     * @param array<string,mixed> $valores
     * @param array<string,string> $erros
     */
    private function formulario(?array $plano, array $valores, array $erros = [], int $status = 200): Response
    {
        return $this->tela('plataforma/planos/form', 'planos', $plano === null ? 'Novo plano' : 'Editar plano', [
            'plano' => $plano,
            'valores' => $valores,
            'erros' => $erros,
            'link' => $plano !== null && $plano['codigo_convite'] !== null ? self::linkConvite((string) $plano['codigo_convite']) : null,
            'pageScripts' => ['js/forms.js', 'js/planos.js'],
        ], $status);
    }
}
