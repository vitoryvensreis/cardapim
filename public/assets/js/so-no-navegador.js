/*
 * A pagina inicial (vendas) e so para o navegador. Aberta dentro do app instalado (PWA), vai direto para o login,
 * que leva cada pessoa logada ao proprio painel. Roda antes de desenhar a pagina (sem piscar a landing).
 */
(function () {
  "use strict";
  var instalado = false;
  try {
    instalado = ["standalone", "fullscreen", "minimal-ui", "window-controls-overlay"].some(function (modo) {
      return window.matchMedia("(display-mode: " + modo + ")").matches;
    }) || window.navigator.standalone === true;
  } catch (e) {
    instalado = false;
  }
  if (instalado) window.location.replace("/login?origem=app");
})();
