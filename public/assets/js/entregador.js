/*
 * Painel do entregador (carregado com defer).
 * - Compartilhar localizacao: watchPosition do celular -> POST /{slug}/entregas/localizacao (no maximo a cada 8 s,
 *   ou antes se andou mais de 30 m). Mantem a tela acesa (Wake Lock) e volta a compartilhar sozinho ao recarregar.
 * - Lista: atualiza quando o caixa despacha um pedido para ele (tempo real, canal "entregas") e a cada 30 s; toca aviso.
 */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const corpo = document.body;
  const slug = corpo.dataset.slug;
  const csrf = corpo.dataset.csrf;
  const eu = parseInt(corpo.dataset.usuario || "0", 10);
  const CHAVE = `cardapim:entregador:compartilhar:${slug}`;
  if (!slug) return;

  /* ---------- Aviso sonoro (depois do primeiro toque na tela) ---------- */
  let audio = null;
  document.addEventListener("pointerdown", () => {
    if (!audio && window.AudioContext) {
      try { audio = new window.AudioContext(); } catch (e) { audio = null; }
    }
  }, { once: true });
  const tocar = () => {
    if (!audio) return;
    try {
      [0, 0.2, 0.4].forEach((atraso) => {
        const osc = audio.createOscillator();
        const vol = audio.createGain();
        osc.frequency.value = 988;
        vol.gain.setValueAtTime(0.0001, audio.currentTime + atraso);
        vol.gain.exponentialRampToValueAtTime(0.3, audio.currentTime + atraso + 0.02);
        vol.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + atraso + 0.15);
        osc.connect(vol).connect(audio.destination);
        osc.start(audio.currentTime + atraso);
        osc.stop(audio.currentTime + atraso + 0.16);
      });
      if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
    } catch (e) { /* sem som */ }
  };

  /* ---------- Atualizar a lista sem recarregar ---------- */
  let atualizando = false;
  const atualizar = async () => {
    if (atualizando || document.querySelector("dialog[open]")) return;
    atualizando = true;
    try {
      const r = await fetch(window.location.pathname, { headers: { Accept: "text/html" }, credentials: "same-origin", cache: "no-store" });
      if (r.ok && new URL(r.url).pathname === window.location.pathname) {
        const doc = new DOMParser().parseFromString(await r.text(), "text/html");
        const nova = $("[data-regiao=entregador]", doc);
        const atual = $("[data-regiao=entregador]");
        if (nova && atual) {
          atual.replaceWith(nova);
          desenharLocal();
        }
      }
    } catch (e) { /* tenta no proximo ciclo */ }
    atualizando = false;
  };
  window.setInterval(() => { if (document.visibilityState === "visible") atualizar(); }, 30000);
  document.addEventListener("cardapim:tempo-real", (ev) => {
    const msg = ev.detail || {};
    if (msg.canal !== "entregas") return;
    ev.preventDefault();
    const dados = msg.dados || {};
    if (msg.tipo === "entrega.despachada" && parseInt(dados.entregador, 10) === eu) {
      tocar();
      atualizar();
    }
  });

  /* ---------- Localizacao em tempo real ---------- */
  let observador = null;
  let travaTela = null;
  let ultimoEnvio = 0;
  let ultimoPonto = null;
  let ultimoOk = 0;
  let erroTexto = "";
  let relogio = 0;

  const ligado = () => observador !== null;
  const distanciaM = (a, b) => {
    const R = 6371000;
    const rad = (g) => (g * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLon = rad(b.lon - a.lon);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  };

  const desenharLocal = () => {
    const cartao = $("[data-local]");
    if (!cartao) return;
    const titulo = $("[data-local-titulo]", cartao);
    const status = $("[data-local-status]", cartao);
    const botao = $("[data-local-botao-texto]", cartao);
    cartao.dataset.localLigado = ligado() ? "1" : "0";
    cartao.classList.toggle("is-erro", erroTexto !== "");
    if (erroTexto) {
      titulo.textContent = "Não foi possível pegar sua localização";
      status.textContent = erroTexto;
      botao.textContent = "Tentar de novo";
      return;
    }
    if (!ligado()) {
      titulo.textContent = "Localização desligada";
      status.textContent = "Ligue para o cliente ver você chegando no mapa, como nos apps de corrida. Só aparece para o cliente do pedido que está com você.";
      botao.textContent = "Compartilhar minha localização";
      return;
    }
    titulo.textContent = "Compartilhando sua localização";
    const seg = ultimoOk ? Math.round((Date.now() - ultimoOk) / 1000) : null;
    status.textContent = seg === null ? "Procurando o sinal do GPS..." : `Enviada há ${seg < 5 ? "poucos" : seg} segundos. O cliente vê você no mapa.`;
    botao.textContent = "Parar de compartilhar";
  };

  const enviar = async (pos) => {
    const ponto = { lat: pos.coords.latitude, lon: pos.coords.longitude };
    const agora = Date.now();
    const andou = ultimoPonto ? distanciaM(ultimoPonto, ponto) : Infinity;
    if (agora - ultimoEnvio < 8000 && andou < 30) return;
    ultimoEnvio = agora;
    ultimoPonto = ponto;
    try {
      const r = await fetch(`/${encodeURIComponent(slug)}/entregas/localizacao`, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
        body: JSON.stringify({
          latitude: ponto.lat,
          longitude: ponto.lon,
          precisao: pos.coords.accuracy,
          direcao: pos.coords.heading,
          velocidade: pos.coords.speed,
        }),
      });
      if (r.ok) {
        ultimoOk = Date.now();
        erroTexto = "";
      } else if (r.status === 419) {
        erroTexto = "Sua sessão expirou. Recarregue a página.";
      }
    } catch (e) { /* sem internet agora: tenta no proximo ponto */ }
    desenharLocal();
  };

  const prenderTela = async () => {
    try {
      if ("wakeLock" in navigator && document.visibilityState === "visible") travaTela = await navigator.wakeLock.request("screen");
    } catch (e) { travaTela = null; }
  };

  const ligar = () => {
    if (!("geolocation" in navigator)) {
      erroTexto = "Este aparelho não informa a localização.";
      desenharLocal();
      return;
    }
    erroTexto = "";
    observador = navigator.geolocation.watchPosition(enviar, (erro) => {
      if (erro.code === erro.PERMISSION_DENIED) {
        desligar(false);
        erroTexto = "Permita a localização para este site nas configurações do navegador e toque em Tentar de novo.";
      } else {
        erroTexto = erro.code === erro.TIMEOUT ? "" : "O GPS não respondeu. Confira se a localização do celular está ligada.";
      }
      desenharLocal();
    }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
    try { localStorage.setItem(CHAVE, "1"); } catch (e) { /* ok */ }
    prenderTela();
    window.clearInterval(relogio);
    relogio = window.setInterval(desenharLocal, 5000);
    desenharLocal();
  };

  const desligar = (avisarServidor = true) => {
    if (observador !== null) navigator.geolocation.clearWatch(observador);
    observador = null;
    ultimoOk = 0;
    ultimoPonto = null;
    window.clearInterval(relogio);
    try { localStorage.removeItem(CHAVE); } catch (e) { /* ok */ }
    if (travaTela) { travaTela.release().catch(() => {}); travaTela = null; }
    if (avisarServidor) {
      fetch(`/${encodeURIComponent(slug)}/entregas/localizacao/parar`, { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf } }).catch(() => {});
    }
    desenharLocal();
  };

  document.addEventListener("click", (ev) => {
    if (!ev.target.closest("[data-local-botao]")) return;
    if (ligado()) desligar(); else ligar();
  });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && ligado() && !travaTela) prenderTela();
  });
  // Ao sair da conta, para de compartilhar.
  document.addEventListener("submit", (ev) => {
    if (ev.target.matches && ev.target.matches("[data-sair]") && ligado()) desligar();
  });

  // Voltou para a tela e estava compartilhando: continua (se o navegador ainda tiver a permissao).
  let lembrou = false;
  try { lembrou = localStorage.getItem(CHAVE) === "1"; } catch (e) { lembrou = false; }
  if (lembrou && navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({ name: "geolocation" }).then((p) => { if (p.state === "granted") ligar(); else desenharLocal(); }).catch(() => desenharLocal());
  } else {
    desenharLocal();
  }
})();
