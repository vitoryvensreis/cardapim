<?php

declare(strict_types=1);

namespace Cardapim\Services\Painel;

/**
 * Fonte unica do menu do painel do gerente: rotulos, icones, rotas e o que cada secao vai ter.
 * Rota de cada secao: /{slug}/gerencia/{chave}. "visao-geral" e /{slug}/gerencia.
 * Caixa e Entregas sao areas proprias (/{slug}/caixa e /{slug}/entregas).
 */
final class MenuGerencia
{
    /**
     * @var array<string, array{titulo:string, rotulo:string, icone:string, descricao:string, planejado:list<string>}>
     */
    public const SECOES = [
        'visao-geral' => [
            'titulo' => 'Visão geral',
            'rotulo' => 'Visão geral',
            'icone' => 'layout-dashboard',
            'descricao' => 'O resumo do seu restaurante em uma tela.',
            'planejado' => ['Vendas e pedidos do dia', 'Gráficos de vendas por hora e por canal', 'Produtos mais vendidos', 'Insumos com estoque baixo', 'Pedidos em andamento'],
        ],
        'mesas' => [
            'titulo' => 'Mesas',
            'rotulo' => 'Mesas',
            'icone' => 'armchair',
            'descricao' => 'Organize o salão e acompanhe a ocupação das mesas.',
            'planejado' => ['Cadastrar, editar e excluir mesas', 'Mapa do salão com mesas livres e ocupadas', 'QR Code de cada mesa para o cardápio'],
        ],
        'estoque' => [
            'titulo' => 'Estoque',
            'rotulo' => 'Estoque',
            'icone' => 'package',
            'descricao' => 'Produtos do cardápio, insumos e fichas técnicas.',
            'planejado' => ['Cadastrar, editar e excluir produtos', 'Cadastrar, editar e excluir insumos', 'Ficha técnica de cada produto', 'Categorias do cardápio', 'Avisos de estoque baixo'],
        ],
        'equipe' => [
            'titulo' => 'Equipe',
            'rotulo' => 'Equipe',
            'icone' => 'users',
            'descricao' => 'Quem trabalha com você e o que cada pessoa pode acessar.',
            'planejado' => ['Cadastrar, editar e excluir garçons', 'Cadastrar, editar e excluir entregadores', 'Cadastrar, editar e excluir caixas', 'Ativar e desativar acessos'],
        ],
        'financeiro' => [
            'titulo' => 'Financeiro',
            'rotulo' => 'Financeiro',
            'icone' => 'banknote',
            'descricao' => 'Dinheiro que entra e que sai, sempre claro.',
            'planejado' => ['Entradas e saídas', 'Fechamentos de caixa', 'Contas a pagar e a receber', 'Relatórios por período'],
        ],
        'perfil' => [
            'titulo' => 'Perfil do restaurante',
            'rotulo' => 'Perfil',
            'icone' => 'store',
            'descricao' => 'Como seu restaurante aparece para os clientes.',
            'planejado' => ['Nome e logo', 'Endereço e telefone', 'Horário de funcionamento', 'Página pública no catálogo'],
        ],
        'integracoes' => [
            'titulo' => 'Integrações',
            'rotulo' => 'Integrações',
            'icone' => 'plug-zap',
            'descricao' => 'Conecte o Cardapim aos serviços que você já usa.',
            'planejado' => ['iFood: receber pedidos direto no painel', 'Asaas: receber Pix dos pedidos online na sua conta'],
        ],
        'assinatura' => [
            'titulo' => 'Assinatura',
            'rotulo' => 'Assinatura',
            'icone' => 'credit-card',
            'descricao' => 'Seu plano do Cardapim e os pagamentos.',
            'planejado' => ['Plano atual e vencimento', 'Histórico de pagamentos', 'Trocar de plano'],
        ],
        'minha-conta' => [
            'titulo' => 'Minha conta',
            'rotulo' => 'Minha conta',
            'icone' => 'circle-user',
            'descricao' => 'Seus dados de acesso.',
            'planejado' => ['Seus dados pessoais', 'Trocar a senha', 'Aparelhos conectados ("manter conectado")', 'Histórico de acessos'],
        ],
        'suporte' => [
            'titulo' => 'Suporte',
            'rotulo' => 'Suporte',
            'icone' => 'life-buoy',
            'descricao' => 'Fale com a equipe do Cardapim.',
            'planejado' => ['Chat com o suporte', 'Abrir chamados', 'Acompanhar seus chamados'],
        ],
    ];

    /** Agrupamento do menu lateral (ordem de exibicao). */
    public const GRUPOS = [
        '' => ['visao-geral'],
        'Operação' => ['@caixa', '@entregas', 'mesas'],
        'Gestão' => ['estoque', 'equipe', 'financeiro'],
        'Restaurante' => ['perfil', 'integracoes', 'assinatura'],
        'Conta' => ['minha-conta', 'suporte'],
    ];

    /** Itens que abrem areas proprias. */
    public const AREAS = [
        '@caixa' => ['rotulo' => 'Caixa', 'icone' => 'monitor', 'area' => 'caixa', 'dica' => 'Abre o modo caixa'],
        '@entregas' => ['rotulo' => 'Entregas', 'icone' => 'bike', 'area' => 'entregas', 'dica' => 'Pedidos e entregadores'],
    ];

    public static function existe(string $chave): bool
    {
        return isset(self::SECOES[$chave]) && $chave !== 'visao-geral';
    }

    public static function url(string $slug, string $chave): string
    {
        $base = '/' . rawurlencode($slug);
        if (isset(self::AREAS[$chave])) {
            return $base . '/' . self::AREAS[$chave]['area'];
        }
        return $chave === 'visao-geral' ? $base . '/gerencia' : $base . '/gerencia/' . $chave;
    }
}
