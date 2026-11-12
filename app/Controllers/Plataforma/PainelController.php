<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Controllers\StatusController;
use Cardapim\Core\Config;
use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Repositories\Gerencia\IntegracaoRepository;
use Cardapim\Repositories\Plataforma\DashboardRepository;
use Cardapim\Repositories\Plataforma\FaturaAdminRepository;
use Cardapim\Repositories\SuporteRepository;
use Cardapim\Services\Email\Mailer;
use Cardapim\Services\Painel\MenuPlataforma;
use Cardapim\Services\Plataforma\AssinaturaCobranca;
use Throwable;

/** Painel da plataforma: /painel (dashboard) e as secoes ainda em construcao (/painel/{secao}). */
final class PainelController extends BasePlataforma
{
    /** Periodos do grafico e dos numeros de pedidos. */
    private const PERIODOS = ['hoje' => 'Hoje', '7dias' => '7 dias', '30dias' => '30 dias'];

    public function inicio(): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $repo = new DashboardRepository($this->db);
        $periodo = array_key_exists($_GET['periodo'] ?? '', self::PERIODOS) ? (string) $_GET['periodo'] : '7dias';
        $amanha = date('Y-m-d 00:00:00', strtotime('tomorrow'));
        $inicio = match ($periodo) {
            'hoje' => date('Y-m-d 00:00:00'),
            '30dias' => date('Y-m-d 00:00:00', strtotime('-29 days')),
            default => date('Y-m-d 00:00:00', strtotime('-6 days')),
        };
        $dias = $periodo === '30dias' ? 30 : 14;
        $inicioGrafico = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));
        $porDia = $repo->pedidosPorDia($inicioGrafico, $amanha);
        $grafico = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $dia = date('Y-m-d', strtotime('-' . $i . ' days'));
            $grafico[] = ['dia' => $dia, 'pedidos' => $porDia[$dia]['pedidos'] ?? 0, 'valor' => $porDia[$dia]['valor'] ?? '0'];
        }
        // Periodo anterior do mesmo tamanho, para comparar.
        $tamanho = strtotime($amanha) - strtotime($inicio);
        $anterior = $repo->pedidos(date('Y-m-d H:i:s', strtotime($inicio) - $tamanho), $inicio);

        $cobranca = new AssinaturaCobranca($this->db);
        $conta = $cobranca->conta();
        $ambiente = $conta['ambiente'] ?? 'producao';
        $integracoes = new IntegracaoRepository($this->db);

        return $this->tela('plataforma/dashboard', 'dashboard', 'Dashboard', [
            'periodo' => $periodo,
            'periodos' => self::PERIODOS,
            'restaurantes' => $repo->restaurantes(date('Y-m-d 00:00:00', strtotime('-29 days'))),
            'pedidos' => $repo->pedidos($inicio, $amanha),
            'anterior' => $anterior,
            'grafico' => $grafico,
            'origens' => $repo->porOrigem($inicio, $amanha),
            'maisPedidos' => $repo->maisPedidos($inicio, $amanha),
            'assinaturas' => $repo->assinaturas(),
            'faturas' => (new FaturaAdminRepository($this->db))->indicadores($ambiente, date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00', (int) strtotime('first day of next month'))),
            'ambiente' => $ambiente,
            'suporte' => self::seguro(fn () => (new SuporteRepository($this->db))->aguardandoPlataforma(), 0),
            'saude' => [
                'tempo_real' => StatusController::tempoReal(),
                'asaas' => $integracoes->buscar(null, 'asaas')['status'],
                'whatsapp' => $integracoes->buscar(null, 'whatsapp')['status'],
                'ifood' => $repo->ifood() + ['liberado' => Config::get('IFOOD_CLIENT_ID') !== '' && Config::get('IFOOD_CLIENT_SECRET') !== ''],
                'email' => Mailer::configuracao()['driver'],
            ],
            'pageStyles' => ['css/plataforma-gestao.css', 'css/visao-geral.css', 'css/dashboard-plataforma.css'],
        ]);
    }

    /** Um numero que nao pode derrubar a tela inteira. @template T @param callable():T $f @param T $padrao @return T */
    private static function seguro(callable $f, mixed $padrao): mixed
    {
        try {
            return $f();
        } catch (Throwable) {
            return $padrao;
        }
    }

    public function secao(string $secao): Response
    {
        if ($r = $this->entrar()) {
            return $r;
        }
        $dados = MenuPlataforma::SECOES[$secao] ?? null;
        if ($dados === null || !empty($dados['pronto'])) {
            return Response::html(View::render('errors/404', ['pageTitle' => 'Página não encontrada | ' . marca(), 'pageDescription' => 'O endereço solicitado não foi encontrado.', 'noIndex' => true], 'layouts/public'), 404);
        }
        return $this->tela('area/em-construcao', $secao, $dados['titulo'], [
            'secao' => $dados,
            'saudacao' => $secao === 'dashboard',
            'atalhos' => $secao === 'dashboard' ? [
                ['/painel/restaurantes', 'Restaurantes', 'store', 'Cadastrar, editar, suspender e excluir restaurantes'],
                ['/painel/usuarios', 'Usuários', 'users', 'Contas da equipe e administradores'],
                ['/painel/integracoes', 'Integrações da plataforma', 'plug-zap', 'Asaas das assinaturas e WhatsApp da plataforma'],
                ['/status', 'Status do sistema', 'activity', 'PHP, banco de dados e tempo real'],
            ] : [],
        ]);
    }
}
