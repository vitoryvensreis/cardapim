<?php
use Cardapim\Core\Icon;
use Cardapim\Shared\Validation\Documento;

$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$url = $base . '/fiscal';
$dataBr = static fn (?string $v): string => is_string($v) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/', $v, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] . (isset($m[4]) ? ' às ' . $m[4] . ':' . $m[5] : '') : '';
?>
<div class="painel-page fiscal-central">
    <header class="painel-cabecalho">
        <div>
            <h1>Central fiscal</h1>
            <p>Gerencie as credenciais da plataforma e acompanhe a integração com a API NeXT.</p>
        </div>
        <?php if ($fiscal !== null): ?>
            <span class="etiqueta <?= $fiscal['ativo'] ? 'etiqueta-ok' : 'etiqueta-neutra' ?>">
                <?= Icon::svg($fiscal['ativo'] ? 'circle-check' : 'power', 'icon-sm') ?>
                <?= $fiscal['ativo'] ? 'Integração ativa' : 'Integração desativada' ?>
            </span>
        <?php endif; ?>
    </header>
    <div class="notice notice-info"><?= Icon::svg('info') ?><div><strong>Notas com valor fiscal só com a sua liberação</strong><p>Cada restaurante emite em modo de teste (sem valor fiscal) até você liberar a produção em <a href="#liberacao-fiscal">Liberação do módulo fiscal</a>. Restaurantes com o módulo desligado continuam com o caixa funcionando normalmente.</p></div></div>
    <?php if ($fiscal === null): ?>
        <div class="notice notice-warning" role="alert">Não foi possível ler a configuração fiscal. Confira a criptografia e os logs do servidor. Nenhuma configuração será sobrescrita.</div>
    <?php else: ?>
        <div class="fiscal-metricas">
            <?php foreach ([
                ['Emitentes na NeXT', $fiscal['quantidade'] ?? 'Não consultado', 'Última lista sincronizada'],
                ['Restaurantes com fiscal ativo', $restaurantesFiscais === null ? 'Indisponível' : count(array_filter($restaurantesFiscais, static fn ($r) => $r['habilitado'])), 'Habilitados para configuração'],
                ['Notas com valor fiscal no mês', ($consumoFiscal ?? null) === null ? 'Indisponível' : number_format($consumoFiscal['producao'], 0, ',', '.'), ($fiscal['limite'] === null ? 'Limite mensal não configurado' : 'Limite informado: ' . number_format($fiscal['limite'], 0, ',', '.')) . (($consumoFiscal ?? null) !== null ? ' · testes: ' . $consumoFiscal['teste'] . ' · precisam de atenção: ' . $consumoFiscal['atencao'] : '')],
                ['Vínculos aguardando aprovação', $restaurantesFiscais === null ? 'Indisponível' : count(array_filter($restaurantesFiscais, static fn ($r) => $r['habilitado'] && !$r['aprovado'])), 'Conferência administrativa do CNPJ'],
            ] as [$rotulo, $valor, $dica]): ?>
                <section class="bloco fiscal-metrica"><h2><?= $e($rotulo) ?></h2><strong><?= $e($valor) ?></strong><p class="texto-suave"><?= $e($dica) ?></p></section>
            <?php endforeach; ?>
        </div>
        <section class="bloco" id="restaurantes-fiscais">
            <div class="bloco-cabecalho"><h2><?= Icon::svg('store') ?>Restaurantes fiscais</h2><p>Aprove o vínculo depois de conferir o CNPJ. Use "Conferir credencial" quando o restaurante não conseguir testar a conexão.</p></div>
            <?php if ($restaurantesFiscais === null): ?><p>Indicadores ainda indisponíveis.</p>
            <?php elseif ($restaurantesFiscais === []): ?><p>Nenhum restaurante iniciou a configuração fiscal.</p>
            <?php else: usort($restaurantesFiscais, static fn ($a, $b) => [(int) !($a['habilitado'] && !$a['aprovado']), (int) !$a['cnpj_divergente'], $a['nome']] <=> [(int) !($b['habilitado'] && !$b['aprovado']), (int) !$b['cnpj_divergente'], $b['nome']]); ?>
                <?php foreach ($restaurantesFiscais as $r): ?>
                    <article class="fiscal-restaurante-item">
                        <div>
                            <strong><?= $e($r['nome']) ?></strong>
                            <p><?= $e(Documento::formatarCnpj($r['cnpj'])) ?> · <?= $r['habilitado'] ? 'Módulo ativo' : 'Módulo desativado' ?> · <?= $r['registrado'] ? 'Conectado ao emissor' : ($r['aprovado'] ? 'Aprovado, aguardando conexão' : 'Aguardando aprovação') ?></p>
                            <p class="texto-suave">Certificado: <?= $e($r['validade'] ? $dataBr($r['validade']) : 'não enviado') ?> · <?= $e($r['alerta']) ?> · Modo: <?= $e($r['modo']) ?> · Último teste: <?= $e($r['status']) ?></p>
                        </div>
                        <?php if ($r['cnpj_divergente']): ?>
                            <form method="post" action="<?= $e($url . '/refazer-vinculo') ?>" class="fiscal-acao">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id_restaurante" value="<?= (int) $r['id'] ?>">
                                <p class="notice notice-warning">O CNPJ do cadastro do restaurante mudou. O vínculo fiscal precisa ser refeito e aprovado de novo.</p>
                                <label class="fiscal-check"><input type="checkbox" name="confirmacao" value="1" required> Refazer o vínculo com o CNPJ atual (certificado e conexão serão pedidos de novo)</label>
                                <button class="btn btn-outline btn-sm" type="submit">Refazer vínculo</button>
                            </form>
                        <?php elseif ($r['habilitado'] && !$r['aprovado']): ?>
                            <form method="post" action="<?= $e($url . '/aprovar-restaurante') ?>" class="fiscal-acao">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="id_restaurante" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="cnpj" value="<?= $e($r['cnpj']) ?>">
                                <label class="fiscal-check"><input type="checkbox" name="confirmacao" value="1" required> Confirmei a titularidade e autorização do CNPJ.</label>
                                <button class="btn btn-primary btn-sm" type="submit">Autorizar vínculo fiscal</button>
                            </form>
                        <?php elseif ($r['aprovado']): ?>
                            <form method="post" action="<?= $e($url . '/conferir-credencial') ?>" class="fiscal-acao">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id_restaurante" value="<?= (int) $r['id'] ?>">
                                <button class="btn btn-outline btn-sm" type="submit"><?= Icon::svg('key-round', 'icon-sm') ?>Conferir credencial na NeXT</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <div class="fiscal-grade">
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('shield-check') ?>Configuração da API NeXT</h2><p>Credencial administrativa da Software House. O token nunca será exibido novamente.</p></div>
                <form method="post" action="<?= $e($url) ?>" autocomplete="off" class="fiscal-form">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <label for="fiscal-cnpj">CNPJ da Software House</label>
                    <input id="fiscal-cnpj" name="cnpj" inputmode="numeric" maxlength="18" required value="<?= $e(Documento::formatarCnpj($fiscal['cnpj'])) ?>">
                    <label for="fiscal-razao">Razão social</label>
                    <input id="fiscal-razao" name="razao" maxlength="160" required value="<?= $e($fiscal['razao']) ?>">
                    <label for="fiscal-token">Token administrativo</label>
                    <input id="fiscal-token" name="token" type="password" autocomplete="new-password" spellcheck="false" maxlength="512" <?= $fiscal['configurado'] ? '' : 'required' ?> placeholder="<?= $fiscal['configurado'] ? 'Token protegido; vazio mantém o atual' : 'Cole o token administrativo' ?>">
                    <label for="fiscal-ambiente">Ambiente padrão</label>
                    <select id="fiscal-ambiente" name="ambiente">
                        <option value="3" <?= $fiscal['ambiente'] === 3 ? 'selected' : '' ?>>Simulação</option>
                        <option value="2" <?= $fiscal['ambiente'] === 2 ? 'selected' : '' ?>>Homologação</option>
                        <option value="1" disabled>Produção (bloqueada)</option>
                    </select>
                    <label for="fiscal-limite">Limite mensal contratado (opcional)</label>
                    <input id="fiscal-limite" name="limite" type="number" min="1" max="100000000" value="<?= $e($fiscal['limite'] ?? '') ?>">
                    <div class="bloco-rodape"><button class="btn btn-primary" type="submit"><?= Icon::svg('save', 'icon-sm') ?>Salvar configurações</button></div>
                </form>
            </section>
            <section class="bloco">
                <div class="bloco-cabecalho"><h2><?= Icon::svg('plug-zap') ?>Conexão e permissões</h2><p>O teste consulta a disponibilidade e a lista de emitentes; não cadastra empresas nem emite notas.</p></div>
                <div class="resumo-assinatura">
                    <div class="resumo-linha"><span>Credencial</span><strong><?= $fiscal['configurado'] ? 'Armazenada com segurança' : 'Não cadastrada' ?></strong></div>
                    <div class="resumo-linha"><span>Disponibilidade</span><strong><?= $e($fiscal['disponibilidade']) ?></strong></div>
                    <div class="resumo-linha"><span>Permissão administrativa</span><strong><?= $e($fiscal['administracao']) ?></strong></div>
                    <div class="resumo-linha"><span>Último teste</span><strong><?= $e($fiscal['testado_em'] ? $dataBr($fiscal['testado_em']) : 'Ainda não testado') ?></strong></div>
                </div>
                <div class="bloco-rodape">
                    <form method="post" action="<?= $e($url . '/testar') ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="btn btn-outline" <?= $fiscal['configurado'] ? '' : 'disabled' ?>><?= Icon::svg('refresh-cw', 'icon-sm') ?>Testar e sincronizar</button>
                    </form>
                    <form method="post" action="<?= $e($url . ($fiscal['ativo'] ? '/desativar' : '/ativar')) ?>">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button type="submit" class="btn btn-outline" <?= !$fiscal['ativo'] && ($fiscal['disponibilidade'] !== 'Serviço em operação' || $fiscal['administracao'] !== 'Permissão administrativa confirmada') ? 'disabled' : '' ?>><?= Icon::svg('power', 'icon-sm') ?><?= $fiscal['ativo'] ? 'Desativar' : 'Ativar integração' ?></button>
                    </form>
                </div>
                <p class="texto-suave">A ativação é apenas administrativa. Nenhum restaurante terá emissão habilitada automaticamente.</p>
                <a class="voltar-link" href="<?= $e($base . '/logs') ?>"><?= Icon::svg('file-text', 'icon-sm') ?>Consultar logs de auditoria</a>
            </section>
        </div>
        <section class="bloco">
            <div class="bloco-cabecalho"><h2><?= Icon::svg('store') ?>CNPJs cadastrados na NeXT</h2><p>Última sincronização: <?= $e($fiscal['sincronizado_em'] ? $dataBr($fiscal['sincronizado_em']) : 'ainda não realizada') ?>. Cadastro na NeXT não significa habilitação fiscal no Deliveryzim.</p></div>
            <?php if ($fiscal['parcial']): ?><p class="texto-suave">Exibindo até 100 CNPJs. O total inclui todos os emitentes da conta, inclusive pessoas físicas, não exibidas aqui.</p><?php endif; ?>
            <?php if ($fiscal['clientes'] === []): ?>
                <p class="texto-suave">Nenhum CNPJ para exibir. Use Testar e sincronizar para consultar a conta.</p>
            <?php else: ?>
                <div class="fiscal-clientes">
                    <?php foreach ($fiscal['clientes'] as $cliente): ?>
                        <article><div><strong><?= $e($cliente['razao'] ?: 'Razão social não informada') ?></strong><p><?= $e(Documento::formatarCnpj($cliente['cnpj'])) ?></p></div><span class="etiqueta etiqueta-neutra"><?= $cliente['software_house'] ? 'Software House' : 'Emitente' ?></span></article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php require __DIR__.'/_liberacoes.php'; ?>
    <section class="bloco"><div class="bloco-cabecalho"><h2>Catálogos fiscais</h2><p>Disponibilize versões das tabelas para os restaurantes autorizados.</p></div><a class="btn btn-outline" href="<?= $e($url.'/catalogos') ?>">Gerenciar catálogos</a></section>
</div>
