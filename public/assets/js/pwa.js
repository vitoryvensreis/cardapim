/*
 * Cardapim - app instalavel (PWA) da equipe.
 * - Registra o service worker (/pwa/sw, escopo "/"): paginas sempre da internet; sem conexao, uma tela "Sem internet".
 * - No login e no cadastro, mostra o convite [data-pwa-instalar]:
 *   Android/Chrome/Edge: botao "Instalar app" (janela do proprio navegador);
 *   iPhone/iPad (Safari nao tem botao): passo a passo Compartilhar > Adicionar a Tela de Inicio;
 *   outro navegador de celular sem convite automatico: caminho pelo menu.
 *   Ja aberto como app: o convite nao aparece.
 */
(() => {
  "use strict";

  const instalado = window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  if (instalado) document.documentElement.classList.add("is-app");

  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("/pwa/sw", { scope: "/" }).catch(() => { /* sem app offline: o site segue normal */ });
    });
  }

  const convite = document.querySelector("[data-pwa-instalar]");
  if (!convite || instalado) return;

  const botao = convite.querySelector("[data-pwa-botao]");
  const texto = convite.querySelector("[data-pwa-texto]");
  const passosIos = convite.querySelector("[data-pwa-ios]");
  const passosMenu = convite.querySelector("[data-pwa-menu]");
  const ua = window.navigator.userAgent;
  const ios = /iPhone|iPad|iPod/.test(ua) || (window.navigator.platform === "MacIntel" && window.navigator.maxTouchPoints > 1);
  const celular = ios || /Android|Mobile/i.test(ua);
  let pedido = null;

  window.addEventListener("beforeinstallprompt", (ev) => {
    ev.preventDefault();
    pedido = ev;
    passosMenu.hidden = true;
    passosIos.hidden = true;
    botao.hidden = false;
    convite.hidden = false;
  });

  botao.addEventListener("click", async () => {
    if (!pedido) return;
    botao.disabled = true;
    pedido.prompt();
    const escolha = await pedido.userChoice.catch(() => null);
    pedido = null;
    botao.disabled = false;
    if (escolha && escolha.outcome === "accepted") {
      botao.hidden = true;
      texto.textContent = "Instalando... O ícone do Cardapim vai aparecer na sua tela inicial.";
    }
  });

  window.addEventListener("appinstalled", () => {
    botao.hidden = true;
    passosMenu.hidden = true;
    passosIos.hidden = true;
    texto.textContent = "Pronto! Abra o Cardapim pelo ícone na tela inicial do celular.";
    convite.hidden = false;
  });

  if (ios) {
    // Safari no iPhone/iPad nao avisa quando da para instalar: mostra o caminho.
    texto.textContent = "Use pelo ícone na tela inicial, em tela cheia, como um aplicativo:";
    passosIos.hidden = false;
    convite.hidden = false;
    return;
  }

  // Celular sem o convite automatico (alguns navegadores): depois de um instante, explica pelo menu.
  if (celular) {
    window.setTimeout(() => {
      if (pedido || !convite.hidden) return;
      texto.textContent = "Use pelo ícone na tela inicial, em tela cheia, como um aplicativo:";
      passosMenu.hidden = false;
      convite.hidden = false;
    }, 2500);
  }
})();
