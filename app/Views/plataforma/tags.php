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
        <section class="bloco tags-cartao" aria-labelledby="tags-consentimento">
            <div class="bloco-cabecalho">
                <h2 id="tags-consentimento"><?= Icon::svg('shield-check') ?> Carregamento do Google</h2>
                <p>Por padrão, as integrações carregam somente após o aceite. A opção abaixo permite detectar o Google antes da escolha, sem liberar cookies opcionais.</p>
            </div>
            <label class="tags-opcao"><input type="checkbox" name="tags[google_advanced]" value="1"<?= $tags['google_advanced'] ? ' checked' : '' ?>> Carregar Google antes da escolha (modo avançado)</label>
            <p>O Google poderá receber dados técnicos e sinais sem cookies antes do aceite. Ative somente após revisar a base legal e o contêiner GTM. O Meta Pixel continua bloqueado até o aceite. Se houver recusa, as integrações são interrompidas e a página é recarregada sem rastreadores externos.</p>
            <?php if (isset($erros['google_advanced'])): ?><p class="tags-erro" role="alert"><?= $e($erros['google_advanced']) ?></p><?php endif; ?>
        </section>
        <p class="tags-aviso"><?= Icon::svg('shield-check') ?> Cadastro, cardápios, checkout e áreas internas não recebem rastreadores. Use o Tag Manager ou as tags diretas em uma mesma página para evitar duplicidade. Publique apenas tags confiáveis no contêiner GTM, com verificações de consentimento. <a href="https://tagassistant.google.com/" target="_blank" rel="noopener noreferrer">Testar no Google Tag Assistant</a></p>
        <div class="form-acoes">
            <button type="submit" class="btn btn-primary"><?= Icon::svg('check', 'icon-sm') ?> Salvar configurações</button>
        </div>
    </form>
</div>
