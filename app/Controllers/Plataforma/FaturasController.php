<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Repositories\Plataforma\FaturaAdminRepository;
use Cardapim\Services\Plataforma\AssinaturaCobranca;

/**
 * Faturas das assinaturas (/painel/faturas): numeros do mes, lista com filtros e "Atualizar agora" (busca no Asaas).
 * O dinheiro entra na conta Asaas da plataforma; aqui so se acompanha.
 */
final class FaturasController extends BasePlataforma
{
    /** Quantas assinaturas o "Atualizar agora" consulta por vez (cada uma e uma chamada ao Asaas). */
    private const LIMITE_ATUALIZAR = 40;

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

    /** Busca no Asaas as faturas das assinaturas cobradas (as com fatura em aberto primeiro). */
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
        $ids = (new FaturaAdminRepository($this->db))->assinaturasParaAtualizar($conta['ambiente'], self::LIMITE_ATUALIZAR);
        foreach ($ids as $id) {
            $cobranca->sincronizar($id);
        }
        $msg = match (count($ids)) {
            0 => 'Nenhuma assinatura cobrada pelo Asaas ainda.',
            1 => 'Faturas atualizadas: 1 assinatura conferida no Asaas.',
            default => 'Faturas atualizadas: ' . count($ids) . ' assinaturas conferidas no Asaas.',
        };
        return $this->voltar('/painel/faturas', 'sucesso', $msg);
    }
}
