/*
 * Cardapim - cliente final no cardapio: janelas em "folha", conta e login pelo WhatsApp.
 * Fluxo (como nos apps de delivery): numero -> codigo de 6 digitos no WhatsApp -> (se for novo) nome.
 * Expoe window.CardapimUI (abrir/fechar folhas) e window.CardapimCliente (atual, entrar, pronto, api).
 * api(url, corpo?) = fetch JSON com o token CSRF da sessao (POST quando ha corpo); dados.http = status HTTP.
 */
(() => {
  "use strict";

  /* ---------- Folhas (dialog) ---------- */
  const raiz = document.documentElement;
  const reduzir = window.matchMedia("(prefers-reduced-motion: reduce)");

  const abrir = (dialogo) => {
    if (!dialogo || dialogo.open) return;
    raiz.classList.add("cd-sem-rolagem");
    dialogo.showModal();
    dialogo.classList.remove("is-saindo");
  };

  const fechar = (dialogo) => {
    if (!dialogo || !dialogo.open) return;
    if (reduzir.matches) {
      dialogo.close();
      return;
    }
    dialogo.classList.add("is-saindo");
    window.setTimeout(() => {
      dialogo.classList.remove("is-saindo");
      dialogo.close();
    }, 180);
  };

  document.querySelectorAll("dialog.cd-folha").forEach((dialogo) => {
    dialogo.addEventListener("close", () => {
      dialogo.querySelector(".cd-folha-caixa")?.style.removeProperty("transform");
      if (!document.querySelector("dialog.cd-folha[open]")) raiz.classList.remove("cd-sem-rolagem");
    });
    dialogo.addEventListener("cancel", (ev) => {
      ev.preventDefault();
      fechar(dialogo);
    });
    // Toque fora da caixa (no fundo escurecido) fecha.
    dialogo.addEventListener("click", (ev) => {
      if (ev.target === dialogo) fechar(dialogo);
    });
    dialogo.querySelectorAll("[data-fechar]").forEach((b) => b.addEventListener("click", () => fechar(dialogo)));

    // Arrastar a folha para baixo pelo topo fecha (gesto comum no celular).
    const topo = dialogo.querySelector(".cd-folha-topo");
    const caixa = dialogo.querySelector(".cd-folha-caixa");
    if (!topo || !caixa) return;
    let inicio = null;
    let delta = 0;
    topo.addEventListener("touchstart", (ev) => {
      if (ev.target.closest("button")) return;
      inicio = ev.touches[0].clientY;
      delta = 0;
      caixa.style.transition = "none";
    }, { passive: true });
    topo.addEventListener("touchmove", (ev) => {
      if (inicio === null) return;
      delta = Math.max(0, ev.touches[0].clientY - inicio);
      caixa.style.transform = `translateY(${delta}px)`;
    }, { passive: true });
    topo.addEventListener("touchend", () => {
      if (inicio === null) return;
      inicio = null;
      caixa.style.removeProperty("transition");
      if (delta > 90) fechar(dialogo);
      else caixa.style.removeProperty("transform");
    });
  });

  window.CardapimUI = { abrir, fechar };

  /* ---------- Sessao do cliente ---------- */
  let csrf = "";
  let cliente = null;
  let loginDisponivel = true;
  const ouvintes = [];

  const avisar = () => ouvintes.forEach((f) => f(cliente));

  const chamar = async (url, corpo) => {
    const opcoes = { credentials: "same-origin", headers: { Accept: "application/json" } };
    if (corpo !== undefined) {
      opcoes.method = "POST";
      opcoes.headers["Content-Type"] = "application/json";
      opcoes.headers["X-CSRF-Token"] = csrf;
      opcoes.body = JSON.stringify(corpo);
    }
    let resposta;
    try {
      resposta = await fetch(url, opcoes);
    } catch (e) {
      return { ok: false, mensagem: "Sem conexão com a internet. Confira e tente de novo." };
    }
    let dados = {};
    try {
      dados = await resposta.json();
    } catch (e) {
      dados = {};
    }
    if (typeof dados.csrf === "string") csrf = dados.csrf;
    if ("cliente" in dados) {
      cliente = dados.cliente;
      avisar();
    }
    dados.http = resposta.status;
    if (!resposta.ok && dados.ok === undefined) dados.ok = false;
    if (!dados.mensagem && !resposta.ok) dados.mensagem = dados.erro || "Algo deu errado. Tente de novo.";
    return dados;
  };

  const pronto = chamar("/api/v1/cliente").then((dados) => {
    loginDisponivel = dados.login_disponivel !== false;
    return cliente;
  });

  /* ---------- Botao da conta no topo ---------- */
  const botaoConta = document.querySelector("[data-conta]");
  const textoConta = document.querySelector("[data-conta-texto]");
  const dConta = document.querySelector("[data-dialogo-conta]");

  const iniciais = (nome) => nome.split(" ").filter(Boolean).slice(0, 2).map((p) => p[0]).join("").toUpperCase();

  ouvintes.push((c) => {
    if (textoConta) textoConta.textContent = c ? c.primeiro_nome : "Entrar";
    if (botaoConta) {
      botaoConta.classList.toggle("is-logado", Boolean(c));
      botaoConta.setAttribute("aria-label", c ? `Sua conta: ${c.nome}` : "Entrar com WhatsApp");
    }
  });

  botaoConta?.addEventListener("click", () => {
    if (!cliente) {
      entrar({});
      return;
    }
    dConta.querySelector("[data-conta-nome]").textContent = cliente.nome;
    dConta.querySelector("[data-conta-whatsapp]").textContent = cliente.whatsapp;
    dConta.querySelector("[data-conta-iniciais]").textContent = iniciais(cliente.nome);
    abrir(dConta);
  });

  dConta?.querySelector("[data-sair]")?.addEventListener("click", async (ev) => {
    const botao = ev.currentTarget;
    botao.disabled = true;
    await chamar("/api/v1/cliente/sair", {});
    botao.disabled = false;
    fechar(dConta);
    window.CardapimToast?.("Você saiu da sua conta.");
  });

  /* ---------- Login pelo WhatsApp ---------- */
  const dLogin = document.querySelector("[data-dialogo-login]");
  if (!dLogin) {
    window.CardapimCliente = { pronto, atual: () => cliente, entrar: () => Promise.resolve(null), ouvir: (f) => ouvintes.push(f), api: chamar };
    return;
  }
  const etapas = {};
  dLogin.querySelectorAll("[data-etapa]").forEach((f) => { etapas[f.dataset.etapa] = f; });
  const campoTel = dLogin.querySelector("[data-telefone]");
  const campoCodigo = dLogin.querySelector("[data-codigo]");
  const campoNome = dLogin.querySelector("[data-nome]");
  const voltar = dLogin.querySelector("[data-login-voltar]");
  const reenviar = dLogin.querySelector("[data-reenviar]");
  const reenviarTempo = dLogin.querySelector("[data-reenviar-tempo]");
  let etapaAtual = "telefone";
  let resolver = null;
  let relogio = null;

  const mascaraTelefone = (valor) => {
    const d = valor.replace(/\D/g, "").replace(/^55(?=\d{10,11}$)/, "").slice(0, 11);
    if (d.length <= 2) return d.length ? `(${d}` : "";
    if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  };
  campoTel.addEventListener("input", () => {
    campoTel.value = mascaraTelefone(campoTel.value);
  });
  campoCodigo.addEventListener("input", () => {
    campoCodigo.value = campoCodigo.value.replace(/\D/g, "").slice(0, 6);
    if (campoCodigo.value.length === 6) etapas.codigo.requestSubmit();
  });

  const mostrarErro = (form, texto) => {
    const erro = form.querySelector("[data-erro]");
    const campo = form.querySelector("input");
    erro.textContent = texto || "";
    erro.hidden = !texto;
    campo?.setAttribute("aria-invalid", texto ? "true" : "false");
  };

  const ir = (etapa) => {
    etapaAtual = etapa;
    Object.entries(etapas).forEach(([nome, form]) => { form.hidden = nome !== etapa; });
    voltar.hidden = etapa !== "codigo";
    const campo = etapas[etapa].querySelector("input");
    window.setTimeout(() => campo?.focus(), 60);
  };

  const ocupado = (form, sim) => {
    const botao = form.querySelector("[data-enviar]");
    botao.disabled = sim;
    botao.classList.toggle("is-loading", sim);
    botao.setAttribute("aria-busy", sim ? "true" : "false");
  };

  const contar = (segundos) => {
    window.clearInterval(relogio);
    let falta = segundos;
    const pintar = () => {
      reenviar.disabled = falta > 0;
      reenviarTempo.textContent = falta > 0 ? `em ${falta}s` : "";
    };
    pintar();
    relogio = window.setInterval(() => {
      falta -= 1;
      pintar();
      if (falta <= 0) window.clearInterval(relogio);
    }, 1000);
  };

  const pedirCodigo = async () => {
    const form = etapas.telefone;
    const digitos = campoTel.value.replace(/\D/g, "");
    if (digitos.length < 10) {
      mostrarErro(form, "Digite o DDD e o número do celular.");
      campoTel.focus();
      return false;
    }
    mostrarErro(form, "");
    ocupado(form, true);
    const r = await chamar("/api/v1/cliente/codigo", { telefone: digitos });
    ocupado(form, false);
    if (!r.ok) {
      if (etapaAtual === "codigo") mostrarErro(etapas.codigo, r.mensagem);
      else mostrarErro(form, r.mensagem);
      if (r.espera) contar(r.espera);
      return false;
    }
    dLogin.querySelector("[data-login-numero]").textContent = r.telefone || campoTel.value;
    campoCodigo.value = "";
    mostrarErro(etapas.codigo, "");
    ir("codigo");
    contar(r.espera || 60);
    return true;
  };

  const concluir = (mensagem) => {
    fechar(dLogin);
    window.CardapimToast?.(mensagem || "Pronto, você entrou!");
    const f = resolver;
    resolver = null;
    f?.(cliente);
  };

  etapas.telefone.addEventListener("submit", (ev) => {
    ev.preventDefault();
    pedirCodigo();
  });

  etapas.codigo.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = etapas.codigo;
    if (campoCodigo.value.length !== 6) {
      mostrarErro(form, "Digite os 6 números do código.");
      return;
    }
    if (form.dataset.enviando === "1") return;
    form.dataset.enviando = "1";
    mostrarErro(form, "");
    ocupado(form, true);
    const r = await chamar("/api/v1/cliente/entrar", { codigo: campoCodigo.value });
    ocupado(form, false);
    form.dataset.enviando = "";
    if (!r.ok) {
      mostrarErro(form, r.mensagem);
      campoCodigo.select();
      return;
    }
    if (r.etapa === "nome") {
      ir("nome");
      return;
    }
    concluir(r.mensagem);
  });

  etapas.nome.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    const form = etapas.nome;
    if (campoNome.value.trim().length < 2) {
      mostrarErro(form, "Digite seu nome (pelo menos 2 letras).");
      return;
    }
    mostrarErro(form, "");
    ocupado(form, true);
    const r = await chamar("/api/v1/cliente/cadastro", { nome: campoNome.value });
    ocupado(form, false);
    if (!r.ok) {
      mostrarErro(form, r.mensagem);
      if (r.mensagem && r.mensagem.indexOf("Confirme seu número") === 0) ir("telefone");
      return;
    }
    concluir(r.mensagem);
  });

  reenviar.addEventListener("click", () => pedirCodigo());
  dLogin.querySelector("[data-trocar-numero]").addEventListener("click", () => ir("telefone"));
  voltar.addEventListener("click", () => ir("telefone"));
  dLogin.addEventListener("close", () => {
    if (resolver) {
      const f = resolver;
      resolver = null;
      f(cliente);
    }
  });

  /** Abre o login. motivo = texto curto do porque (ex.: "para continuar seu pedido"). Resolve com o cliente ou null. */
  const entrar = ({ motivo } = {}) => new Promise((resolve) => {
    if (cliente) {
      resolve(cliente);
      return;
    }
    resolver = resolve;
    dLogin.querySelector("[data-login-titulo]").textContent = "Entre com seu WhatsApp";
    dLogin.querySelector("[data-login-motivo]").textContent = motivo
      ? `Entre ${motivo}. Sem senha: enviamos um código para o seu WhatsApp.`
      : "Sem senha: enviamos um código para o seu WhatsApp.";
    dLogin.querySelector("[data-login-indisponivel]").hidden = loginDisponivel;
    etapas.telefone.querySelector("[data-enviar]").disabled = !loginDisponivel;
    Object.values(etapas).forEach((f) => mostrarErro(f, ""));
    ir(etapaAtual === "codigo" && campoCodigo.value === "" && reenviar.disabled ? "codigo" : "telefone");
    abrir(dLogin);
  });

  window.CardapimCliente = { pronto, atual: () => cliente, entrar, ouvir: (f) => { ouvintes.push(f); }, api: chamar };
})();
