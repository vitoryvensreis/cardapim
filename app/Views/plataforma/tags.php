<?php
use Cardapim\Core\Icon;
use Cardapim\Services\Plataforma\TagsService;
use Cardapim\Shared\Html\Campo;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="painel-page">
    <header class="painel-cabecalho">
        <div>
            <h1>Tags e rastreamento</h1>
            <p>Configure as ferramentas de análise e marketing da plataforma e escolha onde serão executadas.</p>
        </div>
    </header>
    <?= Campo::resumoErros($erros) ?>
    <form method="post" action="<?= $e($base . '/tags') ?>" class="tags-form">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?php foreach (TagsService::PROVEDORES as $chave => [$nome, $exemplo]):
            $tag = $tags[$chave];
            $prefixo = 'tags[' . $chave . ']';
        ?>
            <section class="bloco tags-cartao" aria-labelledby="tag-<?= $e($chave) ?>">
                <div class="bloco-cabecalho tags-cabecalho">
                    <div>
                        <h2 id="tag-<?= $e($chave) ?>"><?= Icon::svg('tags') ?><?= $e($nome) ?></h2>
                        <p><?= $tag['ativo'] ? 'Ativado' : 'Desativado' ?></p>
                    </div>
                    <label class="tags-opcao"><input type="checkbox" name="<?= $e($prefixo) ?>[ativo]" value="1"<?= $tag['ativo'] ? ' checked' : '' ?>> Ativar</label>
                </div>
                <label class="tags-campo" for="id-<?= $e($chave) ?>">Identificador
                    <input id="id-<?= $e($chave) ?>" type="text" name="<?= $e($prefixo) ?>[id]" value="<?= $e($tag['id']) ?>" placeholder="<?= $e($exemplo) ?>" maxlength="64" autocomplete="off" spellcheck="false">
                </label>
                <fieldset class="tags-escopos">
                    <legend>Onde executar</legend>
                    <?php foreach (TagsService::ESCOPOS as $escopo => $rotulo): ?>
                        <label class="tags-opcao"><input type="checkbox" name="<?= $e($prefixo) ?>[escopos][]" value="<?= $e($escopo) ?>"<?= in_array($escopo, $tag['escopos'], true) ? ' checked' : '' ?>> <?= $e($rotulo) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <?php if (isset($erros[$chave])): ?><p class="tags-erro" role="alert"><?= $e($erros[$chave]) ?></p><?php endif; ?>
            </section>
        <?php endforeach; ?>
        <p class="tags-aviso"><?= Icon::svg('shield-check') ?> As tags só carregam após o visitante aceitar todos os cookies. Cadastro, cardápios, checkout e áreas internas não recebem rastreadores. Use o Tag Manager ou as tags diretas em uma mesma página para evitar duplicidade. Publique apenas tags confiáveis no contêiner GTM.</p>
        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= Icon::svg('check', 'icon-sm') ?> Salvar configurações</button>
        </div>
    </form>
</div>
