<?php
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\FiltrosRelatorios as F;
use Cardapim\Services\Gerencia\RelatoriosService as S;
use Cardapim\Services\Gerencia\PeriodoFinanceiro;
use Cardapim\Shared\Numero;

$e=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$v=$filtros->f;
$url=$base.'/financeiro/relatorios';
$link=static fn(array $alt):string=>$url.'?'.$filtros->query($alt);
$select=static function(string $nome,array $opcoes,string $valor) use($e):void {
    echo '<select name="'.$e($nome).'">';
    foreach($opcoes as $k=>$label) {
        echo '<option value="'.$e($k).'"'.((string)$k===$valor?' selected':'').'>'.$e($label).'</option>';
    }
    echo '</select>';
};
$op=static function(string $k) use($opcoes,$v):array {
    $a=[''=>'Todos'];
    foreach($opcoes[$k] as $o) { $a[(string)$o['id']]=$o['nome']; }
    if ($v[$k]!==''&&!isset($a[$v[$k]])) { $a[$v[$k]]='Filtro selecionado'; }
    return $a;
};
$avancados=array_filter(array_intersect_key($v,array_flip(['produto','categoria','pedido','comanda','mesa','origem','funcionario','hora_de','hora_ate'])))!==[] || $v['data_por']!=='lancamento';
$detalhe=$v['visao']==='pedido';
?>
<div class="painel-page relatorios-page">
    <header class="painel-cabecalho">
        <div><h1>Financeiro</h1><p>Saiba quanto vendeu de cada produto, em quais horários e pedidos.</p></div>
        <div class="painel-acoes"><a class="btn btn-outline btn-sm" href="<?= $e($link(['exportar'=>1,'pagina'=>1])) ?>" download><?= Icon::svg('file-spreadsheet','icon-sm') ?> Baixar planilha</a></div>
    </header>
    <?php require __DIR__.'/_abas.php'; ?>
    <section class="bloco">
        <div class="bloco-cabecalho"><h2><?= Icon::svg('chart-no-axes-column') ?> Relatório de vendas</h2><p><?= $e($periodo->rotulo()) ?> · <?= $e(F::DATAS[$v['data_por']]) ?></p></div>
        <?php if ($v['nome_historico']!==''): ?><p class="rel-nota">Detalhe de <?= $e($v['nome_historico']) ?> (<?= $e($v['unidade']) ?>) · <a href="<?= $e($link(['nome_historico'=>'','unidade'=>'','produto'=>'','pagina'=>1])) ?>">Remover seleção</a></p><?php endif; ?>
        <?php if ($filtros->aviso!==null): ?><p class="notice notice-info" role="status"><?= $e($filtros->aviso) ?></p><?php endif; ?>
        <form class="rel-filtros" method="get" action="<?= $e($url) ?>">
            <input type="hidden" name="visao" value="<?= $e($v['visao']) ?>">
            <?php if ($v['nome_historico']!==''): ?><input type="hidden" name="nome_historico" value="<?= $e($v['nome_historico']) ?>"><input type="hidden" name="unidade" value="<?= $e($v['unidade']) ?>"><?php endif; ?>
            <div class="rel-grade">
                <label>Período <?php $select('periodo',PeriodoFinanceiro::ATALHOS+['personalizado'=>'Personalizado'],$periodo->chave); ?></label>
                <label>Pesquisar produto <input type="search" name="busca" value="<?= $e($v['busca']) ?>" maxlength="120" placeholder="Ex.: Heineken"></label>
                <label>Situação <?php $select('situacao',F::SITUACOES,$v['situacao']); ?></label>
            </div>
            <div class="rel-grade rel-datas">
                <label>Data inicial <input type="date" name="de" value="<?= $e($periodo->de) ?>"></label>
                <label>Data final <input type="date" name="ate" value="<?= $e($periodo->ate) ?>"></label>
                <small>Escolha Personalizado para usar as datas.</small>
            </div>
            <details<?= $avancados?' open':'' ?>>
                <summary>Mais filtros</summary>
                <div class="rel-grade">
                    <label>Considerar data de <?php $select('data_por',F::DATAS,$v['data_por']); ?></label>
                    <label>Hora inicial <input type="time" name="hora_de" value="<?= $e($v['hora_de']) ?>"></label>
                    <label>Hora final <input type="time" name="hora_ate" value="<?= $e($v['hora_ate']) ?>"></label>
                    <label>Produto específico <?php $select('produto',$op('produto'),$v['produto']); ?></label>
                    <label>Categoria atual <?php $select('categoria',$op('categoria'),$v['categoria']); ?></label>
                    <label>Número do pedido <input name="pedido" inputmode="numeric" maxlength="18" value="<?= $e($v['pedido']) ?>"></label>
                    <label>Nome ou número da comanda <input name="comanda" maxlength="60" value="<?= $e($v['comanda']) ?>"></label>
                    <label>Mesa <?php $select('mesa',$op('mesa'),$v['mesa']); ?></label>
                    <label>Origem <?php $select('origem',$op('origem'),$v['origem']); ?></label>
                    <label>Funcionário que lançou <?php $select('funcionario',$op('funcionario'),$v['funcionario']); ?></label>
                </div>
                <p class="rel-nota">O intervalo de horas vale para cada dia. Quando atravessa meia-noite, inclui as duas faixas de horário. A categoria usa o cadastro atual.</p>
            </details>
            <div class="rel-acoes">
                <button class="btn btn-primary" type="submit"><?= Icon::svg('search','icon-sm') ?> Aplicar filtros</button>
                <a class="btn btn-outline" href="<?= $e($url) ?>">Limpar filtros</a>
            </div>
        </form>
    </section>
    <div class="rel-indicadores">
        <?php foreach (['vendido'=>'Vendidos','aberto'=>'Em andamento','cancelado'=>'Cancelados'] as $status=>$rotulo):
            $grupos=array_values(array_filter($resumos,static fn($r)=>$r['situacao']===$status));
            $centavos=array_sum(array_map(static fn($r)=>Numero::centavos((string)$r['total']),$grupos));
            $total=intdiv(abs($centavos),100).'.'.sprintf('%02d',abs($centavos)%100);
            if($centavos<0){$total='-'.$total;}
        ?>
        <section class="indicador">
            <span class="indicador-topo"><?= $e($rotulo) ?></span>
            <strong><?= $e(Numero::reais($total)) ?></strong>
            <small><?= $grupos===[]?'Nenhum item':$e(implode(' · ',array_map(static fn($r)=>S::quantidade((string)$r['quantidade'],(string)$r['unidade']),$grupos))) ?></small>
        </section>
        <?php endforeach; ?>
    </div>
    <p class="rel-nota">Valores históricos dos itens, incluindo adicionais já incorporados e componentes de combos rateados. Não representam recebimentos líquidos: descontos gerais, taxas, troco e pagamentos são tratados no Financeiro.</p>
    <section class="bloco">
        <nav class="painel-abas rel-visoes" aria-label="Agrupar relatório">
            <?php foreach(F::VISOES as $k=>$label): ?>
            <a href="<?= $e($link(['visao'=>$k,'pagina'=>1])) ?>"<?= $k===$v['visao']?' aria-current="page"':'' ?>><?= $e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if($linhas===[]): ?>
            <div class="lista-vazia"><?= Icon::svg('search') ?><strong>Nenhum item encontrado</strong><p>Experimente outro período, produto ou situação.</p></div>
        <?php else: ?>
        <div class="rel-tabela" tabindex="0" role="region" aria-label="Resultados de vendas">
        <table>
            <thead><tr><th scope="col"><?= $e(match($v['visao']){'hora'=>'Hora','dia'=>'Data',default=>'Produto'}) ?></th>
            <?php if($detalhe): ?><th scope="col">Pedido / comanda</th><th scope="col">Horário</th><th scope="col">Funcionário</th><?php endif; ?>
            <th scope="col">Situação</th><th scope="col">Quantidade</th><?php if(!$detalhe): ?><th scope="col">Pedidos</th><?php endif; ?><th scope="col">Valor dos itens</th></tr></thead>
            <tbody>
                <?php foreach($linhas as $l): ?>
                <tr>
                    <td data-label="Produto / período">
                        <?php if($v['visao']==='produto'): ?>
                        <a href="<?= $e($link(['visao'=>'pedido','pagina'=>1,'produto'=>$l['produto_id']??'','busca'=>'','nome_historico'=>$l['rotulo'],'unidade'=>$l['unidade'],'situacao'=>$l['situacao']])) ?>"><?= $e($l['rotulo']) ?></a>
                        <?php else: ?><?= $e($v['visao']==='dia'?date('d/m/Y',strtotime($l['rotulo'])):$l['rotulo']) ?><?php endif; ?>
                        <?php if($detalhe&&!empty($l['combo_nome'])): ?><small>Combo: <?= $e($l['combo_nome']) ?></small><?php endif; ?>
                    </td>
                    <?php if($detalhe): ?>
                    <td data-label="Pedido / comanda">#<?= $e($l['numero']) ?><small><?= $e(S::comanda($l)) ?> · <?= $e($l['origem']) ?></small></td>
                    <td data-label="Horário"><?= $e(date('d/m/Y H:i',strtotime($l['momento']))) ?></td>
                    <td data-label="Funcionário"><?= $e($l['funcionario']??'—') ?></td>
                    <?php endif; ?>
                    <td data-label="Situação"><span class="rel-status rel-status-<?= $e($l['situacao']) ?>"><?= $e(S::situacao($l['situacao'])) ?></span></td>
                    <td data-label="Quantidade"><strong><?= $e(S::quantidade((string)$l['quantidade'],(string)$l['unidade'])) ?></strong></td>
                    <?php if(!$detalhe): ?><td data-label="Pedidos"><?= $e($l['pedidos']) ?></td><?php endif; ?>
                    <td data-label="Valor dos itens"><?= $e(Numero::reais($l['total'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        <?php if($filtros->pagina>1||$mais): ?>
            <nav class="rel-acoes" aria-label="Paginação">
                <?php if($filtros->pagina>1): ?><a class="btn btn-outline btn-sm" href="<?= $e($link(['pagina'=>$filtros->pagina-1])) ?>">Anterior</a><?php endif; ?>
                <span>Página <?= $e($filtros->pagina) ?></span>
                <?php if($mais&&$filtros->pagina<2000): ?><a class="btn btn-outline btn-sm" href="<?= $e($link(['pagina'=>$filtros->pagina+1])) ?>">Próxima</a><?php elseif($mais): ?><span>Refine os filtros para ver mais resultados.</span><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>
