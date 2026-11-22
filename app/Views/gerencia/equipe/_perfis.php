<?php
/**
 * Regras de cada funcao (painel do entregador e perfil garcom), na aba Equipe.
 * Espera: $entregadorVeValores, $permissoesGarcom, $base, $csrf, $e.
 */
use Cardapim\Core\Icon;
?>
<section class="equipe-perfis" id="perfis" aria-labelledby="titulo-perfis">
    <h2 class="equipe-perfis-titulo" id="titulo-perfis"><?= Icon::svg('shield-check') ?>Regras de cada função</h2>
    <section class="bloco" aria-labelledby="titulo-entregador" id="entregador">
        <div class="bloco-cabecalho">
            <h2 id="titulo-entregador"><?= Icon::svg('bike') ?>Painel do entregador</h2>
            <p>Você decide o que o entregador vê no celular dele.</p>
        </div>
        <form method="post" action="<?= $e($base . '/equipe/regras/entregador') ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <fieldset class="opcoes-modo">
                <legend class="sr-only">Valores no painel do entregador</legend>
                <label class="opcao-modo">
                    <input type="checkbox" name="entregador_ve_valores" value="1"<?= $entregadorVeValores ? ' checked' : '' ?>>
                    <span>
                        <strong><?= Icon::svg('wallet', 'icon-sm') ?>Mostrar quanto ele ganhou em taxas</strong>
                        <small>Com a opção ligada, ele vê o total de taxas de hoje e dos últimos 7 dias e a taxa de cada entrega feita. Desligada, ele vê só a quantidade de entregas.</small>
                        <small>O valor que ele deve receber do cliente aparece sempre, para ele poder cobrar.</small>
                    </span>
                </label>
            </fieldset>
            <div class="form-acoes">
                <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar painel do entregador</button>
            </div>
        </form>
    </section>

    <section class="bloco" aria-labelledby="titulo-perfil-garcom" id="perfil-garcom">
        <div class="bloco-cabecalho">
            <h2 id="titulo-perfil-garcom"><?= Icon::svg('hand-platter') ?>Perfil garçom</h2>
            <p>O que o garçom pode fazer depois que o pedido já foi lançado na comanda. O gerente e o caixa sempre podem.</p>
        </div>
        <form method="post" action="<?= $e($base . '/equipe/regras/garcom') ?>">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <fieldset class="opcoes-modo opcoes-garcom">
                <legend class="sr-only">Permissões do garçom</legend>
                <label class="opcao-modo">
                    <input type="checkbox" name="remover_itens" value="1"<?= (int) ($permissoesGarcom['remover_itens'] ?? 0) === 1 ? ' checked' : '' ?>>
                    <span>
                        <strong><?= Icon::svg('trash', 'icon-sm') ?>Remover itens já lançados</strong>
                        <small>O garçom pode tirar da comanda um item que já foi lançado, informando o motivo. O estoque volta.</small>
                    </span>
                </label>
                <label class="opcao-modo">
                    <input type="checkbox" name="cancelar_comanda" value="1"<?= (int) ($permissoesGarcom['cancelar_comanda'] ?? 0) === 1 ? ' checked' : '' ?>>
                    <span>
                        <strong><?= Icon::svg('ban', 'icon-sm') ?>Cancelar comanda</strong>
                        <small>O garçom pode cancelar a comanda inteira (sem pagamento). Os itens saem da conta e o estoque volta.</small>
                    </span>
                </label>
                <input type="hidden" name="fechar_conta_enviado" value="1">
                <label class="opcao-modo">
                    <input type="checkbox" name="fechar_conta" value="1"<?= (int) ($permissoesGarcom['fechar_conta'] ?? 1) === 1 ? ' checked' : '' ?>>
                    <span>
                        <strong><?= Icon::svg('receipt', 'icon-sm') ?>Fechar a conta</strong>
                        <small>O garçom recebe o pagamento na mesa (dinheiro, cartão ou Pix) e fecha a conta. Desmarcado, só o caixa fecha.</small>
                    </span>
                </label>
            </fieldset>
            <p class="texto-suave garcom-permissao-dica"><?= Icon::svg('info', 'icon-sm') ?>Desmarcado, o garçom não vê esses botões e precisa pedir ao gerente ou ao caixa. Tudo fica registrado no histórico.</p>
            <div class="form-acoes">
                <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar perfil garçom</button>
            </div>
        </form>
    </section>

</section>
