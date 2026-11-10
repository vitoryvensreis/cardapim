/*
 * Tempo real do Cardapim (WebSocket).
 * - Busca um ticket de 60 s na API (com o login da sessao) e conecta no servidor WebSocket.
 * - Assina "geral" + os canais da tela (data-tempo-real-canais) + os pedidos de outros scripts.
 * - Reconecta sozinho (espera crescente com sorteio) e pede o que perdeu ("desde" = ultimo evento visto
 *   nesta pagina). Pagina recem-aberta ja mostra os dados atuais: comeca do evento mais novo.
 * - Cada evento vira um CustomEvent "cardapim:tempo-real" no document (cancelavel).
 *   Se ninguem tratar (preventDefault) e o evento for de um canal da tela, mostra o aviso "Atualizar agora".
 *   A aba que fez a mudanca nao ve o aviso: ela recarrega depois de salvar e ja abre com o dado novo.
 */
(() => {
  "use strict";

  const corpo = document.body;
  const urlTicket = corpo.dataset.tempoReal;
  if (!urlTicket || !("WebSocket" in window) || !window.fetch) return;

  const canaisTela = (corpo.dataset.tempoRealCanais || "").split(",").map((c) => c.trim()).filter(Boolean);
  const extras = new Set();
  const status = document.querySelector("[data-tempo-real-status]");
  const textoStatus = status ? status.querySelector("[data-tempo-real-texto]") : null;
  const aviso = document.querySelector("[data-tempo-real-aviso]");
  const textoAviso = aviso ? aviso.querySelector("[data-tempo-real-aviso-texto]") : null;

  const ROTULOS = {
    conectando: "Conectando",
    "ao-vivo": "Ao vivo",
    reconectando: "Reconectando",
    offline: "Sem atualização ao vivo",
  };
  const DICAS = {
    conectando: "Conectando às atualizações ao vivo.",
    "ao-vivo": "Quando algo muda, o Cardapim avisa nesta tela sem precisar recarregar.",
    reconectando: "A conexão caiu. Tentando de novo sozinho.",
    offline: "Sem atualização ao vivo agora. A tela funciona normalmente; recarregue para ver novidades.",
  };

  let socket = null;
  let tentativas = 0;
  let espera = null;
  let batimento = null;
  let aguardandoPong = null;
  let parado = false;
  let caiuEm = 0;
  let ultimoId = 0;
  const vistos = new Set();

  function mostrarEstado(estado) {
    if (!status) return;
    status.dataset.estado = estado;
    status.title = DICAS[estado] || "";
    if (textoStatus) textoStatus.textContent = ROTULOS[estado] || "";
  }

  function mostrarAviso(texto) {
    if (!aviso || !textoAviso) return;
    textoAviso.textContent = texto;
    aviso.hidden = false;
  }

  function textoDoEvento(msg) {
    const dados = msg.dados || {};
    switch (msg.tipo) {
      case "estoque.alterado":
        return "O estoque mudou em outra tela. Atualize para ver os números certos.";
      case "integracao.asaas.aviso":
        return "Chegou um novo aviso do Asaas.";
      case "integracao.whatsapp.estado":
        if (dados.estado === "conectado") return "O WhatsApp do restaurante foi conectado.";
        if (dados.estado === "erro") return "O WhatsApp foi desconectado do celular.";
        return "O WhatsApp foi desconectado.";
      default:
        return "Há novidades nesta tela.";
    }
  }

  function canais() {
    return Array.from(new Set(["geral", ...canaisTela, ...extras]));
  }

  function enviar(obj) {
    if (socket && socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify(obj));
  }

  function assinar() {
    enviar({ acao: "assinar", canais: canais(), desde: ultimoId > 0 ? ultimoId : null });
  }

  function agendar() {
    clearTimeout(espera);
    if (parado) return;
    tentativas += 1;
    mostrarEstado(tentativas > 4 ? "offline" : "reconectando");
    const base = Math.min(30000, 1000 * 2 ** Math.min(tentativas, 5));
    espera = setTimeout(conectar, Math.round(base * (0.5 + Math.random() * 0.5)));
  }

  function pararBatimento() {
    clearInterval(batimento);
    clearTimeout(aguardandoPong);
    batimento = null;
    aguardandoPong = null;
  }

  function iniciarBatimento(ws) {
    pararBatimento();
    // O servidor manda ping a cada 25 s; daqui conferimos se a conexao nao "morreu calada" (Wi-Fi trocou etc.).
    batimento = setInterval(() => {
      enviar({ acao: "ping" });
      clearTimeout(aguardandoPong);
      aguardandoPong = setTimeout(() => {
        if (socket === ws) ws.close(4000, "sem resposta");
      }, 10000);
    }, 30000);
  }

  function parar(estado) {
    parado = true;
    clearTimeout(espera);
    pararBatimento();
    mostrarEstado(estado);
  }

  async function conectar() {
    clearTimeout(espera);
    if (parado || socket) return;
    if (tentativas === 0) mostrarEstado("conectando");
    let dados;
    try {
      const resposta = await fetch(urlTicket, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
      if (resposta.status === 401 || resposta.status === 403) {
        parar("offline"); // login expirou ou sem acesso: nao insistir (a proxima pagina pede login)
        return;
      }
      if (!resposta.ok) throw new Error("ticket indisponivel");
      dados = await resposta.json();
      if (!dados || typeof dados.url !== "string" || typeof dados.ticket !== "string" || !/^wss?:\/\//.test(dados.url)) {
        throw new Error("resposta invalida");
      }
    } catch (e) {
      agendar();
      return;
    }

    let ws;
    try {
      ws = new WebSocket(dados.url + "?ticket=" + encodeURIComponent(dados.ticket));
    } catch (e) {
      agendar();
      return;
    }
    socket = ws;
    ws.addEventListener("message", (ev) => receber(ws, ev.data));
    ws.addEventListener("close", (ev) => {
      if (socket !== ws) return;
      socket = null;
      pararBatimento();
      if (!caiuEm) caiuEm = Date.now();
      if (ev.code === 4003) {
        parar("offline"); // conta bloqueada ou removida
        return;
      }
      agendar();
    });
  }

  function receber(ws, texto) {
    let msg;
    try {
      msg = JSON.parse(texto);
    } catch (e) {
      return;
    }
    if (!msg || typeof msg.tipo !== "string") return;

    if (msg.tipo === "sistema.conectado") {
      assinar();
      return;
    }
    if (msg.tipo === "sistema.assinado") {
      if (!ultimoId && msg.dados && msg.dados.ultimo_id) {
        ultimoId = Number(msg.dados.ultimo_id) || 0;
      }
      if (caiuEm && Date.now() - caiuEm > 10 * 60 * 1000) {
        mostrarAviso("A tela ficou um tempo sem conexão. Atualize para ver as informações mais recentes.");
      }
      caiuEm = 0;
      tentativas = 0;
      mostrarEstado("ao-vivo");
      iniciarBatimento(ws);
      return;
    }
    if (msg.tipo === "sistema.pong") {
      clearTimeout(aguardandoPong);
      return;
    }
    if (msg.tipo === "sistema.recarregar") {
      mostrarAviso("Muita coisa mudou enquanto a tela estava sem conexão. Atualize para ver tudo certinho.");
      return;
    }
    if (msg.tipo.indexOf("sistema.") === 0 || typeof msg.id !== "number") return;

    if (vistos.has(msg.id)) return; // pode chegar repetido na reconexao
    vistos.add(msg.id);
    if (vistos.size > 500) vistos.delete(vistos.values().next().value);
    if (msg.id > ultimoId) ultimoId = msg.id;

    const evento = new CustomEvent("cardapim:tempo-real", { detail: msg, cancelable: true });
    const ninguemTratou = document.dispatchEvent(evento);
    if (ninguemTratou && canaisTela.indexOf(msg.canal) !== -1) mostrarAviso(textoDoEvento(msg));
  }

  if (aviso) {
    const atualizar = aviso.querySelector("[data-tempo-real-atualizar]");
    const fechar = aviso.querySelector("[data-tempo-real-fechar]");
    if (atualizar) atualizar.addEventListener("click", () => window.location.reload());
    if (fechar) fechar.addEventListener("click", () => { aviso.hidden = true; });
  }

  window.addEventListener("online", () => {
    if (!socket && !parado) {
      tentativas = 0;
      conectar();
    }
  });
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && !socket && !parado) conectar();
  });
  window.addEventListener("pagehide", () => {
    if (socket) {
      const ws = socket;
      socket = null;
      ws.close(1000, "saiu da pagina");
    }
  });

  // Outros scripts podem ouvir canais extras: window.CardapimTempoReal.assinar("pedidos")
  window.CardapimTempoReal = {
    assinar(canal) {
      if (typeof canal === "string" && /^[a-z]{2,30}$/.test(canal) && !extras.has(canal)) {
        extras.add(canal);
        assinar();
      }
    },
    estado() {
      return status ? status.dataset.estado : socket ? "ao-vivo" : "offline";
    },
  };

  conectar();
})();
