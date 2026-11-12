/*
 * Aviso de PEDIDO NOVO na gerencia e no modo caixa (carregado com defer, depois do tempo-real.js).
 * - Ouve o evento "pedido.novo" do canal "pedidos" (tempo real) e avisa de 3 jeitos:
 *   luz laranja piscando em volta da tela, uma tremida leve no sistema e um som de campainha.
 * - A faixa com o pedido fica na tela ate alguem tocar em "Ver pedido" ou "Ok, vi"; enquanto isso o som
 *   repete a cada 20 s (no maximo 6 vezes). Varios pedidos juntos viram "3 pedidos novos".
 * - Som: o navegador so libera depois do primeiro toque na pagina, a NAO ser que o app esteja instalado (PWA) ou o site
 *   tenha "Som: permitir" nas configuracoes do navegador. Por isso tenta ligar ja ao abrir; se nao der, liga no 1o toque.
 *   A faixa nao muda por causa disso (sem aviso de "toque na tela").
 * - Botao "Som ligado/desligado" no topo (lembrado neste aparelho). Com "reduzir movimento", nada pisca nem treme.
 */
(() => {
  "use strict";

  const corpo = document.body;
  const urlDelivery = corpo.dataset.alertaPedido;
  if (!urlDelivery) return;

  const $ = (s) => document.querySelector(s);
  const app = $(".painel");
  const luz = $("[data-alerta-luz]");
  const aviso = $("[data-alerta-aviso]");
  const titulo = $("[data-alerta-titulo]");
  const texto = $("[data-alerta-texto]");
  const ver = $("[data-alerta-ver]");
  const ok = $("[data-alerta-ok]");
  const botaoSom = $("[data-alerta-som]");
  const textoSom = $("[data-alerta-som-texto]");
  const reduzir = window.matchMedia("(prefers-reduced-motion: reduce)");
  const CHAVE_SOM = "deliveryzim:som-pedido";
  const REPETIR_MS = 20000;
  const MAX_REPETICOES = 6;

  let audio = null;
  let somLigado = true;
  let pendentes = [];
  let repetir = null;
  let repeticoes = 0;
  const tituloOriginal = document.title;

  try { somLigado = window.localStorage.getItem(CHAVE_SOM) !== "desligado"; } catch (e) { somLigado = true; }

  function mostrarBotaoSom() {
    if (!botaoSom) return;
    botaoSom.setAttribute("aria-pressed", somLigado ? "true" : "false");
    if (textoSom) textoSom.textContent = somLigado ? "Som ligado" : "Som desligado";
    botaoSom.title = somLigado ? "Som de pedido novo ligado. Toque para desligar." : "Som de pedido novo desligado. Toque para ligar.";
  }

  /* ---------- Som (Web Audio, sem arquivo) ---------- */
  function liberarAudio() {
    if (!window.AudioContext && !window.webkitAudioContext) return;
    try {
      if (!audio) audio = new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === "suspended") audio.resume();
    } catch (e) { audio = null; }
  }
  const audioPronto = () => !!audio && audio.state === "running";
  ["pointerdown", "keydown", "touchstart"].forEach((tipo) => document.addEventListener(tipo, liberarAudio, { passive: true }));
  // App instalado ou som permitido no site: ja fica pronto sem toque.
  liberarAudio();

  // Campainha "ding-dong" em dois toques, com harmonico para ser ouvida na cozinha.
  function tocar() {
    if (!somLigado || !audio) return;
    if (audio.state === "suspended") {
      // Pode ter sido liberado depois (permissao do site): tenta e toca quando der.
      audio.resume().then(() => { if (audioPronto()) tocar(); }).catch(() => {});
      return;
    }
    if (!audioPronto()) return;
    try {
      const t0 = audio.currentTime + 0.02;
      [[988, 0], [784, 0.32], [988, 0.9], [784, 1.22]].forEach(([freq, atraso]) => {
        [1, 2].forEach((mult) => {
          const osc = audio.createOscillator();
          const vol = audio.createGain();
          osc.type = mult === 1 ? "sine" : "triangle";
          osc.frequency.value = freq * mult;
          const pico = mult === 1 ? 0.32 : 0.08;
          vol.gain.setValueAtTime(0.0001, t0 + atraso);
          vol.gain.exponentialRampToValueAtTime(pico, t0 + atraso + 0.015);
          vol.gain.exponentialRampToValueAtTime(0.0001, t0 + atraso + 0.6);
          osc.connect(vol).connect(audio.destination);
          osc.start(t0 + atraso);
          osc.stop(t0 + atraso + 0.62);
        });
      });
    } catch (e) { /* sem som: fica o aviso visual */ }
  }

  /* ---------- Visual ---------- */
  function piscar() {
    if (luz) {
      luz.classList.remove("is-acesa");
      void luz.offsetWidth; // reinicia a animacao
      luz.classList.add("is-acesa");
      window.setTimeout(() => luz.classList.remove("is-acesa"), reduzir.matches ? 1800 : 2700);
    }
    if (app && !reduzir.matches) {
      app.classList.remove("is-tremendo");
      void app.offsetWidth;
      app.classList.add("is-tremendo");
      window.setTimeout(() => app.classList.remove("is-tremendo"), 600);
    }
    if (navigator.vibrate && !reduzir.matches) {
      try { navigator.vibrate([180, 90, 180]); } catch (e) { /* sem vibracao */ }
    }
  }

  function atualizarFaixa() {
    if (!aviso) return;
    const qtd = pendentes.length;
    const ultimo = pendentes[qtd - 1] || {};
    if (titulo) titulo.textContent = qtd > 1 ? `${qtd} pedidos novos!` : "Pedido novo!";
    if (texto) {
      texto.textContent = qtd > 1
        ? `Pedidos ${pendentes.map((p) => (p.numero ? `#${p.numero}` : "")).filter(Boolean).join(", ")} esperando para serem aceitos.`
        : `Pedido ${ultimo.numero ? `#${ultimo.numero} ` : ""}chegou e está esperando para ser aceito.`;
    }
    if (ver) {
      ver.href = qtd === 1 && ultimo.id ? `${urlDelivery}/${encodeURIComponent(ultimo.id)}` : urlDelivery;
      ver.lastChild.textContent = qtd > 1 ? "Ver pedidos" : "Ver pedido";
    }
    document.title = `(${qtd}) ${qtd > 1 ? "Pedidos novos" : "Pedido novo"} | ${tituloOriginal}`;
  }

  function abrirFaixa() {
    if (!aviso) return;
    const estavaFechado = aviso.hidden;
    aviso.hidden = false;
    if (estavaFechado && !reduzir.matches) {
      aviso.classList.remove("is-entrando");
      void aviso.offsetWidth;
      aviso.classList.add("is-entrando");
    }
  }

  function fecharFaixa() {
    if (aviso) aviso.hidden = true;
    pendentes = [];
    repeticoes = 0;
    window.clearInterval(repetir);
    repetir = null;
    document.title = tituloOriginal;
  }

  function avisar(dados) {
    const id = Number(dados.id) || 0;
    if (id && pendentes.some((p) => p.id === id)) return;
    pendentes.push({ id, numero: Number(dados.numero) || 0 });
    atualizarFaixa();
    abrirFaixa();
    piscar();
    tocar();
    repeticoes = 0;
    window.clearInterval(repetir);
    repetir = window.setInterval(() => {
      repeticoes += 1;
      if (repeticoes > MAX_REPETICOES || !aviso || aviso.hidden) {
        window.clearInterval(repetir);
        repetir = null;
        return;
      }
      tocar();
      if (document.visibilityState === "visible") piscar();
    }, REPETIR_MS);
  }

  /* ---------- Eventos ---------- */
  document.addEventListener("cardapim:tempo-real", (ev) => {
    const msg = ev.detail || {};
    if (msg.canal !== "pedidos") return;
    if (msg.tipo === "pedido.novo") {
      avisar(msg.dados || {});
      return;
    }
    // Pedido aceito ou cancelado em outro aparelho: sai da faixa.
    if ((msg.tipo === "pedido.atualizado" || msg.tipo === "pedido.cancelado") && msg.dados && pendentes.length) {
      const id = Number(msg.dados.id) || 0;
      if (msg.tipo === "pedido.cancelado" || msg.dados.status !== "recebido") {
        pendentes = pendentes.filter((p) => p.id !== id);
        if (pendentes.length === 0) fecharFaixa(); else atualizarFaixa();
      }
    }
  });

  if (ok) ok.addEventListener("click", fecharFaixa);
  if (ver) ver.addEventListener("click", () => { window.clearInterval(repetir); repetir = null; document.title = tituloOriginal; });
  document.addEventListener("keydown", (ev) => { if (ev.key === "Escape" && aviso && !aviso.hidden) fecharFaixa(); });

  if (botaoSom) {
    botaoSom.addEventListener("click", () => {
      somLigado = !somLigado;
      try { window.localStorage.setItem(CHAVE_SOM, somLigado ? "ligado" : "desligado"); } catch (e) { /* sem memoria: vale so nesta pagina */ }
      mostrarBotaoSom();
      if (somLigado) {
        liberarAudio();
        tocar(); // amostra: a pessoa ouve como vai soar
      }
    });
  }
  mostrarBotaoSom();

  // A gerencia nem sempre ouve "pedidos" (depende da aba): pede o canal ao tempo real.
  if (window.CardapimTempoReal) window.CardapimTempoReal.assinar("pedidos");

  // Para testar sem pedido de verdade: window.CardapimAlertaPedido.simular()
  window.CardapimAlertaPedido = { simular: () => avisar({ id: 0, numero: 0 }) };
})();
