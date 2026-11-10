<?php
/**
 * Perfil do restaurante. Espera: $perfil, $dados, $horario (enviado ou null), $turnosPorDia, $sempreAberto,
 * $abertoAgora, $semHorario, $erros (por formulario), $restaurante, $base, $csrf.
 */
use Cardapim\Core\Icon;
use Cardapim\Modules\Restaurante\HorarioFuncionamento;
use Cardapim\Modules\Restaurante\LogoUpload;
use Cardapim\Services\Gerencia\PerfilService;
use Cardapim\Shared\Html\Campo;
use Cardapim\Shared\RestauranteLogo;
use Cardapim\Shared\Validation\Documento;

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$d = static fn (string $campo): string => (string) ($dados[$campo] ?? '');
$errosDados = $erros['dados'] ?? [];
$errosHorario = $erros['horarios'] ?? [];
$logo = RestauranteLogo::urlSegura($perfil['logo_path'] ?? null);
$modoSempre = $horario !== null ? ($horario['modo'] ?? '') === 'sempre' : $sempreAberto;

/** Valor de um campo de horario: o que foi enviado (se houve erro) ou o que esta salvo. */
$hv = static function (int $dia, string $campo) use ($horario, $turnosPorDia): string {
    if ($horario !== null) {
        $v = $horario['dia'][$dia][$campo] ?? '';
        return is_string($v) ? $v : '';
    }
    $turnos = $turnosPorDia[$dia];
    return match ($campo) {
        'aberto' => $turnos !== [] ? '1' : '',
        'abre1' => $turnos[0]['abre'] ?? '',
        'fecha1' => $turnos[0]['fecha'] ?? '',
        'turno2' => isset($turnos[1]) ? '1' : '',
        'abre2' => $turnos[1]['abre'] ?? '',
        'fecha2' => $turnos[1]['fecha'] ?? '',
        default => '',
    };
};
?>
<div class="painel-page painel-page-form">
    <header class="painel-cabecalho">
        <div>
            <h1>Perfil do restaurante</h1>
            <p>É assim que os clientes veem o seu restaurante no Cardapim.</p>
        </div>
        <div class="painel-acoes">
            <?php if ($semHorario): ?>
                <span class="etiqueta etiqueta-neutra"><?= Icon::svg('clock') ?>Horário não informado</span>
            <?php else: ?>
                <span class="etiqueta <?= $abertoAgora ? 'etiqueta-ok' : 'etiqueta-alerta' ?>"><?= Icon::svg('clock') ?><?= $abertoAgora ? 'Aberto agora' : 'Fechado agora' ?></span>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="/<?= $e((string) $restaurante['slug']) ?>" target="_blank" rel="noopener"><?= Icon::svg('arrow-up-right', 'icon-sm') ?>Ver minha página</a>
        </div>
    </header>

    <section class="bloco" id="logo" aria-labelledby="titulo-logo">
        <div class="bloco-cabecalho"><h2 id="titulo-logo"><?= Icon::svg('image') ?>Logo</h2></div>
        <div class="logo-editor">
            <span class="logo-previa" data-logo-previa>
                <?php if ($logo !== null): ?>
                    <img src="<?= $e($logo) ?>" width="104" height="104" alt="Logo atual de <?= $e((string) $perfil['nome']) ?>">
                <?php else: ?>
                    <span aria-hidden="true"><?= $e(RestauranteLogo::iniciais((string) ($perfil['nome'] ?? ''))) ?></span>
                <?php endif; ?>
            </span>
            <div class="logo-acoes">
                <form method="post" action="<?= $e($base . '/perfil/logo') ?>" enctype="multipart/form-data" class="linha-form" data-logo-form>
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= LogoUpload::TAMANHO_MAXIMO ?>">
                    <label class="btn btn-outline btn-sm arquivo-input">
                        <?= Icon::svg('upload', 'icon-sm') ?><span data-logo-rotulo><?= $logo !== null ? 'Trocar logo' : 'Escolher imagem' ?></span>
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required data-logo-arquivo>
                    </label>
                    <button class="btn btn-primary btn-sm" type="submit" data-logo-enviar data-submit-label="Enviando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar logo</button>
                </form>
                <small>PNG, JPG ou WebP de até 2 MB. Imagem quadrada fica melhor.</small>
                <p class="field-msg ficha-erro" data-logo-erro role="alert" hidden></p>
                <?php if ($logo !== null): ?>
                    <form method="post" action="<?= $e($base . '/perfil/logo/remover') ?>"
                          data-confirm="No lugar da logo vão aparecer as iniciais do restaurante." data-confirm-titulo="Remover a logo?" data-confirm-acao="Remover logo" data-confirm-perigo="1">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <button class="link-acao link-perigo" type="submit"><?= Icon::svg('trash', 'icon-sm') ?>Remover logo</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <form method="post" action="<?= $e($base . '/perfil') ?>" novalidate data-validate-form id="dados">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?= Campo::resumoErros($errosDados) ?>

        <section class="bloco" aria-labelledby="titulo-dados">
            <div class="bloco-cabecalho"><h2 id="titulo-dados"><?= Icon::svg('store') ?>Dados do restaurante</h2></div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'nome', 'rotulo' => 'Nome do restaurante', 'valor' => $d('nome'), 'icone' => 'store', 'erro' => $errosDados['nome'] ?? null, 'classe' => 'col-inteira',
                    'dica' => 'O endereço da sua página continua o mesmo, mesmo trocando o nome.', 'attrs' => ['maxlength' => 180, 'data-validate' => 'required', 'autocomplete' => 'organization']]) ?>
                <?= Campo::texto(['nome' => 'telefone', 'rotulo' => 'Telefone ou WhatsApp', 'valor' => $d('telefone'), 'icone' => 'phone', 'erro' => $errosDados['telefone'] ?? null,
                    'dica' => 'Os clientes usam para falar com você.', 'attrs' => ['inputmode' => 'tel', 'data-mask' => 'phone', 'data-validate' => 'phone', 'autocomplete' => 'tel', 'placeholder' => '(00) 00000-0000']]) ?>
                <?= Campo::texto(['nome' => 'email', 'rotulo' => 'E-mail de contato', 'tipo' => 'email', 'valor' => $d('email'), 'icone' => 'mail', 'erro' => $errosDados['email'] ?? null, 'opcional' => true,
                    'attrs' => ['maxlength' => 190, 'autocomplete' => 'email', 'autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
            </div>
            <dl class="dados-fixos">
                <div><dt>CNPJ</dt><dd><?= !empty($perfil['cnpj']) ? $e(Documento::formatarCnpj((string) $perfil['cnpj'])) : 'Não informado' ?></dd></div>
                <div><dt>Endereço da sua página</dt><dd>/<?= $e((string) $restaurante['slug']) ?></dd></div>
            </dl>
            <p class="texto-suave">Para mudar o CNPJ ou o endereço da página, fale com o suporte.</p>
        </section>

        <section class="bloco" aria-labelledby="titulo-endereco">
            <div class="bloco-cabecalho">
                <h2 id="titulo-endereco"><?= Icon::svg('map-pin') ?>Endereço</h2>
                <p>Onde fica o restaurante. Usado para entregas e para os clientes encontrarem você.</p>
            </div>
            <div class="form-grade">
                <?= Campo::texto(['nome' => 'cep', 'rotulo' => 'CEP', 'valor' => $d('cep'), 'icone' => 'map-pin', 'erro' => $errosDados['cep'] ?? null, 'opcional' => true,
                    'attrs' => ['inputmode' => 'numeric', 'data-mask' => 'cep', 'autocomplete' => 'postal-code', 'placeholder' => '00000-000']]) ?>
                <?= Campo::selecao(['nome' => 'estado', 'rotulo' => 'Estado', 'valor' => $d('estado'), 'icone' => 'map-pin', 'erro' => $errosDados['estado'] ?? null, 'opcional' => true,
                    'vazio' => 'Escolha o estado', 'attrs' => ['autocomplete' => 'address-level1']], PerfilService::UFS) ?>
                <?= Campo::texto(['nome' => 'cidade', 'rotulo' => 'Cidade', 'valor' => $d('cidade'), 'icone' => 'map-pin', 'erro' => $errosDados['cidade'] ?? null, 'opcional' => true,
                    'attrs' => ['maxlength' => 120, 'autocomplete' => 'address-level2']]) ?>
                <?= Campo::texto(['nome' => 'bairro', 'rotulo' => 'Bairro', 'valor' => $d('bairro'), 'icone' => 'map-pin', 'erro' => $errosDados['bairro'] ?? null, 'opcional' => true,
                    'attrs' => ['maxlength' => 120, 'autocomplete' => 'address-level3']]) ?>
                <?= Campo::texto(['nome' => 'logradouro', 'rotulo' => 'Rua / Avenida', 'valor' => $d('logradouro'), 'icone' => 'map-pin', 'erro' => $errosDados['logradouro'] ?? null, 'opcional' => true,
                    'classe' => 'col-inteira', 'attrs' => ['maxlength' => 180, 'autocomplete' => 'address-line1']]) ?>
                <?= Campo::texto(['nome' => 'numero', 'rotulo' => 'Número', 'valor' => $d('numero'), 'icone' => 'map-pin', 'erro' => $errosDados['numero'] ?? null, 'opcional' => true,
                    'dica' => 'Sem número? Escreva S/N.', 'attrs' => ['maxlength' => 20]]) ?>
                <?= Campo::texto(['nome' => 'complemento', 'rotulo' => 'Complemento', 'valor' => $d('complemento'), 'icone' => 'map-pin', 'erro' => $errosDados['complemento'] ?? null, 'opcional' => true,
                    'dica' => 'Exemplo: Loja 2', 'attrs' => ['maxlength' => 120, 'autocomplete' => 'address-line2']]) ?>
                <?= Campo::texto(['nome' => 'ponto_referencia', 'rotulo' => 'Ponto de referência', 'valor' => $d('ponto_referencia'), 'icone' => 'compass', 'erro' => $errosDados['ponto_referencia'] ?? null, 'opcional' => true,
                    'classe' => 'col-inteira', 'dica' => 'Ajuda o entregador e o cliente. Exemplo: em frente à praça.', 'attrs' => ['maxlength' => 180]]) ?>
            </div>
        </section>

        <div class="form-acoes">
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar dados e endereço</button>
        </div>
    </form>

    <form method="post" action="<?= $e($base . '/perfil/horarios') ?>" id="horarios" class="horarios-form" data-horarios-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <section class="bloco" aria-labelledby="titulo-horarios">
            <div class="bloco-cabecalho">
                <h2 id="titulo-horarios"><?= Icon::svg('calendar-clock') ?>Horário de funcionamento</h2>
                <p>Os clientes veem se você está aberto. Fora do horário, o restaurante aparece como fechado.</p>
            </div>
            <?php if ($errosHorario !== []): ?>
                <div class="notice notice-warning erros-form" role="alert" tabindex="-1" data-erros-form>
                    <?= Icon::svg('triangle-exclamation-point') ?>
                    <div><strong>Confira os horários:</strong><ul><?php foreach ($errosHorario as $msg): ?><li><?= $e($msg) ?></li><?php endforeach; ?></ul></div>
                </div>
            <?php endif; ?>

            <fieldset class="opcoes-modo">
                <legend class="sr-only">Como o restaurante funciona</legend>
                <label class="opcao-modo">
                    <input type="radio" name="modo" value="semana"<?= $modoSempre ? '' : ' checked' ?>>
                    <span><strong>Horários por dia da semana</strong><small>Escolha os dias que abre e os horários de cada um.</small></span>
                </label>
                <label class="opcao-modo">
                    <input type="radio" name="modo" value="sempre"<?= $modoSempre ? ' checked' : '' ?>>
                    <span><strong>Sempre aberto (24 horas)</strong><small>Todos os dias, o dia inteiro.</small></span>
                </label>
            </fieldset>

            <div class="horarios" data-horarios>
                <?php foreach (HorarioFuncionamento::DIAS as $dia => $nomeDia):
                    $erroDia = $errosHorario['dia' . $dia] ?? null;
                    $aberto = $hv($dia, 'aberto') === '1';
                ?>
                    <div class="horario-dia<?= $erroDia ? ' is-invalido' : '' ?>" data-dia="<?= $dia ?>">
                        <div class="horario-topo">
                            <strong><?= $e($nomeDia) ?></strong>
                            <label class="chave-mini">
                                <input type="checkbox" name="dia[<?= $dia ?>][aberto]" value="1"<?= $aberto ? ' checked' : '' ?> data-aberto>
                                <span class="alternador-chave" aria-hidden="true"></span>
                                <span class="chave-texto" data-texto-aberto>Abre</span><span class="chave-texto" data-texto-fechado>Fechado</span>
                            </label>
                        </div>
                        <div class="horario-turnos">
                            <div class="horario-turno">
                                <label><span class="sr-only"><?= $e($nomeDia) ?>: abre às</span><input type="time" name="dia[<?= $dia ?>][abre1]" value="<?= $e($hv($dia, 'abre1')) ?>" step="300"></label>
                                <span>até</span>
                                <label><span class="sr-only"><?= $e($nomeDia) ?>: fecha às</span><input type="time" name="dia[<?= $dia ?>][fecha1]" value="<?= $e($hv($dia, 'fecha1')) ?>" step="300"></label>
                            </div>
                            <label class="check-mini">
                                <input type="checkbox" name="dia[<?= $dia ?>][turno2]" value="1"<?= $hv($dia, 'turno2') === '1' ? ' checked' : '' ?> data-turno2>
                                <span>Fecha no meio do dia? Adicionar segundo horário</span>
                            </label>
                            <div class="horario-turno horario-turno-2">
                                <label><span class="sr-only"><?= $e($nomeDia) ?>: reabre às</span><input type="time" name="dia[<?= $dia ?>][abre2]" value="<?= $e($hv($dia, 'abre2')) ?>" step="300"></label>
                                <span>até</span>
                                <label><span class="sr-only"><?= $e($nomeDia) ?>: fecha de novo às</span><input type="time" name="dia[<?= $dia ?>][fecha2]" value="<?= $e($hv($dia, 'fecha2')) ?>" step="300"></label>
                            </div>
                            <button class="link-acao" type="button" data-copiar-dia><?= Icon::svg('copy', 'icon-sm') ?>Usar este horário em todos os dias</button>
                            <?php if ($erroDia): ?><p class="field-msg ficha-erro"><?= $e($erroDia) ?></p><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="texto-suave">Fecha depois da meia-noite? Coloque normalmente, por exemplo, das 18:00 até as 02:00.</p>
            </div>
        </section>
        <div class="form-acoes">
            <button class="btn btn-primary" type="submit" data-submit-label="Salvando..."><?= Icon::svg('save', 'icon-sm') ?>Salvar horário</button>
        </div>
    </form>
</div>
