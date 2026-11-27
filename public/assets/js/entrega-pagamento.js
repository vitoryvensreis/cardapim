/* Aba Entrega e pagamento: mostra os tempos so do que esta marcado e pega a localizacao do aparelho. */
(function () {
  'use strict';

  // Tempo de entrega/retirada aparece so quando o modo esta marcado.
  document.querySelectorAll('[data-modo]').forEach(function (caixa) {
    var alvo = document.querySelector('[data-tempo="' + caixa.getAttribute('data-modo') + '"]');
    if (!alvo) { return; }
    var atualizar = function () { alvo.hidden = !caixa.checked; };
    caixa.addEventListener('change', atualizar);
    atualizar();
  });

  // Pix pelo app ligado desliga o Pix na entrega (o servidor tambem garante).
  var pixApp = document.querySelector('[data-pix-online]');
  var pixEntrega = document.querySelector('[data-pix-entrega]');
  var pixAviso = document.querySelector('[data-pix-entrega-aviso]');
  if (pixApp && pixEntrega) {
    var pixEntregaAntes = pixEntrega.checked;
    var travarPix = function () {
      var ligado = pixApp.checked && !pixApp.disabled;
      if (ligado) {
        if (!pixEntrega.disabled) { pixEntregaAntes = pixEntrega.checked; }
        pixEntrega.checked = false;
      } else if (pixEntrega.disabled) {
        pixEntrega.checked = pixEntregaAntes;
      }
      pixEntrega.disabled = ligado;
      if (pixAviso) { pixAviso.hidden = !ligado; }
    };
    pixApp.addEventListener('change', travarPix);
    travarPix();
  }

  // Taxa por forma de pagamento: todas as linhas ficam visiveis (a taxa vale tambem na mesa, no balcao e no garcom,
  // mesmo que a forma esteja desligada no cardapio online). O campo de valor so aparece com uma taxa escolhida.
  var blocoTaxas = document.querySelector('[data-taxas]');
  if (blocoTaxas) {
    var linhas = Array.prototype.slice.call(blocoTaxas.querySelectorAll('[data-taxa-linha]'));
    linhas.forEach(function (linha) {
      var tipo = linha.querySelector('[data-taxa-tipo]');
      var valorCaixa = linha.querySelector('[data-taxa-valor-caixa]');
      var prefixo = linha.querySelector('[data-taxa-prefixo]');
      var valor = linha.querySelector('[data-taxa-valor]');
      if (!tipo || !valorCaixa) { return; }
      tipo.addEventListener('change', function () {
        valorCaixa.hidden = tipo.value === '';
        if (prefixo) { prefixo.textContent = tipo.value === 'fixo' ? 'R$' : '%'; }
        if (valor) {
          valor.placeholder = tipo.value === 'fixo' ? '1,00' : '3,5';
          if (tipo.value !== '') { valor.focus(); }
        }
      });
    });
  }

  // "Usar a localizacao deste aparelho"
  var form = document.querySelector('[data-local-form]');
  var botao = document.querySelector('[data-usar-local]');
  if (!form || !botao) { return; }
  var rotulo = botao.querySelector('[data-usar-local-rotulo]');
  var erro = document.querySelector('[data-local-erro]');
  var textoOriginal = rotulo ? rotulo.textContent : '';
  var mostrarErro = function (mensagem) {
    if (erro) { erro.textContent = mensagem; erro.hidden = false; }
    botao.disabled = false;
    if (rotulo) { rotulo.textContent = textoOriginal; }
  };

  botao.addEventListener('click', function () {
    if (!('geolocation' in navigator)) {
      mostrarErro('Este aparelho não informa a localização. Use "Buscar pelo endereço do Perfil".');
      return;
    }
    if (erro) { erro.hidden = true; }
    botao.disabled = true;
    if (rotulo) { rotulo.textContent = 'Pegando a localização...'; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      form.querySelector('[data-local-lat]').value = pos.coords.latitude.toFixed(7);
      form.querySelector('[data-local-lon]').value = pos.coords.longitude.toFixed(7);
      if (rotulo) { rotulo.textContent = 'Salvando...'; }
      form.submit();
    }, function (falha) {
      mostrarErro(falha && falha.code === 1
        ? 'Você não permitiu a localização. Libere nas configurações do navegador e tente de novo.'
        : 'Não conseguimos pegar a localização agora. Tente de novo perto de uma janela ou use o endereço do Perfil.');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  });
})();
