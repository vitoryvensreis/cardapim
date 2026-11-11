<?php

declare(strict_types=1);

namespace Cardapim\Controllers\Plataforma;

use Cardapim\Core\Response;
use Cardapim\Core\View;
use Cardapim\Services\Painel\MenuPlataforma;

/** Painel da plataforma: /painel (dashboard) e as secoes ainda em construcao (/painel/{secao}). */
final class PainelController extends BasePlataforma
{
    public function inicio(): Response
    {
        return $this->secao('dashboard');
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
                ['/painel/integracoes', 'Integrações da plataforma', 'plug-zap', 'Asaas das assinaturas e WhatsApp da plataforma'],
                ['/status', 'Status do sistema', 'activity', 'PHP, banco de dados e tempo real'],
            ] : [],
        ]);
    }
}
