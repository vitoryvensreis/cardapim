<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Core\Session;
use Cardapim\Repositories\Gerencia\AssinaturaRepository;
use Cardapim\Repositories\Plataforma\FaturaAdminRepository;
use Cardapim\Services\Plataforma\AssinaturaCobranca;

/**
 * Faturas das assinaturas (/painel/faturas): numeros do mes, lista com filtros e "Atualizar agora" (busca no Asaas).
 * O dinheiro entra na conta Asaas da plataforma; aqui so se acompanha.
 */
final class FaturasController extends BasePlataforma
{
    private const CHAVE_CONCILIACAO = "conciliacao_faturas";

    public function lista(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new FaturaAdminRepository($this->db);
        $conta = (new AssinaturaCobranca($this->db))->conta();
        $padrao = $conta['ambiente'] ?? 'producao';
        $ambiente = in_array($_GET['ambiente'] ?? '', ['producao', 'sandbox'], true) ? (string) $_GET['ambiente'] : $padrao;
        $situacao = in_array($_GET['situacao'] ?? '', FaturaAdminRepository::SITUACOES, true) ? (string) $_GET['situacao'] : null;
        $mes = is_string($_GET['mes'] ?? null) && preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $_GET['mes']) ? $_GET['mes'] : null;
        $busca = mb_substr(trim(is_string($_GET['busca'] ?? null) ? $_GET['busca'] : ''), 0, 80);
        $pagina = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
        $filtros = ['situacao' => $situacao, 'busca' => $busca, 'mes' => $mes];
        $res = $repo->listar($ambiente, $filtros, $pagina);
        $outro = $ambiente === 'producao' ? 'sandbox' : 'producao';

        return $this->tela('plataforma/faturas/lista', 'faturas', 'Faturas', [
            'faturas' => $res['itens'],
            'total' => $res['total'],
            'pagina' => $pagina,
            'paginas' => max(1, (int) ceil($res['total'] / FaturaAdminRepository::POR_PAGINA)),
            'filtros' => [...$filtros, 'ambiente' => $ambiente === $padrao ? null : $ambiente],
            'contagem' => $repo->contagem($ambiente, $filtros),
            'indicadores' => $repo->indicadores($ambiente, date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00', (int) strtotime('first day of next month'))),
            'ambiente' => $ambiente,
            'ambientePadrao' => $padrao,
            'outroAmbiente' => $repo->temNoAmbiente($outro) || $ambiente !== $padrao ? $outro : null,
            'asaasConectado' => $conta !== null,
            'pageStyles' => ['css/plataforma-gestao.css', 'css/faturas.css'],
            'pageScripts' => ['js/forms.js'],
        ]);
    }

    /** Conciliação completa sob demanda, retomável na mesma sessão quando o histórico é grande. */
    public function atualizar(): Response
    {
        if ($r = $this->entrar(true)) {
            return $r;
        }
        $cobranca = new AssinaturaCobranca($this->db);
        $conta = $cobranca->conta();
        if ($conta === null) {
            return $this->voltar('/painel/faturas', 'erro', 'Conecte o Asaas da plataforma em Integrações para buscar as faturas.');
        }
        $ambiente = $this->post('ambiente') ?: $conta['ambiente'];
        $destino = '/painel/faturas?' . http_build_query(['ambiente' => $ambiente]);
        if ($ambiente !== $conta['ambiente']) {
            return $this->voltar($destino, 'erro', 'A conta Asaas conectada não corresponde ao ambiente selecionado.');
        }
        $lock = $this->db->query("SELECT GET_LOCK('cardapim:conciliacao-assinaturas', 0)");
        if ((int) $lock->fetchColumn() !== 1) {
            return $this->voltar($destino, 'erro', 'Já existe uma conciliação em andamento. Tente novamente.');
        }
        try {
            $repo = new AssinaturaRepository($this->db);
            $estado = Session::get(self::CHAVE_CONCILIACAO);
            if (!is_array($estado) || ($estado['ambiente'] ?? null) !== $ambiente) {
                $estado = ['ambiente' => $ambiente, 'fase' => 'recorrencias', 'cursor' => 0, 'recorrencias' => 0,
                    'faturas' => 0, 'corrigidas' => 0, 'falhas' => 0];
            }
            // A requisição HTTP não deve manter o painel bloqueado por todo um histórico grande.
            $inicio = microtime(true);
            $processadas = 0;
            while ($estado['fase'] !== 'fim' && $processadas < 40 && microtime(true) - $inicio < 15) {
                $recorrencias = $estado['fase'] === 'recorrencias';
                $lote = $recorrencias
                    ? $repo->loteRecorrenciasConciliacao($ambiente, $estado['cursor'])
                    : $repo->loteFaturasConciliacao($ambiente, $estado['cursor']);
                if ($lote === []) {
                    $estado['fase'] = $recorrencias ? 'faturas' : 'fim';
                    $estado['cursor'] = 0;
                    continue;
                }
                foreach ($lote as $item) {
                    if ($processadas >= 40 || microtime(true) - $inicio >= 15) {
                        break;
                    }
                    $cursorAnterior = $estado['cursor'];
                    $estado['cursor'] = $recorrencias ? $item : (int) $item['id'];
                    $processadas++;
                    try {
                        if ($recorrencias) {
                            $ok = $cobranca->sincronizar($item, true);
                        } else {
                            $antes = $repo->faturaPorAsaas($ambiente, $item['asaas_id']);
                            $ok = $cobranca->sincronizarFatura($ambiente, $item['asaas_id']);
                            $depois = $repo->faturaPorAsaas($ambiente, $item['asaas_id']);
                            if ($ok && $antes !== null && $depois !== null && $antes['status'] !== $depois['status']) {
                                $estado['corrigidas']++;
                            }
                        }
                        $estado[$ok ? ($recorrencias ? 'recorrencias' : 'faturas') : 'falhas']++;
                    } catch (\Throwable $erro) {
                        $estado['falhas']++;
                        error_log('[Cardapim] Conciliação manual: ' . $estado['fase'] . ' #' . $estado['cursor'] . ' ' . get_class($erro));
                        if ((int) $erro->getCode() === 429) {
                            $estado['cursor'] = $cursorAnterior;
                            $estado['falhas']--;
                            Session::set(self::CHAVE_CONCILIACAO, $estado);
                            return $this->voltar($destino, 'erro', 'Limite da API atingido. Clique em Atualizar agora mais tarde para continuar.');
                        }
                    }
                    usleep(250000);
                }
            }
            $resumo = "Recorrências conferidas: {$estado['recorrencias']}. Faturas conferidas: {$estado['faturas']}. Situações corrigidas na consulta individual: {$estado['corrigidas']}. Falhas: {$estado['falhas']}.";
            if ($estado['fase'] !== 'fim') {
                Session::set(self::CHAVE_CONCILIACAO, $estado);
                return $this->voltar($destino, 'sucesso', "Atualização parcial. {$resumo} Clique em Atualizar agora para continuar de onde parou.");
            }
            Session::remover(self::CHAVE_CONCILIACAO);
            return $this->voltar($destino, $estado['falhas'] > 0 ? 'erro' : 'sucesso',
                ($estado['falhas'] > 0 ? 'Conciliação com falhas. ' : 'Conciliação completa. ') . $resumo);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('cardapim:conciliacao-assinaturas')");
        }
    }
}
