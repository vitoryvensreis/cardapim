/*
 * Painel do entregador (carregado com defer).
 * - Localizacao OBRIGATORIA: liga sozinha ao abrir o painel (o celular pede a permissao uma vez e guarda).
 *   Sem permissao, o painel fica escondido atras da tela "Ative sua localizacao" ate liberar.
 *   watchPosition -> POST /{slug}/entregas/localizacao (no maximo a cada 8 s, ou antes se andou mais de 30 m).
 *   Sem sinal preciso, tenta a localizacao aproximada. Mantem a tela acesa (Wake Lock). Ao sair da conta, para.
 * - Lista: atualiza quando o caixa despacha um pedido para ele (tempo real, canal "entregas") e a cada 30 s; toca aviso.
 */
(() => {
  "use strict";

  const $ = (s, el = document) => el.querySelector(s);
  const corpo = document.body;
  const slug = corpo.dataset.slug;
  const csrf = corpo.dataset.csrf;
  const eu = parseInt(corpo.dataset.usuario || "0", 10);
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

  /* ---------- Localizacao em tempo real (obrigatoria) ---------- */
  let observador = null;
  let travaTela = null;
  let ultimoEnvio = 0;
  let ultimoPonto = null;
  let ultimoOk = 0;
  let ultimaLeitura = 0;
  let problema = ""; // aviso temporario (sem sinal, sem internet): nao bloqueia
  let bloqueio = ""; // "", "pedindo", "fechou", "negado", "sem-gps": esconde o painel
  let relogio = 0;
  const ua = window.navigator.userAgent;
  const ios = /iPhone|iPad|iPod/.test(ua) || (window.navigator.platform === "MacIntel" && window.navigator.maxTouchPoints > 1);

  const distanciaM = (a, b) => {
    const R = 6371000;
    const rad = (g) => (g * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLon = rad(b.lon - a.lon);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  };

  const telaBloqueio = $("[data-local-bloqueio]");
  const desenharBloqueio = () => {
    if (!telaBloqueio) return;
    const regiao = $("[data-regiao=entregador]");
    const bloqueado = bloqueio !== "";
    telaBloqueio.hidden = !bloqueado;
    if (regiao) {
      regiao.hidden = bloqueado;
      regiao.toggleAttribute("inert", bloqueado);
    }
    if (!bloqueado) return;
    const textos = {
      pedindo: ["Ative sua localização para começar", "Para receber e fazer entregas, o Cardapim precisa da sua localização. Toque em Permitir na janela do celular. O cliente só vê você enquanto o pedido dele está a caminho.", "Ativar localização"],
      fechou: ["Falta permitir a localização", "A janela foi fechada sem permitir. Toque no botão abaixo e escolha Permitir. Sem a localização, não dá para fazer entregas.", "Ativar localização"],
      negado: ["Sua localização está bloqueada", "O celular bloqueou a localização para o Cardapim. Libere assim:", "Tentar de novo"],
      "sem-gps": ["Este navegador não informa a localização", "Abra o Cardapim no Chrome (Android) ou no Safari (iPhone) para fazer entregas.", "Tentar de novo"],
    };
    const [titulo, texto, botao] = textos[bloqueio] || textos.pedindo;
    $("[data-bloqueio-titulo]").textContent = titulo;
    $("[data-bloqueio-texto]").textContent = texto;
    $("[data-local-ativar-texto]").textContent = botao;
    $("[data-bloqueio-android]").hidden = bloqueio !== "negado" || ios;
    $("[data-bloqueio-ios]").hidden = bloqueio !== "negado" || !ios;
  };

  const desenharLocal = () => {
    desenharBloqueio();
    const cartao = $("[data-local]");
    if (!cartao) return;
    const titulo = $("[data-local-titulo]", cartao);
    const status = $("[data-local-status]", cartao);
    const seg = ultimoOk ? Math.round((Date.now() - ultimoOk) / 1000) : null;
    const ok = seg !== null && seg < 120 && problema === "";
    cartao.dataset.localLigado = ok ? "1" : "0";
    cartao.classList.toggle("is-erro", problema !== "");
    $("[data-local-botao]", cartao).hidden = problema === "";
    if (problema) {
      titulo.textContent = "Localização com problema";
      status.textContent = problema;
    } else if (seg === null) {
      titulo.textContent = "Ligando sua localização...";
      status.textContent = "Procurando o sinal do GPS.";
    } else {
      titulo.textContent = "Localização ligada";
      status.textContent = `Enviada há ${seg < 5 ? "poucos" : seg} segundos.`;
    }
  };

  const enviar = async (pos, forcar = false) => {
    ultimaLeitura = Date.now();
    if (bloqueio) {
      bloqueio = "";
      desenharLocal();
    }
    const ponto = { lat: pos.coords.latitude, lon: pos.coords.longitude };
    const agora = Date.now();
    const andou = ultimoPonto ? distanciaM(ultimoPonto, ponto) : Infinity;
    if (!forcar && agora - ultimoEnvio < 8000 && andou < 30) return;
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
        problema = "";
      } else if (r.status === 419) {
        problema = "Sua sessão expirou. Toque em Tentar de novo para recarregar.";
      }
    } catch (e) {
      problema = "Sem internet agora. A localização volta sozinha quando a conexão voltar.";
    }
    desenharLocal();
  };

  const prenderTela = async () => {
    try {
      if ("wakeLock" in navigator && document.visibilityState === "visible" && !travaTela) {
        travaTela = await navigator.wakeLock.request("screen");
        travaTela.addEventListener("release", () => { travaTela = null; });
      }
    } catch (e) { travaTela = null; }
  };

  const falhou = async (erro) => {
    if (erro.code === erro.PERMISSION_DENIED) {
      parar(false);
      // Fechou a janela sem responder (a permissao continua "perguntar") ou bloqueou de vez?
      let estado = "denied";
      try { estado = (await navigator.permissions.query({ name: "geolocation" })).state; } catch (e) { estado = "denied"; }
      bloqueio = estado === "prompt" ? "fechou" : "negado";
    } else if (Date.now() - ultimaLeitura > 30000) {
      problema = "O GPS não está respondendo. Confira se a localização do celular está ligada.";
    }
    desenharLocal();
  };

  // Sem sinal preciso (dentro de casa, computador): usa a localizacao aproximada para nao ficar sem nada.
  const aproximada = (forcar) => {
    navigator.geolocation.getCurrentPosition((pos) => enviar(pos, forcar), () => {}, { enableHighAccuracy: false, maximumAge: 60000, timeout: 15000 });
  };

  const ligar = () => {
    if (!("geolocation" in navigator)) {
      bloqueio = "sem-gps";
      desenharLocal();
      return;
    }
    if (observador !== null) navigator.geolocation.clearWatch(observador);
    problema = "";
    observador = navigator.geolocation.watchPosition((pos) => enviar(pos), falhou, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
    prenderTela();
    window.clearInterval(relogio);
    relogio = window.setInterval(() => {
      if (observador !== null && !bloqueio && document.visibilityState === "visible" && Date.now() - ultimaLeitura > 20000) aproximada(false);
      desenharLocal();
    }, 5000);
    desenharLocal();
  };

  const parar = (avisarServidor = true) => {
    if (observador !== null) navigator.geolocation.clearWatch(observador);
    observador = null;
    window.clearInterval(relogio);
    if (travaTela) { travaTela.release().catch(() => {}); travaTela = null; }
    if (avisarServidor) {
      fetch(`/${encodeURIComponent(slug)}/entregas/localizacao/parar`, { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf }, keepalive: true }).catch(() => {});
    }
  };

  document.addEventListener("click", (ev) => {
    if (ev.target.closest("[data-local-ativar]")) {
      if (bloqueio === "sem-gps" || problema.indexOf("sessão expirou") >= 0) window.location.reload();
      else ligar();
      return;
    }
    if (ev.target.closest("[data-local-botao]")) {
      if (problema.indexOf("sessão expirou") >= 0) window.location.reload();
      else { ligar(); aproximada(true); }
    }
  });
  // Voltou para o app (depois do WhatsApp, do Waze ou com a tela desligada): manda a posicao na hora.
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState !== "visible") return;
    if (observador === null && !bloqueio) ligar();
    else if (observador !== null) { prenderTela(); aproximada(true); }
  });
  // Ao sair da conta, para de compartilhar (e apaga a ultima posicao).
  document.addEventListener("submit", (ev) => {
    if (ev.target.matches && ev.target.matches("[data-sair]")) parar(true);
  });

  // A permissao mudou nas configuracoes do celular com o painel aberto.
  if (navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({ name: "geolocation" }).then((p) => {
      if (p.state !== "granted") bloqueio = p.state === "denied" ? "negado" : "pedindo";
      desenharLocal();
      p.addEventListener("change", () => {
        if (p.state === "granted") { bloqueio = ""; ligar(); }
        else if (p.state === "denied") { parar(false); bloqueio = "negado"; desenharLocal(); }
      });
    }).catch(() => {});
  }

  // Obrigatorio: liga assim que o painel abre (o celular pergunta so na primeira vez).
  ligar();
})();
