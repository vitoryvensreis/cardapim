<?php
/**
 * Restaurante pela administracao: numeros, dados, situacao, plano, contas e exclusao.
 * Espera: $rest, $valores, $erros, $contas, $movimento, $bloqueioExcluir, $assinatura, $planos, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Auth\Autorizacao;
use Cardapim\Services\Gerencia\AssinaturaService;
use Cardapim\Services\Gerencia\PerfilService;
use Cardapim\Services\Plataforma\RestauranteAdminService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\Numero;

$e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$val = static fn (string $c): string => (string) ($valores[$c] ?? '');
$idR = (int) $rest['id'];
$slugUrl = '/' . rawurlencode((string) $rest['slug']);
$statusAtual = (string) $rest['status_rest'];
$selo = match ($statusAtual) {
    'ativo' => ['etiqueta-ok', 'circle-check'],
    'pendente' => ['etiqueta-atencao', 'clock'],
    'inativo' => ['etiqueta-neutra', 'power'],
    default => ['etiqueta-alerta', 'triangle-exclamation-point'],
};
$quando = static fn (?string $d): string => $d ? date('d/m/Y H:i', (int) strtotime($d)) : '—';
$situacaoAssinatura = $assinatura === null ? 'Sem assinatura' : match ((string) $assinatura['status']) {
    'aguardando' => 'Aguardando o 1º pagamento',
    'ativa' => (float) $assinatura['valor'] <= 0 ? 'Grátis' : 'Ativa',
    'cancelada' => 'Cancelada (usa até o fim do período)',
    default => (string) $assinatura['status'],
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <a class="voltar-link" href="/painel/restaurantes"><?= Icon::svg('arrow-left', 'icon-sm') ?>Restaurantes</a>
            <h1><?= $e((string) $rest['nome']) ?></h1>
            <p><span class="etiqueta <?= $selo[0] ?>"><?= Icon::svg($selo[1]) ?><?= $e(RestauranteAdminService::rotuloStatus($statusAtual)) ?></span>
                · cadastrado em <?= $e($quando($rest['created_at'] ?? null)) ?> · /<?= $e((string) $rest['slug']) ?></p>
        </div>
        <div class="painel-acoes">
            <a class="btn btn-outline btn-sm" href="<?= $e($slugUrl) ?>" target="_blank" rel="noopener"><?= Icon::svg('external-link', 'icon-sm') ?>Ver cardápio</a>
            <a class="btn btn-primary btn-sm" href="<?= $e($slugUrl . '/gerencia') ?>"><?= Icon::svg('log-in', 'icon-sm') ?>Abrir painel do restaurante</a>
        </div>
    </header>

    <div class="indicadores">
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('receipt') ?>Pedidos</span>
            <strong><?= (int) $movimento['pedidos'] ?></strong>
            <small>último: <?= $e($quando($movimento['ultimo_pedido'])) ?></small>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('banknote') ?>Vendas em 30 dias</span>
            <strong><?= $e(Numero::reais($movimento['vendas_30'])) ?></strong>
            <small><?= (int) $movimento['pedidos_30'] ?> pedidos concluídos</small>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('users') ?>Contas</span>
            <strong><?= count($contas) ?></strong>
            <small>gerente e equipe</small>
        </div>
        <div class="indicador">
            <span class="indicador-topo"><?= Icon::svg('hamburger') ?>Produtos</span>
            <strong><?= (int) $movimento['produtos'] ?></strong>
            <small>no cardápio</small>
        </div>
    </div>

    <?= Campo::resumoErros($erros) ?>

    <form method="post" action="/painel/restaurantes/<?= $idR ?>" novalidate data-validate-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <section class="bloco" aria-labelledby="titulo-dados">
            <div class="bloco-cabecalho">
                <h2 id="titulo-dados"><?= Icon::svg('store') ?>Dados do restaurante</h2>
                <p>Os mesmos dados que o gerente vê no Perfil. O endereço do cardápio (/<?= $e((string) $rest['slug']) ?>) não muda.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome', 'valor' => $val('nome'), 'icone' => 'store', 'erro' => $erros['nome'] ?? null, 'classe' => 'col-inteira', 'attrs' => ['maxlength' => 180, 'data-validate' => 'required']]) ?>
                <?= Campo::texto(['nome' => 'telefone', 'rotulo' => 'Telefone', 'valor' => $val('telefone'), 'icone' => 'phone', 'erro' => $erros['telefone'] ?? null, 'attrs' => ['inputmode' => 'tel', 'data-mask' => 'phone', 'data-validate' => 'phone']]) ?>
                <?= Campo::texto(['nome' => 'email', 'rotulo' => 'E-mail de contato', 'tipo' => 'email', 'valor' => $val('email'), 'icone' => 'mail', 'erro' => $erros['email'] ?? null, 'opcional' => true, 'attrs' => ['maxlength' => 190, 'autocapitalize' => 'none']]) ?>
                <?= Campo::texto(['nome' => 'cnpj', 'rotulo' => 'CNPJ', 'valor' => $val('cnpj'), 'icone' => 'id-card', 'erro' => $erros['cnpj'] ?? null, 'opcional' => true, 'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'cnpj']]) ?>
                <?= Campo::texto(['nome' => 'cep', 'rotulo' => 'CEP', 'valor' => $val('cep'), 'icone' => 'map-pin', 'erro' => $erros['cep'] ?? null, 'opcional' => true, 'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'cep']]) ?>
                <?= Campo::texto(['nome' => 'logradouro', 'rotulo' => 'Rua', 'valor' => $val('logradouro'), 'icone' => 'map-pin', 'erro' => $erros['logradouro'] ?? null, 'opcional' => true, 'attrs' => ['maxlength' => 180]]) ?>
                <?= Campo::texto(['nome' => 'numero', 'rotulo' => 'Número', 'valor' => $val('numero'), 'icone' => 'map-pin', 'erro' => $erros['numero'] ?? null, 'opcional' => true, 'attrs' => ['maxlength' => 20]]) ?>
                <?= Campo::texto(['nome' => 'complemento', 'rotulo' => 'Complemento', 'valor' => $val('complemento'), 'icone' => 'map-pin', 'erro' => $erros['complemento'] ?? null, 'opcional' => true, 'attrs' => ['maxlength' => 120]]) ?>
                <?= Campo::texto(['nome' => 'bairro', 'rotulo' => 'Bairro', 'valor' => $val('bairro'), 'icone' => 'map-pin', 'erro' => $erros['bairro'] ?? null, 'opcional' => true, 'attrs' => ['maxlength' => 120]]) ?>
                <?= Campo::texto(['nome' => 'cidade', 'rotulo' => 'Cidade', 'valor' => $val('cidade'), 'icone' => 'map-pin', 'erro' => $erros['cidade'] ?? null, 'opcional' => true, 'attrs' => ['maxlength' => 120]]) ?>
                <?= Campo::selecao(['nome' => 'estado', 'rotulo' => 'Estado', 'valor' => $val('estado'), 'icone' => 'map-pin', 'erro' => $erros['estado'] ?? null, 'opcional' => true, 'vazio' => 'Escolha'], PerfilService::UFS) ?>
                <?= Campo::texto(['nome' => 'ponto_referencia', 'rotulo' => 'Ponto de referência', 'valor' => $val('ponto_referencia'), 'icone' => 'map-pin', 'erro' => $erros['ponto_referencia'] ?? null, 'opcional' => true, 'classe' => 'col-inteira', 'attrs' => ['maxlength' => 180]]) ?>
            </div>
            <div class="bloco-rodape">
                <button class="btn btn-primary btn-sm" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar dados</button>
            </div>
        </section>
    </form>

    <section class="bloco" id="situacao" aria-labelledby="titulo-situacao">
        <div class="bloco-cabecalho">
            <h2 id="titulo-situacao"><?= Icon::svg('shield-check') ?>Situação</h2>
            <p>Agora: <strong><?= $e(RestauranteAdminService::rotuloStatus($statusAtual)) ?></strong>.
                <?= match ($statusAtual) {
                    'pendente' => 'Aguardando o 1º pagamento: a equipe monta tudo, mas o cardápio só aparece depois de pagar.',
                    'inadimplente' => 'Fatura atrasada: só o gerente entra, e só na Assinatura. Libera sozinho quando o Asaas confirmar o pagamento.',
                    'suspenso' => 'Ninguém da equipe entra e o cardápio está fora do ar.',
                    'inativo' => 'Encerrado: fora do catálogo e sem acesso. Os dados estão guardados.',
                    default => 'Funcionando normalmente.',
                } ?></p>
        </div>
        <div class="gestao-acoes-status">
            <?php foreach (RestauranteAdminService::ACOES_STATUS as $novoStatus => [$rotuloAcao, $explicacao]): if ($novoStatus === $statusAtual) { continue; } ?>
                <form class="gestao-acao" method="post" action="/painel/restaurantes/<?= $idR ?>/status"
                      data-confirm="<?= $e($explicacao) ?>" data-confirm-titulo="<?= $e($rotuloAcao . ' ' . $rest['nome'] . '?') ?>" data-confirm-acao="<?= $e($rotuloAcao) ?>"<?= $novoStatus === 'ativo' ? '' : ' data-confirm-perigo="1"' ?>>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="status" value="<?= $e($novoStatus) ?>">
                    <strong><?= Icon::svg($novoStatus === 'ativo' ? 'rotate-ccw' : ($novoStatus === 'suspenso' ? 'pause' : 'power'), 'icon-sm') ?><?= $e($rotuloAcao) ?></strong>
                    <small><?= $e($explicacao) ?></small>
                    <?php if ($novoStatus !== 'ativo'): ?>
                        <label class="sr-only" for="motivo-<?= $e($novoStatus) ?>">Motivo</label>
                        <input class="gestao-motivo" id="motivo-<?= $e($novoStatus) ?>" name="motivo" maxlength="200" placeholder="Motivo (ex.: pediu para pausar)" required>
                    <?php endif; ?>
                    <button class="btn <?= $novoStatus === 'ativo' ? 'btn-wine' : 'btn-outline' ?> btn-sm" type="submit"><?= $e($rotuloAcao) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="bloco" id="plano" aria-labelledby="titulo-plano">
        <div class="bloco-cabecalho">
            <h2 id="titulo-plano"><?= Icon::svg('tags') ?>Plano e assinatura</h2>
            <p><?php if ($assinatura === null): ?>Sem assinatura no momento.<?php else: ?>
                <strong><?= $e((string) $assinatura['plano_nome']) ?></strong> · <?= $e(AssinaturaService::preco((string) $assinatura['valor'], (string) $assinatura['periodo'])) ?>
                · <?= $e($situacaoAssinatura) ?><?= $assinatura['vence_em'] ? ' · próximo vencimento ' . $e(date('d/m/Y', (int) strtotime((string) $assinatura['vence_em']))) : '' ?>
            <?php endif; ?></p>
        </div>
        <form class="gestao-linha" method="post" action="/painel/restaurantes/<?= $idR ?>/plano"
              data-confirm="A assinatura muda na hora. Plano pago gera ou ajusta a cobrança no Asaas da plataforma." data-confirm-titulo="Trocar o plano de <?= $e((string) $rest['nome']) ?>?" data-confirm-acao="Trocar plano">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <label class="gestao-select">
                <span class="sr-only">Novo plano</span>
                <select name="plano_id" required>
                    <option value="">Escolha o novo plano</option>
                    <?php foreach ($planos as $pl): ?>
                        <option value="<?= (int) $pl['id'] ?>"<?= $assinatura !== null && (int) $assinatura['plano_id'] === (int) $pl['id'] ? ' disabled' : '' ?>>
                            <?= $e((string) $pl['nome']) ?> - <?= (float) $pl['valor'] <= 0 ? 'grátis' : $e(Numero::reais((string) $pl['valor']) . ' ' . (AssinaturaService::ROTULO_PERIODO[$pl['periodo']] ?? '')) ?><?= (int) $pl['visibilidade'] === 1 ? '' : ' (privado)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-wine btn-sm" type="submit"><?= Icon::svg('arrow-right-left', 'icon-sm') ?>Trocar plano</button>
        </form>
    </section>

    <section class="bloco" id="contas" aria-labelledby="titulo-contas">
        <div class="bloco-cabecalho">
            <h2 id="titulo-contas"><?= Icon::svg('users') ?>Contas do restaurante</h2>
            <a class="btn btn-outline btn-sm" href="/painel/usuarios/novo?restaurante=<?= $idR ?>"><?= Icon::svg('user-plus', 'icon-sm') ?>Nova conta</a>
        </div>
        <?php if ($contas === []): ?>
            <div class="lista-vazia"><?= Icon::svg('users') ?><strong>Nenhuma conta</strong><p>Crie a conta do gerente para alguém administrar o restaurante.</p></div>
        <?php else: ?>
            <ul class="gestao-contas">
                <?php foreach ($contas as $c): ?>
                    <li>
                        <a href="/painel/usuarios/<?= (int) $c['id'] ?>">
                            <strong><?= $e($c['nome'] . ' ' . $c['sobrenome']) ?></strong>
                            <small><?= $e(Autorizacao::rotuloCargo((string) $c['cargo'])) ?> · <?= $e((string) $c['usuario']) ?> · último acesso <?= $e($quando($c['ultimo_acesso'])) ?></small>
                        </a>
                        <?php if ((int) $c['ativo'] !== 1): ?><span class="etiqueta etiqueta-neutra"><?= Icon::svg('lock') ?>Bloqueada</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="bloco bloco-perigo" id="excluir" aria-labelledby="titulo-excluir">
        <div class="bloco-cabecalho">
            <h2 id="titulo-excluir"><?= Icon::svg('trash') ?>Excluir restaurante</h2>
            <p>Apaga de vez o restaurante, as contas, o cardápio e as configurações. Só para cadastros sem movimento (feitos por engano ou de teste).</p>
        </div>
        <?php if ($bloqueioExcluir !== null): ?>
            <p class="notice notice-info"><?= Icon::svg('info') ?><span><?= $e($bloqueioExcluir) ?></span></p>
        <?php else: ?>
            <form class="gestao-linha" method="post" action="/painel/restaurantes/<?= $idR ?>/excluir"
                  data-confirm="Restaurante, contas e cardápio serão apagados. Isso não pode ser desfeito." data-confirm-titulo="Excluir <?= $e((string) $rest['nome']) ?>?" data-confirm-acao="Excluir restaurante" data-confirm-perigo="1">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <label class="gestao-confirmar">
                    <span>Para confirmar, digite <strong><?= $e((string) $rest['slug']) ?></strong></span>
                    <input name="confirmacao" autocomplete="off" autocapitalize="none" spellcheck="false" required>
                </label>
                <button class="btn btn-perigo btn-sm" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Excluir restaurante</button>
            </form>
        <?php endif; ?>
    </section>
</div>
