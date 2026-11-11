<?php

declare(strict_types=1);

namespace Cardapim\Services\Painel;

/**
 * Fonte unica do menu do painel da PLATAFORMA (administracao do Cardapim, cargo adm_master).
 * Rota de cada secao: /painel/{chave}. "dashboard" e /painel.
 * pronto = true: a secao tem tela propria; senao mostra "Em construcao" com o que vai ter.
 */
final class MenuPlataforma
{
    /**
     * @var array<string, array{titulo:string, rotulo:string, icone:string, descricao:string, planejado:list<string>, pronto?:bool}>
     */
    public const SECOES = [
        'dashboard' => [
            'titulo' => 'Dashboard',
            'rotulo' => 'Dashboard',
            'icone' => 'layout-dashboard',
            'descricao' => 'Os números de toda a plataforma em uma tela.',
            'planejado' => [
                'Faturamento das assinaturas no mês e previsão do próximo',
                'Restaurantes ativos, novos, em teste e cancelados',
                'Pedidos e vendas de toda a plataforma por período',
                'Faturas vencidas e restaurantes com pagamento atrasado',
                'Saúde do sistema: banco, tempo real, WhatsApp e Asaas',
            ],
        ],
        'restaurantes' => [
            'titulo' => 'Restaurantes',
            'rotulo' => 'Restaurantes',
            'icone' => 'store',
            'descricao' => 'Todos os restaurantes da plataforma.',
            'planejado' => [
                'Lista com busca, situação e plano de cada restaurante',
                'Aprovar cadastros novos',
                'Suspender e reativar o acesso',
                'Entrar no painel do restaurante para dar suporte',
                'Dados, endereço e histórico de cada um',
            ],
        ],
        'planos' => [
            'titulo' => 'Planos',
            'rotulo' => 'Planos',
            'icone' => 'tags',
            'descricao' => 'Planos e preços que os restaurantes podem assinar.',
            'planejado' => [],
            'pronto' => true,
        ],
        'faturas' => [
            'titulo' => 'Faturas',
            'rotulo' => 'Faturas',
            'icone' => 'receipt',
            'descricao' => 'Cobranças das assinaturas, recebidas pelo Asaas da plataforma.',
            'planejado' => [
                'Gerar as cobranças das assinaturas (Pix e boleto) pelo Asaas',
                'Situação de cada fatura: aberta, paga ou vencida',
                'Baixa automática quando o Asaas avisar o pagamento',
                'Reenviar cobrança por e-mail e WhatsApp',
                'Histórico de faturas por restaurante',
            ],
        ],
        'usuarios' => [
            'titulo' => 'Usuários',
            'rotulo' => 'Usuários',
            'icone' => 'users',
            'descricao' => 'Todas as contas da plataforma.',
            'planejado' => [
                'Buscar qualquer conta por nome, usuário ou e-mail',
                'Bloquear e liberar acessos',
                'Redefinir senha e desligar a verificação em duas etapas',
                'Administradores da plataforma',
            ],
        ],
        'logs' => [
            'titulo' => 'Logs de auditoria',
            'rotulo' => 'Logs de auditoria',
            'icone' => 'file-text',
            'descricao' => 'Tudo o que foi feito na plataforma, por quem e quando.',
            'planejado' => [
                'Todos os registros com filtro por restaurante, pessoa, data e tipo',
                'Logins, alertas de segurança e alterações',
                'Exportar para planilha',
            ],
        ],
        'integracoes' => [
            'titulo' => 'Integrações',
            'rotulo' => 'Integrações',
            'icone' => 'plug-zap',
            'descricao' => 'Contas da própria plataforma: Asaas para receber as assinaturas e WhatsApp para falar com clientes.',
            'planejado' => [],
            'pronto' => true,
        ],
        'configuracoes' => [
            'titulo' => 'Configurações',
            'rotulo' => 'Configurações',
            'icone' => 'settings',
            'descricao' => 'Nome, textos e logos da plataforma.',
            'planejado' => [],
            'pronto' => true,
        ],
        'suporte' => [
            'titulo' => 'Suporte',
            'rotulo' => 'Suporte',
            'icone' => 'life-buoy',
            'descricao' => 'Pedidos de ajuda dos restaurantes.',
            'planejado' => [
                'Chamados abertos pelos restaurantes',
                'Responder e acompanhar cada chamado',
                'Situação: aberto, em atendimento, resolvido',
            ],
        ],
        'minha-conta' => [
            'titulo' => 'Minha conta',
            'rotulo' => 'Minha conta',
            'icone' => 'circle-user',
            'descricao' => 'Seus dados e a segurança da sua conta.',
            'planejado' => [
                'Nome e telefone',
                'Trocar a senha',
                'Verificação em duas etapas (aplicativo ou e-mail)',
                'Aparelhos conectados e últimos acessos',
            ],
        ],
    ];

    /** Agrupamento do menu lateral. */
    public const GRUPOS = [
        '' => ['dashboard'],
        'Gestão' => ['restaurantes', 'planos', 'faturas', 'usuarios'],
        'Plataforma' => ['integracoes', 'logs', 'configuracoes', 'suporte'],
        'Conta' => ['minha-conta'],
    ];

    public static function existe(string $chave): bool
    {
        return isset(self::SECOES[$chave]);
    }

    public static function url(string $chave): string
    {
        return $chave === 'dashboard' ? '/painel' : '/painel/' . $chave;
    }
}
