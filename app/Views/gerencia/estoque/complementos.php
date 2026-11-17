<?php
/** Grupos de complementos com as opcoes. Espera: $pronto, $grupos, $base, $csrf, $contagem, $abaAtual. */
use Cardapim\Core\Icon;
use Cardapim\Shared\Numero;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$regra = static function (int $min, int $max): string {
    if ($min === 0) {
        return $max === 1 ? 'Opcional, escolhe 1' : 'Opcional, até ' . $max;
    }
    return $min === $max ? 'Obrigatório escolher ' . $min : 'Obrigatório: de ' . $min . ' a ' . $max;
};
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Estoque</h1>
            <p>Complementos são as escolhas do produto: adicionais, bordas, molhos, pontos da carne...</p>
        </div>
        <?php if ($pronto): ?>
            <a class="btn btn-primary" href="<?= $e($base . '/estoque/complementos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Novo grupo</a>
        <?php endif; ?>
    </header>

    <?php require __DIR__ . '/_abas.php'; ?>

    <?php if (!$pronto): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('clock') ?>
                <strong>Os complementos estão sendo liberados</strong>
                <p>Falta uma atualização do sistema no servidor. Assim que for feita, você cadastra os complementos por aqui.</p>
            </div>
        </section>
    <?php elseif ($grupos === []): ?>
        <section class="bloco">
            <div class="lista-vazia">
                <?= Icon::svg('square-plus') ?>
                <strong>Nenhum grupo de complementos ainda</strong>
                <p>Crie um grupo (exemplo: "Adicionais" com Bacon e Cheddar) e depois escolha, no cadastro do produto, onde ele aparece.</p>
                <a class="btn btn-primary btn-sm" href="<?= $e($base . '/estoque/complementos/novo') ?>"><?= Icon::svg('plus', 'icon-sm') ?>Criar o primeiro grupo</a>
            </div>
        </section>
    <?php else: ?>
        <div class="complementos-grupos">
            <?php foreach ($grupos as $g): ?>
                <section class="bloco complemento-grupo" aria-labelledby="grupo-<?= (int) $g['id'] ?>">
                    <div class="complemento-grupo-topo">
                        <div>
                            <h2 id="grupo-<?= (int) $g['id'] ?>"><?= $e($g['nome']) ?></h2>
                            <p class="texto-suave"><?= $e($regra($g['minimo'], $g['maximo'])) ?> · <?= $g['produtos'] === 0 ? 'ainda não está em nenhum produto' : 'em ' . $g['produtos'] . ' produto' . ($g['produtos'] === 1 ? '' : 's') ?></p>
                        </div>
                        <a class="btn btn-outline btn-sm" href="<?= $e($base . '/estoque/complementos/' . (int) $g['id']) ?>"><?= Icon::svg('pencil', 'icon-sm') ?>Editar</a>
                    </div>
                    <ul class="complemento-opcoes">
                        <?php foreach ($g['opcoes'] as $o): ?>
                            <li class="complemento-opcao<?= $o['disponivel'] ? '' : ' is-pausado' ?>">
                                <?php if ($o['imagem_path']): ?>
                                    <img class="complemento-foto" src="<?= $e(\Cardapim\Shared\MidiaUrl::publica($o['imagem_path'])) ?>" alt="" loading="lazy" width="48" height="48">
                                <?php else: ?>
                                    <span class="complemento-foto is-vazia" aria-hidden="true"><?= Icon::svg('image', 'icon-sm') ?></span>
                                <?php endif; ?>
                                <span class="complemento-texto"><strong><?= $e($o['nome']) ?></strong>
                                    <small><?= (float) $o['preco'] > 0 ? '+ ' . $e(Numero::reais($o['preco'])) : 'Grátis' ?><?= $o['disponivel'] ? '' : ' · Pausado' ?></small></span>
                                <form method="post" action="<?= $e($base . '/estoque/complementos/opcoes/' . (int) $o['id'] . '/pausar') ?>">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <button class="btn btn-outline btn-sm" type="submit" aria-label="<?= $e(($o['disponivel'] ? 'Pausar ' : 'Voltar a vender ') . $o['nome']) ?>">
                                        <?= Icon::svg($o['disponivel'] ? 'pause' : 'play', 'icon-sm') ?><?= $o['disponivel'] ? 'Pausar' : 'Vender' ?>
                                    </button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
