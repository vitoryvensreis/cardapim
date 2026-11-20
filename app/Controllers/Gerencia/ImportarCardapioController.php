<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Modules\Catalog\LeitorPlanilha;
use Cardapim\Modules\Catalog\PlanilhaCardapio;
use Cardapim\Services\Auth\Guard;
use Cardapim\Services\Gerencia\ImportarCardapioService;
use Cardapim\Shared\Planilha;
use RuntimeException;

/**
 * Importar cardapio por planilha (Gerencia > Integracoes). Fluxo em duas etapas:
 * enviar a planilha -> conferir o que vai entrar (nada gravado) -> confirmar a importacao.
 * O plano conferido fica na sessao do gerente, preso ao restaurante, por 30 minutos.
 */
final class ImportarCardapioController extends BaseGerencia
{
    private const SESSAO = 'importar_cardapio';
    private const VALIDADE = 1800;

    public function inicio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $guardado = $this->guardado();
        $dados = ['plano' => null, 'conferencia' => null, 'arquivo' => null, 'codigo' => null];
        if ($guardado !== null) {
            $dados = [
                'plano' => $guardado['plano'],
                'conferencia' => (new ImportarCardapioService($this->db, $this->usuario, $this->restaurante['id']))->conferir($guardado['plano']),
                'arquivo' => $guardado['arquivo'],
                'codigo' => $guardado['codigo'],
            ];
        }
        return $this->tela('gerencia/integracoes/importar-cardapio', 'integracoes', 'Importar cardápio', $dados + [
            'xlsxDisponivel' => class_exists(\ZipArchive::class),
            'pageScripts' => ['js/forms.js'],
        ]);
    }

    /** Modelo em CSV (abre no Excel e no Google Planilhas). */
    public function modelo(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        return Guard::semCache(new Response(Planilha::csv(PlanilhaCardapio::modelo()), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modelo-cardapio-' . $this->restaurante['slug'] . '.csv"',
        ]));
    }

    public function conferir(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $voltar = $this->url('integracoes/importar-cardapio');
        $arquivo = $_FILES['planilha'] ?? null;
        $erroEnvio = is_array($arquivo) ? (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($erroEnvio === UPLOAD_ERR_NO_FILE) {
            return $this->voltar($voltar, 'erro', 'Escolha a planilha antes de enviar.');
        }
        if (in_array($erroEnvio, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->voltar($voltar, 'erro', 'A planilha passou de 2 MB. Apague abas e imagens que não são do cardápio e envie de novo.');
        }
        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        if ($erroEnvio !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return $this->voltar($voltar, 'erro', 'A planilha não chegou inteira. Tente enviar de novo.');
        }
        $nome = mb_substr(basename(str_replace('\\', '/', (string) ($arquivo['name'] ?? 'planilha'))), 0, 120);
        try {
            $plano = PlanilhaCardapio::interpretar(LeitorPlanilha::ler($tmp, $nome));
        } catch (RuntimeException $erro) {
            return $this->voltar($voltar, 'erro', $erro->getMessage());
        } finally {
            @unlink($tmp);
        }
        Session::set(self::SESSAO, [
            'restaurante' => (int) $this->restaurante['id'],
            'usuario' => $this->usuario->id,
            'criado' => time(),
            'arquivo' => $nome,
            'codigo' => bin2hex(random_bytes(8)),
            'plano' => $plano,
        ]);
        return Response::redirect($voltar . '#conferencia', 303);
    }

    public function importar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $voltar = $this->url('integracoes/importar-cardapio');
        $guardado = $this->guardado();
        if ($guardado === null || !hash_equals((string) $guardado['codigo'], $this->post('codigo'))) {
            return $this->voltar($voltar, 'erro', 'A conferência expirou ou foi trocada por outra planilha. Envie a planilha de novo.');
        }
        $resultado = (new ImportarCardapioService($this->db, $this->usuario, $this->restaurante['id']))->importar($guardado['plano'], (string) $guardado['arquivo']);
        if (!$resultado->ok) {
            return $this->voltar($voltar . '#conferencia', 'erro', $resultado->mensagem);
        }
        Session::remover(self::SESSAO);
        $this->avisar($resultado, 'estoque', 'estoque.alterado', ['area' => 'produto', 'acao' => 'importado', 'id' => null]);
        return $this->voltar($this->url('estoque/produtos'), 'sucesso', $resultado->mensagem);
    }

    public function descartar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        Session::remover(self::SESSAO);
        return $this->voltar($this->url('integracoes/importar-cardapio'), 'sucesso', 'Conferência descartada. Nada foi importado.');
    }

    /** @return array{restaurante:int, usuario:int, criado:int, arquivo:string, codigo:string, plano:array<string,mixed>}|null */
    private function guardado(): ?array
    {
        $g = Session::get(self::SESSAO);
        if (!is_array($g) || ($g['restaurante'] ?? null) !== (int) $this->restaurante['id'] || ($g['usuario'] ?? null) !== $this->usuario->id
            || !is_int($g['criado'] ?? null) || time() - $g['criado'] > self::VALIDADE || !is_array($g['plano'] ?? null)) {
            if ($g !== null) {
                Session::remover(self::SESSAO);
            }
            return null;
        }
        return $g;
    }
}
