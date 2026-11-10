<?php
/**
 * Cardapio publico. Espera: $restaurante, $logo, $iniciais, $categorias (id, nome, produtos), $totalProdutos, $info.
 * Os produtos levam data-* com id, nome, descricao e preco em centavos: o JS monta a sacola sem script inline.
 */
use Cardapim\Core\Icon;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$aberto = $info['aberto'];
$status = !$info['horario_informado'] ? ['neutro', 'clock', 'Horário não informado'] : ($aberto ? ['aberto', 'circle-check', 'Aberto agora'] : ['fechado', 'clock', 'Fechado agora']);
$telefone = preg_replace('/\D/', '', (string) $info['telefone']) ?? '';
?>
<section class="cd-hero" aria-labelledby="cd-nome">
    <div class="cd-capa" aria-hidden="true"></div>
    <div class="cd-hero-corpo">
        <span class="cd-logo">
            <?php if ($logo !== null): ?>
                <img src="<?= $e($logo) ?>" alt="" width="88" height="88">
            <?php else: ?>
                <span aria-hidden="true"><?= $e($iniciais) ?></span>
            <?php endif; ?>
        </span>
        <div class="cd-hero-texto">
            <h1 id="cd-nome" data-hero-nome><?= $e((string) $restaurante['nome']) ?></h1>
            <p class="cd-hero-linha">
                <span class="cd-status cd-status-<?= $status[0] ?>"><?= Icon::svg($status[1], 'icon-sm') ?><?= $status[2] ?></span>
                <?php if ($info['bairro_cidade'] !== ''): ?><span class="cd-local"><?= Icon::svg('map-pin', 'icon-sm') ?><?= $e($info['bairro_cidade']) ?></span><?php endif; ?>
            </p>
        </div>
    </div>
    <details class="cd-info">
        <summary><?= Icon::svg('info', 'icon-sm') ?>Endereço, telefone e horários<?= Icon::svg('chevron-down', 'icon-sm cd-info-seta') ?></summary>
        <div class="cd-info-corpo">
            <?php if ($info['endereco'] !== '' || $info['bairro_cidade'] !== ''): ?>
                <p><?= Icon::svg('map-pin', 'icon-sm') ?><span><?= $e(trim($info['endereco'] . ($info['endereco'] !== '' && $info['bairro_cidade'] !== '' ? ' · ' : '') . $info['bairro_cidade'])) ?></span></p>
            <?php endif; ?>
            <?php if (strlen($telefone) >= 10): ?>
                <p><?= Icon::svg('phone', 'icon-sm') ?><a href="tel:+55<?= $e($telefone) ?>"><?= $e(Documento::formatarTelefone($telefone)) ?></a></p>
            <?php endif; ?>
            <?php if ($info['sempre_aberto']): ?>
                <p><?= Icon::svg('clock', 'icon-sm') ?><span>Aberto 24 horas, todos os dias</span></p>
            <?php elseif ($info['horarios'] !== []): ?>
                <table class="cd-horarios">
                    <caption><?= Icon::svg('clock', 'icon-sm') ?>Horário de funcionamento</caption>
                    <tbody>
                        <?php foreach ($info['horarios'] as $h): ?>
                            <tr<?= $h['hoje'] ? ' class="is-hoje"' : '' ?>><th scope="row"><?= $e($h['dia']) ?><?= $h['hoje'] ? ' <small>(hoje)</small>' : '' ?></th><td><?= $e($h['texto']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </details>
    <?php if ($info['horario_informado'] && !$aberto && $totalProdutos > 0): ?>
        <p class="cd-aviso" role="status"><?= Icon::svg('clock', 'icon-sm') ?>O restaurante está fechado agora. Você pode montar sua sacola e pedir quando ele abrir.</p>
    <?php endif; ?>
</section>

<?php if ($totalProdutos === 0): ?>
    <section class="cd-vazio" id="cardapio">
        <span aria-hidden="true"><?= Icon::svg('utensils-crossed', 'icon-lg') ?></span>
        <h2>Cardápio em preparação</h2>
        <p>Este restaurante ainda está montando o cardápio por aqui. Volte em breve.</p>
        <a class="btn btn-wine" href="/catalogo"><?= Icon::svg('store', 'icon-sm') ?>Ver outros restaurantes</a>
    </section>
<?php else: ?>
<div class="cd-layout">
    <div class="cd-menu" id="cardapio">
        <nav class="cd-nav" aria-label="Categorias do cardápio" data-nav>
            <label class="cd-busca">
                <span class="sr-only">Buscar no cardápio</span>
                <?= Icon::svg('search', 'icon-sm') ?>
                <input type="search" placeholder="Buscar no cardápio" autocomplete="off" enterkeyhint="search" data-busca>
                <button type="button" class="cd-busca-limpar" aria-label="Limpar busca" data-busca-limpar hidden><?= Icon::svg('x', 'icon-sm') ?></button>
            </label>
            <?php if (count($categorias) > 1): ?>
                <ul class="cd-abas" data-abas>
                    <?php foreach ($categorias as $i => $c): ?>
                        <li><a class="cd-aba" href="#cat-<?= $e($c['id']) ?>" data-aba="<?= $e($c['id']) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>><?= $e($c['nome']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </nav>

        <?php foreach ($categorias as $c): ?>
            <section class="cd-categoria" id="cat-<?= $e($c['id']) ?>" data-categoria="<?= $e($c['id']) ?>" aria-labelledby="titulo-<?= $e($c['id']) ?>">
                <h2 id="titulo-<?= $e($c['id']) ?>"><?= $e($c['nome']) ?> <small><?= count($c['produtos']) ?></small></h2>
                <ul class="cd-produtos">
                    <?php foreach ($c['produtos'] as $p): ?>
                        <li data-produto-item data-busca-texto="<?= $e(mb_strtolower($p['nome'] . ' ' . $p['descricao'])) ?>">
                            <button type="button" class="cd-produto<?= $p['disponivel'] ? '' : ' is-indisponivel' ?>" data-produto="<?= (int) $p['id'] ?>"
                                    data-nome="<?= $e($p['nome']) ?>" data-descricao="<?= $e($p['descricao']) ?>" data-centavos="<?= (int) $p['centavos'] ?>"
                                    <?= $p['disponivel'] ? '' : 'disabled aria-disabled="true"' ?>>
                                <span class="cd-produto-texto">
                                    <strong><?= $e($p['nome']) ?></strong>
                                    <?php if ($p['descricao'] !== ''): ?><span class="cd-produto-desc"><?= $e($p['descricao']) ?></span><?php endif; ?>
                                    <span class="cd-produto-preco"><?= $p['disponivel'] ? $e($p['preco']) : 'Indisponível no momento' ?></span>
                                </span>
                                <span class="cd-produto-acao" aria-hidden="true">
                                    <span class="cd-na-sacola" data-na-sacola hidden></span>
                                    <?php if ($p['disponivel']): ?><span class="cd-mais"><?= Icon::svg('plus') ?></span><?php endif; ?>
                                </span>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <div class="cd-vazio cd-vazio-busca" data-busca-vazio hidden>
            <span aria-hidden="true"><?= Icon::svg('search', 'icon-lg') ?></span>
            <h2>Nada encontrado</h2>
            <p>Não achamos nenhum item com "<strong data-busca-termo></strong>". Tente outra palavra.</p>
            <button type="button" class="btn btn-outline btn-sm" data-busca-limpar>Ver o cardápio todo</button>
        </div>
    </div>

    <aside class="cd-lateral" aria-labelledby="sacola-titulo-lateral">
        <div class="cd-sacola-painel">
            <h2 id="sacola-titulo-lateral"><?= Icon::svg('shopping-bag') ?>Sua sacola</h2>
            <div data-sacola-conteudo></div>
        </div>
    </aside>
</div>

<div class="cd-barra" data-barra hidden>
    <button type="button" class="cd-barra-btn" data-abrir-sacola aria-haspopup="dialog">
        <span class="cd-barra-qtd" data-barra-qtd>0</span>
        <span class="cd-barra-texto">Ver sacola</span>
        <span class="cd-barra-total" data-barra-total>R$ 0,00</span>
    </button>
</div>
<?php endif; ?>

<p class="cd-toast" role="status" aria-live="polite" data-toast hidden></p>
