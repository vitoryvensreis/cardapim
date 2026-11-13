<?php
/**
 * Finalizar pedido (/{slug}/finalizar). A sacola vem do aparelho (localStorage); o servidor recalcula tudo.
 * Espera: $restaurante, $config (CheckoutPublico::config).
 */
use Cardapim\Core\Icon;
use Cardapim\Services\Gerencia\PerfilService;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$modos = $config['modos'];
$formas = $config['formas'];
$formasEntrega = array_values(array_diff($formas, ['pix_online']));
$rotulosForma = [
    'pix_entrega' => ['Pix', 'qr-code'],
    'debito' => ['Cartão de débito', 'credit-card'],
    'credito' => ['Cartão de crédito', 'credit-card'],
    'dinheiro' => ['Dinheiro', 'banknote'],
];
$json = json_encode([
    'modos' => array_keys($modos),
    'formas' => $formas,
    'aberto' => $config['aberto'],
    'pixPrazo' => $config['pix_prazo_minutos'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<div class="ck" data-checkout data-config="<?= $e((string) $json) ?>">
    <div class="ck-cabecalho">
        <h1>Finalizar pedido</h1>
        <p><?= $e((string) $restaurante['nome']) ?></p>
    </div>

    <?php if (!$config['recebe_pedidos']): ?>
        <div class="ck-vazio">
            <?= Icon::svg('store', 'icon icon-lg') ?>
            <strong>Este restaurante ainda não recebe pedidos pelo <?= marca_html() ?></strong>
            <p>Você pode ver o cardápio e falar direto com o restaurante.</p>
            <a class="btn btn-primary" href="/<?= $e((string) $restaurante['slug']) ?>">Voltar ao cardápio</a>
        </div>
    <?php else: ?>
    <div class="ck-vazio" data-ck-vazio hidden>
        <?= Icon::svg('shopping-bag', 'icon icon-lg') ?>
        <strong>Sua sacola está vazia</strong>
        <p>Escolha seus itens no cardápio para continuar.</p>
        <a class="btn btn-primary" href="/<?= $e((string) $restaurante['slug']) ?>">Ver cardápio</a>
    </div>

    <?php if (!$config['aberto']): ?>
        <div class="ck-alerta ck-alerta-fechado" role="status"><?= Icon::svg('clock') ?><p><strong>O restaurante está fechado agora.</strong> Você pode revisar o pedido, mas só consegue enviar quando ele abrir.</p></div>
    <?php endif; ?>

    <form class="ck-layout" data-ck-form novalidate>
        <div class="ck-principal">
            <section class="ck-bloco" aria-labelledby="ck-titulo-tipo">
                <h2 id="ck-titulo-tipo"><span class="ck-passo" aria-hidden="true">1</span>Como você quer receber?</h2>
                <div class="ck-opcoes ck-opcoes-tipo" role="radiogroup" aria-labelledby="ck-titulo-tipo">
                    <?php if (isset($modos['entrega'])): ?>
                        <label class="ck-opcao">
                            <input type="radio" name="tipo" value="entrega" data-ck-tipo>
                            <span class="ck-opcao-icone" aria-hidden="true"><?= Icon::svg('truck') ?></span>
                            <span class="ck-opcao-texto"><strong>Entrega</strong><small><?= $modos['entrega'] !== '' ? $e($modos['entrega']) : 'No seu endereço' ?></small></span>
                        </label>
                    <?php endif; ?>
                    <?php if (isset($modos['retirada'])): ?>
                        <label class="ck-opcao">
                            <input type="radio" name="tipo" value="retirada" data-ck-tipo>
                            <span class="ck-opcao-icone" aria-hidden="true"><?= Icon::svg('store') ?></span>
                            <span class="ck-opcao-texto"><strong>Retirar no local</strong><small><?= $modos['retirada'] !== '' ? $e($modos['retirada']) : 'Sem taxa de entrega' ?></small></span>
                        </label>
                    <?php endif; ?>
                </div>
                <p class="ck-retirada-local" data-ck-retirada hidden><?= Icon::svg('map-pin', 'icon-sm') ?><span>Retire em: <strong><?= $e($config['endereco_restaurante'] !== '' ? $config['endereco_restaurante'] : 'endereço do restaurante') ?></strong></span></p>
            </section>

            <section class="ck-bloco" aria-labelledby="ck-titulo-endereco" data-ck-endereco hidden>
                <h2 id="ck-titulo-endereco"><span class="ck-passo" aria-hidden="true">2</span>Endereço de entrega</h2>

                <div class="ck-endereco-salvo" data-ck-salvo hidden>
                    <span class="ck-opcao-icone" aria-hidden="true"><?= Icon::svg('map-pin') ?></span>
                    <p><strong data-ck-salvo-linha1></strong><small data-ck-salvo-linha2></small></p>
                    <button class="ck-link" type="button" data-ck-trocar>Trocar</button>
                </div>

                <div class="ck-campos" data-ck-campos>
                    <div class="ck-campo ck-campo-cep">
                        <label for="ck-cep">CEP</label>
                        <div class="ck-cep-linha">
                            <input id="ck-cep" name="cep" inputmode="numeric" autocomplete="postal-code" maxlength="9" placeholder="00000-000" data-ck-end="cep">
                            <span class="ck-cep-carregando" data-ck-cep-carregando hidden><?= Icon::svg('loader-circle', 'icon icon-girar') ?></span>
                        </div>
                        <small class="ck-ajuda" data-ck-cep-msg>Digite o CEP e preenchemos o resto.</small>
                    </div>
                    <div class="ck-campo ck-campo-largo">
                        <label for="ck-rua">Rua</label>
                        <input id="ck-rua" name="logradouro" autocomplete="address-line1" maxlength="150" data-ck-end="logradouro">
                    </div>
                    <div class="ck-linha-2">
                        <div class="ck-campo">
                            <label for="ck-numero">Número</label>
                            <input id="ck-numero" name="numero" autocomplete="off" maxlength="20" placeholder="Ou S/N" data-ck-end="numero">
                        </div>
                        <div class="ck-campo">
                            <label for="ck-complemento">Complemento <span class="ck-opcional">(opcional)</span></label>
                            <input id="ck-complemento" name="complemento" autocomplete="address-line2" maxlength="100" placeholder="Apto, bloco" data-ck-end="complemento">
                        </div>
                    </div>
                    <div class="ck-campo ck-campo-largo">
                        <label for="ck-bairro">Bairro</label>
                        <input id="ck-bairro" name="bairro" autocomplete="address-level3" maxlength="100" data-ck-end="bairro">
                    </div>
                    <div class="ck-linha-2 ck-linha-cidade">
                        <div class="ck-campo">
                            <label for="ck-cidade">Cidade</label>
                            <input id="ck-cidade" name="cidade" autocomplete="address-level2" maxlength="100" data-ck-end="cidade">
                        </div>
                        <div class="ck-campo">
                            <label for="ck-estado">Estado</label>
                            <select id="ck-estado" name="estado" autocomplete="address-level1" data-ck-end="estado">
                                <option value="">UF</option>
                                <?php foreach (array_keys(PerfilService::UFS) as $uf): ?><option value="<?= $e($uf) ?>"><?= $e($uf) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ck-campo ck-campo-largo">
                        <label for="ck-referencia">Ponto de referência <span class="ck-opcional">(opcional)</span></label>
                        <input id="ck-referencia" name="referencia" maxlength="150" placeholder="Ex.: portão azul, perto da praça" data-ck-end="referencia">
                    </div>
                    <button class="ck-link ck-gps" type="button" data-ck-gps><?= Icon::svg('locate-fixed', 'icon-sm') ?><span data-ck-gps-texto>Estou neste endereço: usar minha localização</span></button>
                </div>

                <div class="ck-entrega" data-ck-entrega role="status" aria-live="polite" hidden></div>
            </section>

            <section class="ck-bloco" aria-labelledby="ck-titulo-pagamento" data-ck-pagamento>
                <h2 id="ck-titulo-pagamento"><span class="ck-passo" aria-hidden="true" data-ck-passo-pag>3</span>Pagamento</h2>
                <?php if (in_array('pix_online', $formas, true)): ?>
                    <p class="ck-grupo">Pagar agora</p>
                    <div class="ck-opcoes" role="radiogroup" aria-label="Pagar agora">
                        <label class="ck-opcao">
                            <input type="radio" name="forma_pagamento" value="pix_online" data-ck-forma>
                            <span class="ck-opcao-icone ck-opcao-icone-pix" aria-hidden="true"><?= Icon::svg('qr-code') ?></span>
                            <span class="ck-opcao-texto"><strong>Pix</strong><small>Aprovação na hora. O pedido vai para a cozinha assim que o Pix cair.</small></span>
                        </label>
                    </div>
                <?php endif; ?>
                <?php if ($formasEntrega !== []): ?>
                    <p class="ck-grupo" data-ck-grupo-entrega>Pagar na entrega</p>
                    <div class="ck-opcoes ck-opcoes-grade" role="radiogroup" aria-label="Pagar na entrega ou na retirada">
                        <?php foreach ($formasEntrega as $forma): [$rotulo, $icone] = $rotulosForma[$forma]; ?>
                            <label class="ck-opcao ck-opcao-compacta">
                                <input type="radio" name="forma_pagamento" value="<?= $e($forma) ?>" data-ck-forma>
                                <span class="ck-opcao-icone" aria-hidden="true"><?= Icon::svg($icone) ?></span>
                                <span class="ck-opcao-texto"><strong><?= $e($rotulo) ?></strong></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="ck-extra" data-ck-troco hidden>
                    <label class="ck-check"><input type="checkbox" data-ck-precisa-troco><span>Vou precisar de troco</span></label>
                    <div class="ck-campo" data-ck-troco-campo hidden>
                        <label for="ck-troco">Troco para quanto?</label>
                        <input id="ck-troco" name="troco_para" inputmode="numeric" placeholder="R$ 0,00" data-ck-dinheiro>
                        <small class="ck-ajuda" data-ck-troco-msg>Assim o entregador já leva o troco certo.</small>
                    </div>
                </div>

                <div class="ck-extra" data-ck-cpf hidden>
                    <div class="ck-campo">
                        <label for="ck-cpf">CPF de quem vai pagar</label>
                        <input id="ck-cpf" name="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" data-ck-cpf-campo>
                        <small class="ck-ajuda">O Pix exige o CPF. Pedimos só uma vez.</small>
                    </div>
                </div>
            </section>

            <section class="ck-bloco ck-bloco-obs">
                <details data-ck-obs-detalhes>
                    <summary><?= Icon::svg('pencil', 'icon-sm') ?>Alguma observação para o restaurante?</summary>
                    <label class="sr-only" for="ck-obs">Observação do pedido</label>
                    <textarea id="ck-obs" name="observacao" rows="3" maxlength="300" placeholder="Ex.: tocar o interfone 12, sem talheres"></textarea>
                </details>
            </section>
        </div>

        <aside class="ck-resumo" aria-labelledby="ck-titulo-resumo">
            <div class="ck-resumo-caixa">
                <div class="ck-resumo-topo">
                    <h2 id="ck-titulo-resumo">Seu pedido</h2>
                    <a class="ck-link" href="/<?= $e((string) $restaurante['slug']) ?>">Editar</a>
                </div>
                <ul class="ck-itens" data-ck-itens></ul>

                <div class="ck-fidelidade" data-ck-fidelidade role="status" aria-live="polite" hidden>
                    <span class="ck-fidelidade-icone" aria-hidden="true"><?= Icon::svg('gift') ?></span>
                    <div class="ck-fidelidade-texto">
                        <strong data-ck-fid-titulo>Cartão fidelidade</strong>
                        <small data-ck-fid-texto></small>
                        <span class="ck-fidelidade-pontos" data-ck-fid-pontos aria-hidden="true"></span>
                    </div>
                </div>

                <div class="ck-cupom" data-ck-cupom>
                    <details data-ck-cupom-detalhes>
                        <summary><?= Icon::svg('ticket-percent', 'icon-sm') ?>Tenho um cupom de desconto</summary>
                        <div class="ck-cupom-linha">
                            <label class="sr-only" for="ck-cupom">Código do cupom</label>
                            <input id="ck-cupom" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Digite o código" data-ck-cupom-campo>
                            <button class="btn btn-outline btn-sm" type="button" data-ck-cupom-aplicar>Aplicar</button>
                        </div>
                    </details>
                    <div class="ck-cupom-ok" data-ck-cupom-ok hidden>
                        <?= Icon::svg('circle-check', 'icon-sm') ?>
                        <span data-ck-cupom-ok-texto></span>
                        <button class="ck-link" type="button" data-ck-cupom-remover>Remover</button>
                    </div>
                    <small class="ck-cupom-msg" data-ck-cupom-msg role="status" aria-live="polite" hidden></small>
                </div>

                <dl class="ck-totais">
                    <div><dt>Subtotal</dt><dd data-ck-subtotal>R$ 0,00</dd></div>
                    <div data-ck-linha-taxa><dt>Taxa de entrega</dt><dd data-ck-taxa>—</dd></div>
                    <div class="ck-desconto" data-ck-linha-desconto hidden><dt data-ck-desconto-rotulo>Desconto</dt><dd data-ck-desconto>− R$ 0,00</dd></div>
                    <div class="ck-total"><dt>Total</dt><dd data-ck-total>R$ 0,00</dd></div>
                </dl>
                <p class="ck-aviso-min" data-ck-minimo hidden></p>
                <div class="ck-erro" data-ck-erro role="alert" hidden></div>
                <button class="btn btn-primary btn-block ck-enviar ck-enviar-lateral" type="submit" data-ck-enviar>
                    <span data-ck-enviar-texto>Fazer pedido</span><span class="ck-enviar-total" data-ck-enviar-total></span>
                </button>
                <p class="ck-seguro"><?= Icon::svg('shield-check', 'icon-sm') ?>Os valores são conferidos pelo restaurante na hora do pedido.</p>
            </div>
        </aside>

        <div class="ck-barra" data-ck-barra>
            <div class="ck-barra-total"><small>Total</small><strong data-ck-barra-total>R$ 0,00</strong></div>
            <button class="btn btn-primary ck-enviar" type="submit" data-ck-enviar>
                <span data-ck-enviar-texto>Fazer pedido</span>
            </button>
        </div>
    </form>
    <?php endif; ?>
    <div class="cd-toast" data-toast role="status" aria-live="polite" hidden></div>
</div>
