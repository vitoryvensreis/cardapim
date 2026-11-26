<?php
use Cardapim\Core\Icon;
use Cardapim\Shared\Validation\Documento;
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = $base . '/fiscal';
$tokenCsrf = static function () use ($e, $csrf): void { ?><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php };
$dataBr = static fn (?string $iso): string => is_string($iso) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : '';
?>
<div class="painel-page fiscal-restaurante">
    <header class="painel-cabecalho"><div><h1>Central fiscal</h1><p>Prepare o restaurante para emitir a nota fiscal do consumidor (NFC-e) pelo <?= marca_html() ?>.</p></div></header>
    <div class="notice notice-info"><?= Icon::svg('info') ?><div><strong>Nenhuma nota é emitida nesta fase</strong><p>Aqui você só prepara o cadastro. O caixa continua funcionando como hoje.</p></div></div>
    <?php if ($fiscal === null): ?>
        <section class="bloco"><p>A configuração fiscal está temporariamente indisponível. Tente novamente em alguns minutos.</p></section>
    <?php else:
        $hom = $fiscal['ambiente'] === 2;
        $testado = $fiscal['status'] !== 'Não testado';
        $certOk = ($fiscal['certificado']['expira_em'] ?? 0) > time();
        $temParametros = $hom && ($fiscal['config']['id_csc'] ?? '') !== '' && $fiscal['tem_csc'];
        // Etapas na ordem real do fluxo. Cada uma aponta para o bloco onde se resolve.
        $etapas = [['Ativar o módulo fiscal', $fiscal['habilitado'], 'modulo-fiscal']];
        if ($fiscal['habilitado']) {
            $etapas[] = ['Preencher os dados da empresa', $fiscal['empresa_salva'] && (!$hom || $fiscal['empresa_pronta']), 'dados-empresa'];
            $etapas[] = ['Aguardar a aprovação da plataforma', $fiscal['aprovado'], 'conexao-emissor'];
            $etapas[] = ['Conectar ao emissor de notas', $fiscal['registrado'], 'conexao-emissor'];
            if ($hom) {
                $etapas[] = ['Salvar série, numeração e CSC', $temParametros, 'nfce-ambiente'];
                $etapas[] = ['Enviar o certificado digital A1', $certOk, 'certificado-a1'];
                $etapas[] = ['Enviar a configuração', (bool) $fiscal['config_enviada'], 'certificado-a1'];
            }
            $etapas[] = ['Testar a conexão', $testado, 'teste-conexao'];
        }
        $atual = null;
        foreach ($etapas as $i => $etapa) { if (!$etapa[1]) { $atual = $i; break; } }
    ?>
        <section class="bloco fiscal-etapas" aria-labelledby="titulo-etapas">
            <div class="bloco-cabecalho"><h2 id="titulo-etapas"><?= Icon::svg('list-checks') ?>Seu progresso</h2>
                <p><?= $atual === null ? 'Todas as etapas desta fase foram concluídas.' : 'Próximo passo: ' . $e(mb_strtolower($etapas[$atual][0])) . '.' ?></p></div>
            <ol class="fiscal-passos">
                <?php foreach ($etapas as $i => [$titulo, $feito, $ancora]): $estado = $feito ? 'feito' : ($i === $atual ? 'atual' : 'pendente'); ?>
                    <li class="fiscal-passo fiscal-passo-<?= $estado ?>"<?= $estado === 'atual' ? ' aria-current="step"' : '' ?>>
                        <?= Icon::svg($feito ? 'circle-check' : ($estado === 'atual' ? 'arrow-right' : 'clock'), 'icon-sm') ?>
                        <a href="#<?= $e($ancora) ?>"><?= $e($titulo) ?></a>
                        <span class="fiscal-passo-estado"><?= $feito ? 'Concluída' : ($estado === 'atual' ? 'Fazer agora' : 'Depois') ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <section class="bloco" id="modulo-fiscal">
            <div class="bloco-cabecalho"><h2><?= Icon::svg('power') ?>Módulo fiscal</h2><p><?= $fiscal['habilitado'] ? 'Ativo. Desativar guarda todas as configurações.' : 'Ative para começar a configurar. Não usar o emissor não dispensa as obrigações fiscais do restaurante.' ?></p></div>
            <form method="post" action="<?= $e($url . '/habilitar') ?>"><?php $tokenCsrf(); ?>
                <input type="hidden" name="habilitado" value="<?= $fiscal['habilitado'] ? '0' : '1' ?>">
                <button class="btn <?= $fiscal['habilitado'] ? 'btn-outline' : 'btn-primary' ?>" type="submit"><?= $fiscal['habilitado'] ? 'Desativar módulo' : 'Habilitar módulo fiscal' ?></button>
            </form>
        </section>

        <?php if ($fiscal['habilitado']): $d = $fiscal['empresa']; $c = $fiscal['config']; ?>
            <?php if (!empty($fiscalProdutosDisponivel)): ?>
                <section class="bloco"><div class="bloco-cabecalho"><h2><?= Icon::svg('package') ?>Cadastro fiscal dos produtos</h2><p>Defina perfis tributários, classifique os produtos e acompanhe o que falta.</p></div><a class="btn btn-outline" href="<?= $e($url.'/produtos') ?>">Produtos fiscais</a> <a class="btn btn-outline" href="<?= $e($url.'/perfis') ?>">Perfis tributários</a></section>
            <?php endif; ?>

            <section class="bloco" id="dados-empresa">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('store') ?>Dados fiscais da empresa</h2><p>Confira com o contador. O CNPJ vem do cadastro do restaurante.</p></div>
                <p><strong>CNPJ: <?= $e(Documento::formatarCnpj($fiscal['cnpj'])) ?></strong></p>
                <form method="post" action="<?= $e($url . '/empresa') ?>" class="fiscal-form" data-fiscal-empresa data-fiscal-consulta="<?= $e($url . '/consultar-empresa') ?>" data-fiscal-inicial="<?= $fiscal['auto_consulta'] ? '1' : '0' ?>"><?php $tokenCsrf(); ?>
                    <button type="button" class="btn btn-outline" data-fiscal-repreencher><?= Icon::svg('refresh-cw','icon-sm') ?>Repreencher pelo CNPJ</button>
                    <p class="texto-suave fiscal-consulta" role="status" aria-live="polite" data-fiscal-mensagem>Os dados consultados são sugestões. Você pode editar tudo antes de salvar.</p>
                    <?php foreach (['razao'=>['Razão social',160],'nome'=>['Nome fantasia',120],'email'=>['E-mail fiscal',160],'cep'=>['CEP',9],'logradouro'=>['Logradouro',120],'numero'=>['Número',20],'complemento'=>['Complemento',100],'bairro'=>['Bairro',80],'cidade'=>['Município',80],'estado'=>['UF',2]] as $campo => [$rotulo,$max]): $obrig = !in_array($campo,['complemento','nome','email','bairro'],true); ?>
                        <label for="f-<?= $e($campo) ?>"><?= $e($rotulo) ?><?= $obrig ? '' : ' <span class="texto-suave">(opcional)</span>' ?></label>
                        <input id="f-<?= $e($campo) ?>" name="<?= $e($campo) ?>" maxlength="<?= $max ?>" <?= $obrig ? 'required' : '' ?> value="<?= $e($d[$campo] ?? '') ?>" <?= $campo === 'cep' ? 'inputmode="numeric" autocomplete="postal-code"' : ($campo === 'email' ? 'type="email"' : '') ?>>
                        <?php if ($campo === 'cep'): ?><button type="button" class="btn btn-outline" data-fiscal-cep><?= Icon::svg('search','icon-sm') ?>Buscar endereço pelo CEP</button><?php endif; ?>
                    <?php endforeach; ?>
                    <p class="texto-suave">Código do município (IBGE): <?= !empty($d['ibge_verificado']) ? $e($d['ibge']) . ', identificado automaticamente' : 'identificado pelo sistema ao salvar' ?>.</p>
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
                    <div data-fiscal-ie><label for="f-ie">Número da inscrição estadual (só números)</label><input id="f-ie" name="ie" inputmode="numeric" pattern="[0-9]{2,20}" maxlength="20" value="<?= $e($d['ie'] ?? '') ?>"></div>
                    <p class="texto-suave">A NFC-e exige a inscrição estadual do restaurante. Dá para salvar sem ela agora, mas o teste com a SEFAZ só é liberado depois que ela for informada.</p>
                    <?php if ($fiscal['empresa_salva'] && !$fiscal['empresa_pronta']): ?><p class="fiscal-consulta-erro" role="status"><?= $e(\Cardapim\Services\Gerencia\FiscalService::pendenciaEmpresa($d)) ?></p><?php endif; ?>
                    <button class="btn btn-primary" type="submit"><?= Icon::svg('save','icon-sm') ?>Salvar dados fiscais</button>
                </form>
            </section>

            <section class="bloco" id="conexao-emissor">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('key-round') ?>Conexão com o emissor de notas</h2><p>O <?= marca_html() ?> emite as notas pela NeXT. A plataforma confere o CNPJ antes de liberar a conexão. Nenhuma senha ou chave é exibida.</p></div>
                <p><strong><?= $fiscal['registrado'] ? 'Restaurante conectado ao emissor.' : ($fiscal['aprovado'] ? 'Aprovado pela plataforma. Clique em Conectar.' : ($fiscal['empresa_salva'] ? 'Aguardando a aprovação da plataforma. Você será liberado em breve.' : 'Salve os dados fiscais da empresa para pedir a aprovação.')) ?></strong></p>
                <form method="post" action="<?= $e($url . '/conectar') ?>"><?php $tokenCsrf(); ?>
                    <button class="btn btn-primary" type="submit" <?= !$fiscal['aprovado'] || $fiscal['registrado'] ? 'disabled' : '' ?>><?= $fiscal['registrado'] ? 'Conectado à NeXT' : 'Conectar ao emissor' ?></button>
                </form>
            </section>

            <section class="bloco" id="nfce-ambiente">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('settings') ?>Modo de teste</h2><p>Comece pelo teste interno. Use o teste com a SEFAZ quando tiver o certificado A1 e o CSC.</p></div>
                <form method="post" action="<?= $e($url . '/ambiente') ?>" class="fiscal-form"><?php $tokenCsrf(); ?>
                    <label for="f-amb">Modo</label><select id="f-amb" name="ambiente"><option value="3" <?= $fiscal['ambiente'] === 3 ? 'selected' : '' ?>>Teste interno (simulação, sem SEFAZ)</option><option value="2" <?= $hom ? 'selected' : '' ?>>Teste com a SEFAZ (homologação)</option></select>
                    <button class="btn btn-outline" type="submit">Usar este modo</button>
                </form>
                <?php if (!$hom): ?>
                    <div class="fiscal-separado">
                        <p><strong><?= Icon::svg('circle-check','icon-sm') ?> Configuração automática</strong></p>
                        <p class="texto-suave">Série de teste <?= $e($c['serie']) ?> e numeração controlada pelo sistema. Não precisa de CSC nem de certificado.</p>
                        <p class="texto-suave">As respostas são simuladas e nada é enviado à SEFAZ.</p>
                    </div>
                <?php else: ?>
                <form method="post" action="<?= $e($url . '/configurar') ?>" class="fiscal-form fiscal-separado"><?php $tokenCsrf(); ?>
                    <label for="f-serie">Série</label><input id="f-serie" name="serie" type="number" min="0" max="889" required value="<?= $e($c['serie'] ?? 1) ?>">
                    <p class="texto-suave">Normalmente 1. As séries de 890 a 999 são reservadas.</p>
                    <label for="f-numero">Próximo número da nota</label><input id="f-numero" name="numero" type="number" min="1" max="999999999" required value="<?= $e($c['numero'] ?? 1) ?>">
                    <label for="f-idcsc">ID do CSC</label><input id="f-idcsc" name="id_csc" maxlength="6" inputmode="numeric" pattern="[0-9]{1,6}" required value="<?= $e($c['id_csc'] ?? '') ?>">
                    <label for="f-csc">CSC (código de segurança do contribuinte)</label><input id="f-csc" name="csc" type="password" maxlength="128" autocomplete="new-password" <?= $fiscal['tem_csc'] ? '' : 'required' ?> placeholder="<?= $fiscal['tem_csc'] ? 'Já salvo; deixe vazio para manter' : 'Cole o código' ?>">
                    <p class="texto-suave">O CSC e o ID são obrigatórios na NFC-e. São gerados no portal da SEFAZ do estado com o certificado do restaurante; o contador costuma ter acesso.</p>
                    <button class="btn btn-primary" type="submit">Salvar parâmetros</button>
                </form>
                <?php endif; ?>
            </section>

            <?php if ($hom): ?>
            <section class="bloco" id="certificado-a1">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('shield-check') ?>Certificado digital A1</h2><p>Arquivo .pfx ou .p12 do restaurante. Usado só no teste com a SEFAZ; o arquivo e a senha não ficam guardados no <?= marca_html() ?>.</p></div>
                <p><strong><?= $e($fiscal['alerta']) ?></strong><?php if ($fiscal['certificado']): ?> · vence em <?= $e($dataBr($fiscal['certificado']['validade'] ?? '')) ?><?php endif; ?></p>
                <?php $motivoCert = !$fiscal['registrado'] ? 'Conecte o restaurante ao emissor primeiro.' : (!$temParametros ? 'Salve série, numeração e CSC primeiro.' : (!$fiscal['empresa_pronta'] ? \Cardapim\Services\Gerencia\FiscalService::pendenciaEmpresa($d) : '')); ?>
                <form method="post" enctype="multipart/form-data" action="<?= $e($url . '/certificado') ?>" class="fiscal-form"><?php $tokenCsrf(); ?>
                    <label for="f-cert">Arquivo do certificado (até 1 MB)</label><input id="f-cert" type="file" name="certificado" accept=".pfx,.p12" required>
                    <label for="f-senha">Senha do certificado</label><input id="f-senha" type="password" name="senha" autocomplete="new-password" maxlength="512">
                    <button class="btn btn-primary" type="submit" <?= $motivoCert !== '' ? 'disabled aria-describedby="motivo-cert"' : '' ?>>Validar e enviar certificado</button>
                    <?php if ($motivoCert !== ''): ?><p class="texto-suave" id="motivo-cert"><?= $e($motivoCert) ?></p><?php endif; ?>
                </form>
                <div class="fiscal-separado">
                    <p><strong>Configuração no emissor:</strong> <?= $fiscal['config_enviada'] ? 'enviada.' : 'ainda não enviada.' ?></p>
                    <?php $motivoConfig = $motivoCert !== '' ? $motivoCert : (!$certOk ? 'Envie o certificado A1 primeiro.' : ''); ?>
                    <form method="post" action="<?= $e($url . '/enviar-config') ?>"><?php $tokenCsrf(); ?><button class="btn btn-outline" type="submit" <?= $motivoConfig !== '' ? 'disabled aria-describedby="motivo-config"' : '' ?>><?= $fiscal['config_enviada'] ? 'Enviar configuração de novo' : 'Enviar configuração' ?></button></form>
                    <?php if ($motivoConfig !== ''): ?><p class="texto-suave" id="motivo-config"><?= $e($motivoConfig) ?></p><?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="bloco" id="teste-conexao">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('refresh-cw') ?>Testar a conexão</h2><p>Confere se o emissor responde para este restaurante. Não emite nota.</p></div>
                <p><strong><?= $e($testado ? $fiscal['status'] : 'Ainda não testado neste modo.') ?></strong></p>
                <form method="post" action="<?= $e($url . '/testar') ?>"><?php $tokenCsrf(); ?><button class="btn btn-outline" type="submit" <?= !$fiscal['registrado'] ? 'disabled aria-describedby="motivo-teste"' : '' ?>>Testar conexão</button></form>
                <?php if (!$fiscal['registrado']): ?><p class="texto-suave" id="motivo-teste">Conecte o restaurante ao emissor primeiro.</p><?php endif; ?>
            </section>

            <?php if ($fiscal['registrado']): $em = $fiscal['emissao']; ?>
            <section class="bloco" id="emissao-automatica">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('receipt') ?>Emissão automática</h2><p>A nota é emitida sozinha quando o pagamento é confirmado. Se o emissor estiver fora do ar, a venda segue normalmente e a nota é reenviada depois.</p></div>
                <form method="post" action="<?= $e($url . '/emissao') ?>" class="fiscal-form"><?php $tokenCsrf(); ?>
                    <fieldset class="fiscal-opcoes"><legend>Emitir a nota automaticamente em</legend>
                        <?php foreach (\Cardapim\Services\Fiscal\FiscalEmissao::ORIGENS as $k => $rot): ?>
                            <label class="fiscal-check"><input type="checkbox" name="<?= $e($k) ?>" value="1" <?= $em[$k] ? 'checked' : '' ?>> <?= $e($rot) ?><?= $k === 'ifood' ? ' <span class="texto-suave">(confirme com o contador antes de ligar)</span>' : '' ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                    <label class="fiscal-check"><input type="checkbox" name="imprimir" value="1" <?= $em['imprimir'] ? 'checked' : '' ?>> Imprimir a nota sozinha depois de autorizada</label>
                    <button class="btn btn-primary" type="submit"><?= Icon::svg('save','icon-sm') ?>Salvar emissão automática</button>
                </form>
                <?php if ($fiscal['ambiente'] !== 1): ?><p class="texto-suave">Neste modo de teste as notas não têm valor fiscal.</p><?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('printer') ?>Impressora do cupom fiscal</h2><p><?= $e($fiscal['impressora']['destino'] ?? 'Nenhuma impressora escolhida ainda.') ?></p></div>
                <a class="btn btn-outline" href="<?= $e($base . '/integracoes/impressoras#cupom-fiscal') ?>">Escolher impressora fiscal</a>
                <p class="texto-suave">A impressão da nota será liberada numa etapa futura.</p>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
