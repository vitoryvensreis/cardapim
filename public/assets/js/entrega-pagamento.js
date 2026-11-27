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

  // Um Pix por vez: Pix pelo app (Asaas) e Pix pela chave nunca juntos (o servidor e o banco tambem garantem).
  // Qualquer Pix com QR Code ligado desliga o Pix na entrega.
  var pixApp = document.querySelector('[data-pix-online]');
  var pixChave = document.querySelector('[data-pix-chave]');
  var pixChaveCampos = document.querySelector('[data-pix-chave-campos]');
  var pixUmAviso = document.querySelector('[data-pix-um-aviso]');
  var pixEntrega = document.querySelector('[data-pix-entrega]');
  var pixAviso = document.querySelector('[data-pix-entrega-aviso]');
  var pixEntregaAntes = pixEntrega ? pixEntrega.checked : false;
  var travarPix = function () {
    var ligado = (pixApp && pixApp.checked && !pixApp.disabled) || (pixChave && pixChave.checked);
    if (pixChaveCampos && pixChave && pixChave.checked) { pixChaveCampos.hidden = false; }
    if (!pixEntrega) { return; }
    if (ligado) {
      if (!pixEntrega.disabled) { pixEntregaAntes = pixEntrega.checked; }
      pixEntrega.checked = false;
    } else if (pixEntrega.disabled) {
      pixEntrega.checked = pixEntregaAntes;
    }
    pixEntrega.disabled = !!ligado;
    if (pixAviso) { pixAviso.hidden = !ligado; }
  };
  var umPorVez = function (marcado, outro) {
    if (marcado && marcado.checked && outro && outro.checked) {
      outro.checked = false;
      if (pixUmAviso) { pixUmAviso.hidden = false; }
    }
    travarPix();
  };
  if (pixApp) { pixApp.addEventListener('change', function () { umPorVez(pixApp, pixChave); }); }
  if (pixChave) { pixChave.addEventListener('change', function () { umPorVez(pixChave, pixApp); }); }
  travarPix();

  // "Ver como fica": QR Code de R$ 1,00 com os dados digitados (nada e gravado).
  var botaoTeste = document.querySelector('[data-pix-teste]');
  if (botaoTeste) {
    var resultado = document.querySelector('[data-pix-teste-resultado]');
    var erroTeste = document.querySelector('[data-pix-teste-erro]');
    botaoTeste.addEventListener('click', function () {
      var dados = new FormData(botaoTeste.form);
      botaoTeste.disabled = true;
      erroTeste.hidden = true;
      fetch(botaoTeste.getAttribute('data-pix-teste'), { method: 'POST', body: dados, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) {
            var erros = j.erros || {};
            var primeira = Object.keys(erros)[0];
            erroTeste.textContent = primeira ? erros[primeira] : 'Confira os dados da chave Pix.';
            erroTeste.hidden = false;
            resultado.hidden = true;
            return;
          }
          document.querySelector('[data-pix-teste-codigo]').value = j.codigo;
          var area = document.querySelector('[data-pix-teste-qr]');
          if (typeof window.qrcode === 'function') {
            var qr = window.qrcode(0, 'M');
            qr.addData(j.codigo);
            qr.make();
            area.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
          } else {
            area.textContent = 'Use o código abaixo no app do seu banco.';
          }
          resultado.hidden = false;
        })
        .catch(function () {
          erroTeste.textContent = 'Não foi possível gerar agora. Atualize a página e tente de novo.';
          erroTeste.hidden = false;
        })
        .then(function () { botaoTeste.disabled = false; });
    });
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
