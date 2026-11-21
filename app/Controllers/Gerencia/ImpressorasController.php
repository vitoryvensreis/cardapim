<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Gerencia;

use Cardapim\Core\Response;
use Cardapim\Repositories\Desktop\ImpressoraRepository;
use Cardapim\Services\Auditoria;
use Cardapim\Shared\Esquema;

/**
 * Gerencia > Integracoes > Impressoras: as impressoras cadastradas nos Deliveryzim Agents do restaurante (com apelido)
 * e em quais delas cada ponto do sistema imprime. Ponto com uma impressora imprime direto; com varias, quem
 * imprime escolhe (a padrao vem marcada). O pedido novo do delivery sai em todas as marcadas.
 */
final class ImpressorasController extends BaseGerencia
{
    public function inicio(string $slug): Response
    {
        if ($r = $this->entrar($slug)) {
            return $r;
        }
        $ativo = Esquema::tem($this->db, 'impressoras');
        $repo = new ImpressoraRepository($this->db);
        return $this->tela('gerencia/integracoes/impressoras', 'integracoes', 'Impressoras', [
            'ativo' => $ativo,
            'impressoras' => $ativo ? $repo->listar($this->restaurante['id']) : [],
            'pontos' => $ativo ? $repo->pontos($this->restaurante['id']) : [],
            'rotulos' => $this->pontosDisponiveis(),
            'agentDownload' => IntegracoesController::AGENT_DOWNLOAD,
        ]);
    }

    /** Os pontos "producao" e "garcom_lancar" so aparecem depois das migracoes deles. @return array<string,string> */
    private function pontosDisponiveis(): array
    {
        $pontos = ImpressoraRepository::PONTOS;
        if (!Esquema::tem($this->db, 'impressao_produto')) {
            unset($pontos['producao']);
        }
        if (!Esquema::tem($this->db, 'impressao_lancamento')) {
            unset($pontos['garcom_lancar']);
        }
        return $pontos;
    }

    public function salvar(string $slug): Response
    {
        if ($r = $this->entrar($slug, true)) {
            return $r;
        }
        $volta = $this->url('integracoes/impressoras');
        if (!Esquema::tem($this->db, 'impressoras')) {
            return $this->voltar($volta, 'erro', 'As impressoras ainda estão sendo ativadas. Tente de novo em alguns minutos.');
        }
        $marcadas = is_array($_POST['ponto'] ?? null) ? $_POST['ponto'] : [];
        $padroes = is_array($_POST['padrao'] ?? null) ? $_POST['padrao'] : [];
        $config = [];
        foreach (array_keys($this->pontosDisponiveis()) as $ponto) {
            $ids = [];
            foreach (is_array($marcadas[$ponto] ?? null) ? $marcadas[$ponto] : [] as $v) {
                if (is_string($v) && ctype_digit($v) && strlen($v) <= 18) {
                    $ids[] = (int) $v;
                }
            }
            $p = $padroes[$ponto] ?? '';
            $config[$ponto] = ['ids' => $ids, 'padrao' => is_string($p) && ctype_digit($p) && strlen($p) <= 18 ? (int) $p : null];
        }
        (new ImpressoraRepository($this->db))->salvarPontos($this->restaurante['id'], $config);
        Auditoria::registrar($this->usuario, 'Pontos de impressão salvos', 'Configuração das impressoras do Agent atualizada.');
        return $this->voltar($volta, 'sucesso', 'Pontos de impressão salvos.');
    }
}
