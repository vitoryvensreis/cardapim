<?php
use Cardapim\Core\Icon;
use Cardapim\Shared\Validation\Documento;
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = $base . '/fiscal';
$tokenCsrf = static function () use ($e, $csrf): void { ?><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php };
?>
<div class="painel-page fiscal-restaurante">
    <header class="painel-cabecalho"><div><h1>Central fiscal</h1><p>Configure a emissão fiscal integrada do <?= marca_html() ?>. Disponível em todos os planos.</p></div></header>
    <div class="notice notice-info"><?= Icon::svg('info') ?><div><strong>Emissão real bloqueada</strong><p>Esta etapa prepara o cadastro. Não altera o caixa nem emite documentos fiscais.</p></div></div>
    <?php if ($fiscal === null): ?>
        <section class="bloco"><p>Configuração fiscal temporariamente indisponível.</p></section>
    <?php else: ?>
        <section class="bloco">
            <div class="bloco-cabecalho"><h2><?= Icon::svg('power') ?>Módulo fiscal</h2><p>Ativação opcional. A desativação não dispensa obrigações fiscais.</p></div>
            <form method="post" action="<?= $e($url . '/habilitar') ?>"><?php $tokenCsrf(); ?>
                <input type="hidden" name="habilitado" value="<?= $fiscal['habilitado'] ? '0' : '1' ?>">
                <button class="btn <?= $fiscal['habilitado'] ? 'btn-outline' : 'btn-primary' ?>" type="submit"><?= $fiscal['habilitado'] ? 'Desativar módulo' : 'Habilitar módulo fiscal' ?></button>
            </form>
        </section>
        <?php if ($fiscal['habilitado']): $d = $fiscal['empresa']; $c = $fiscal['config']; ?>
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('store') ?>Dados fiscais da empresa</h2><p>Confira com o contador. O CNPJ vem do cadastro do restaurante.</p></div>
                <p><strong>CNPJ: <?= $e(Documento::formatarCnpj($fiscal['cnpj'])) ?></strong></p>
                <form method="post" action="<?= $e($url . '/empresa') ?>" class="fiscal-form" data-fiscal-empresa data-fiscal-consulta="<?= $e($url . '/consultar-empresa') ?>" data-fiscal-inicial="<?= $fiscal['auto_consulta'] ? '1' : '0' ?>"><?php $tokenCsrf(); ?>
                    <button type="button" class="btn btn-outline" data-fiscal-repreencher><?= Icon::svg('refresh-cw','icon-sm') ?>Repreencher pelo CNPJ</button>
                    <p class="texto-suave fiscal-consulta" role="status" aria-live="polite" data-fiscal-mensagem>Os dados consultados são sugestões. Você pode editar tudo antes de salvar.</p>
                    <?php foreach (['razao'=>['Razão social',160],'nome'=>['Nome fantasia',120],'email'=>['E-mail fiscal',160],'cep'=>['CEP',9],'logradouro'=>['Logradouro',120],'numero'=>['Número',20],'complemento'=>['Complemento',100],'bairro'=>['Bairro',80],'cidade'=>['Município',80],'estado'=>['UF',2]] as $campo => [$rotulo,$max]): ?>
                        <label for="f-<?= $e($campo) ?>"><?= $e($rotulo) ?></label>
                        <input id="f-<?= $e($campo) ?>" name="<?= $e($campo) ?>" maxlength="<?= $max ?>" <?= !in_array($campo,['complemento','nome','email','bairro'],true) ? 'required' : '' ?> value="<?= $e($d[$campo] ?? '') ?>" <?= $campo === 'cep' ? 'inputmode="numeric" autocomplete="postal-code"' : ($campo === 'email' ? 'type="email"' : '') ?>>
                        <?php if ($campo === 'cep'): ?><button type="button" class="btn btn-outline" data-fiscal-cep><?= Icon::svg('search','icon-sm') ?>Buscar endereço pelo CEP</button><?php endif; ?>
                    <?php endforeach; ?>
                    <p class="texto-suave">Código IBGE: <?= !empty($d['ibge_verificado']) ? $e($d['ibge']) . ' — identificado automaticamente' : 'identificado pelo sistema ao salvar' ?>.</p>
                    <label for="f-crt">Regime tributário</label>
                    <select id="f-crt" name="crt" required>
                        <option value="">Selecione com o contador</option>
                        <?php foreach (['1'=>'Simples Nacional','2'=>'Simples Nacional — excesso de sublimite','3'=>'Regime normal','4'=>'MEI'] as $v=>$rotulo): ?>
                            <option value="<?= $v ?>" <?= (string) ($d['crt'] ?? '') === (string) $v ? 'selected' : '' ?>><?= $e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php $ieSituacao = $d['ie_situacao'] ?? (!empty($d['ie']) ? 'possui' : 'pendente'); ?>
                    <label for="f-ie-situacao">Inscrição estadual (CGF no Ceará)</label>
                    <select id="f-ie-situacao" name="ie_situacao">
                        <?php foreach (['possui'=>'Possuo inscrição estadual','isento'=>'Isento — confirmar com o contador','pendente'=>'Não possuo ou não sei informar'] as $v=>$rotulo): ?>
                            <option value="<?= $e($v) ?>" <?= $ieSituacao === $v ? 'selected' : '' ?>><?= $e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div data-fiscal-ie><label for="f-ie">Inscrição estadual (dígitos)</label><input id="f-ie" name="ie" inputmode="numeric" pattern="[0-9]{2,20}" maxlength="20" value="<?= $e($d['ie'] ?? '') ?>"></div>
                    <p class="texto-suave">A ausência de inscrição estadual não significa isenção. Consulte o contador; cadastros pendentes podem ser salvos, mas não liberam homologação.</p>
                    <?php if ($fiscal['empresa_salva'] && !$fiscal['empresa_pronta']): ?><p class="fiscal-consulta-erro" role="status">Cadastro salvo com pendência fiscal. Confira a inscrição estadual e o município.</p><?php endif; ?>
                    <button class="btn btn-primary" type="submit"><?= Icon::svg('save','icon-sm') ?>Salvar dados fiscais</button>
                </form>
            </section>
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('key-round') ?>Conexão com a NeXT</h2><p>O cadastro automático é liberado após a administração conferir o vínculo com o CNPJ. Nenhum token é exibido.</p></div>
                <p><?= $fiscal['registrado'] ? 'Emitente conectado' : ($fiscal['aprovado'] ? 'Vínculo aprovado; conecte o emitente.' : 'Aguardando confirmação administrativa') ?></p>
                <form method="post" action="<?= $e($url . '/conectar') ?>"><?php $tokenCsrf(); ?>
                    <button class="btn btn-primary" type="submit" <?= !$fiscal['aprovado'] ? 'disabled' : '' ?>><?= $fiscal['registrado'] ? 'Conferir vínculo' : 'Conectar emitente' ?></button>
                </form>
            </section>
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('settings') ?>NFC-e e ambiente</h2><p>Parâmetros separados por ambiente. Modelo 65; emissão real ainda não disponível.</p></div>
                <form method="post" action="<?= $e($url . '/ambiente') ?>" class="fiscal-form"><?php $tokenCsrf(); ?>
                    <label for="f-amb">Ambiente</label><select id="f-amb" name="ambiente"><option value="3" <?= $fiscal['ambiente'] === 3 ? 'selected' : '' ?>>Simulação</option><option value="2" <?= $fiscal['ambiente'] === 2 ? 'selected' : '' ?>>Homologação</option><option disabled>Produção (bloqueada)</option></select>
                    <button class="btn btn-outline" type="submit">Selecionar ambiente</button>
                </form>
                <form method="post" action="<?= $e($url . '/configurar') ?>" class="fiscal-form fiscal-separado"><?php $tokenCsrf(); ?>
                    <label for="f-serie">Série</label><input id="f-serie" name="serie" type="number" min="0" max="999" required value="<?= $e($c['serie'] ?? 1) ?>">
                    <label for="f-numero">Próxima numeração</label><input id="f-numero" name="numero" type="number" min="1" max="999999999" required value="<?= $e($c['numero'] ?? 1) ?>">
                    <label for="f-idcsc">ID do CSC (quando exigido)</label><input id="f-idcsc" name="id_csc" maxlength="6" inputmode="numeric" value="<?= $e($c['id_csc'] ?? '') ?>">
                    <label for="f-csc">CSC (quando exigido)</label><input id="f-csc" name="csc" type="password" maxlength="128" autocomplete="new-password" placeholder="<?= $fiscal['tem_csc'] ? 'Protegido; vazio mantém se ID não mudar' : 'Informe o código' ?>">
                    <button class="btn btn-primary" type="submit">Salvar parâmetros</button>
                </form>
            </section>
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('shield-check') ?>Certificado digital A1</h2><p>Em simulação não é necessário certificado. O envio ocorre apenas em homologação.</p></div>
                <p><strong><?= $e($fiscal['alerta']) ?></strong></p>
                <?php if ($fiscal['certificado']): ?><p><?= $e($fiscal['certificado']['titular']) ?> · Vencimento: <?= $e($fiscal['certificado']['validade']) ?></p><?php endif; ?>
                <?php if ($fiscal['ambiente'] === 2): ?>
                    <form method="post" enctype="multipart/form-data" action="<?= $e($url . '/certificado') ?>" class="fiscal-form"><?php $tokenCsrf(); ?>
                        <label for="f-cert">Arquivo .pfx ou .p12 (até 1 MB)</label><input id="f-cert" type="file" name="certificado" accept=".pfx,.p12" required>
                        <label for="f-senha">Senha do certificado</label><input id="f-senha" type="password" name="senha" autocomplete="new-password" maxlength="512">
                        <button class="btn btn-primary" type="submit" <?= !$fiscal['registrado'] ? 'disabled' : '' ?>>Validar e enviar à NeXT</button>
                    </form>
                    <form method="post" action="<?= $e($url . '/enviar-config') ?>" class="fiscal-separado"><?php $tokenCsrf(); ?><button class="btn btn-outline" type="submit" <?= !$fiscal['registrado'] ? 'disabled' : '' ?>>Enviar configuração de homologação</button></form>
                <?php endif; ?>
            </section>
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('refresh-cw') ?>Conexão fiscal</h2></div>
                <p><?= $e($fiscal['status']) ?></p>
                <form method="post" action="<?= $e($url . '/testar') ?>"><?php $tokenCsrf(); ?><button class="btn btn-outline" type="submit" <?= !$fiscal['registrado'] ? 'disabled' : '' ?>>Testar conexão</button></form>
                <p class="texto-suave">Este teste não é homologação de emissão.</p>
            </section>
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('printer') ?>Impressora fiscal</h2><p><?= $e($fiscal['impressora']['destino'] ?? 'Aguardando seleção') ?></p></div>
                <a class="btn btn-outline" href="<?= $e($base . '/integracoes/impressoras#cupom-fiscal') ?>">Gerenciar impressora fiscal</a>
                <p class="texto-suave">A impressão do DANFE será disponibilizada em etapa posterior.</p>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
