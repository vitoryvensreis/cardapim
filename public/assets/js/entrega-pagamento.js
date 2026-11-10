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
