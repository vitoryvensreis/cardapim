/*
 * Cardapim - cardapio publico /{slug}: abas de categoria que acompanham a rolagem, busca,
 * janela do produto (quantidade + observacao) e sacola.
 *
 * Sacola: guardada neste aparelho (localStorage, uma por restaurante, vale 2 dias) para nao se perder ao
 * recarregar ou sair da pagina. Precos e disponibilidade sao sempre conferidos no servidor
 * (POST /api/v1/restaurantes/{slug}/sacola); o servidor recalcula tudo de novo ao finalizar o pedido
 * (/{slug}/finalizar, js/checkout.js).
 */
(() => {
  "use strict";

  const slug = document.body.dataset.cardapio;
  if (!slug) return;
  const UI = window.CardapimUI;
  const Cliente = window.CardapimCliente;
  const CHAVE = `cardapim:sacola:v1:${slug}`;
  const VALIDADE = 2 * 24 * 60 * 60 * 1000;
  const MAX_QTD = 99;
  const dinheiro = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
  const reais = (centavos) => dinheiro.format(centavos / 100).replace(/ /g, " ");
  const el = (tag, classe, texto) => {
    const n = document.createElement(tag);
    if (classe) n.className = classe;
    if (texto !== undefined) n.textContent = texto;
    return n;
  };
  const icone = (nome, classe = "icon") => {
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    svg.setAttribute("class", classe);
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    const use = document.createElementNS(ns, "use");
    const sprite = document.querySelector("svg use")?.getAttribute("href")?.split("#")[0] || "/assets/icons/lucide.svg";
    use.setAttribute("href", `${sprite}#${nome}`);
    svg.appendChild(use);
    return svg;
  };

  /* ---------- Aviso rapido (toast) ---------- */
  const toast = document.querySelector("[data-toast]");
  let toastTempo = null;
  window.CardapimToast = (texto) => {
    if (!toast) return;
    toast.textContent = texto;
    toast.hidden = false;
    toast.classList.remove("is-saindo");
    window.clearTimeout(toastTempo);
    toastTempo = window.setTimeout(() => {
      toast.classList.add("is-saindo");
      window.setTimeout(() => { toast.hidden = true; }, 200);
    }, 2400);
  };

  /* ---------- Nome do restaurante aparece no topo ao rolar ---------- */
  const topo = document.querySelector("[data-topo]");
  const heroNome = document.querySelector("[data-hero-nome]");
  if (topo && heroNome && "IntersectionObserver" in window) {
    new IntersectionObserver(([e]) => topo.classList.toggle("is-compacto", !e.isIntersecting), { rootMargin: "-56px 0px 0px 0px" }).observe(heroNome);
  }

  /* ---------- Produtos da pagina ---------- */
  const produtos = new Map();
  document.querySelectorAll("[data-produto]").forEach((b) => {
    produtos.set(Number(b.dataset.produto), {
      id: Number(b.dataset.produto),
      nome: b.dataset.nome,
      descricao: b.dataset.descricao || "",
      centavos: Number(b.dataset.centavos) || 0,
      disponivel: !b.disabled,
      botao: b,
    });
  });

  /* ---------- Sacola ---------- */
  let sacola = [];
  const ler = () => {
    try {
      const dados = JSON.parse(window.localStorage.getItem(CHAVE) || "null");
      if (!dados || !Array.isArray(dados.itens) || Date.now() - (dados.salvo || 0) > VALIDADE) return [];
      return dados.itens.filter((i) => Number.isInteger(i.id) && Number.isInteger(i.qtd) && i.qtd > 0).slice(0, 50);
    } catch (e) {
      return [];
    }
  };
  const salvar = () => {
    try {
      if (sacola.length === 0) window.localStorage.removeItem(CHAVE);
      else window.localStorage.setItem(CHAVE, JSON.stringify({ itens: sacola, salvo: Date.now() }));
    } catch (e) {
      /* navegacao privada sem armazenamento: a sacola vale so enquanto a pagina estiver aberta */
    }
  };
  const chaveLinha = (id, obs) => `${id}|${obs.trim().toLowerCase()}`;
  const totais = () => sacola.reduce((t, i) => {
    if (i.indisponivel) return t;
    t.qtd += i.qtd;
    t.centavos += i.qtd * i.centavos;
    return t;
  }, { qtd: 0, centavos: 0 });

  const adicionar = (produto, qtd, obs, linhaEditada) => {
    obs = obs.trim().slice(0, 140);
    if (linhaEditada) sacola = sacola.filter((i) => i.chave !== linhaEditada);
    const chave = chaveLinha(produto.id, obs);
    const existente = sacola.find((i) => i.chave === chave);
    if (existente) existente.qtd = Math.min(MAX_QTD, existente.qtd + qtd);
    else sacola.push({ chave, id: produto.id, nome: produto.nome, centavos: produto.centavos, qtd, obs });
    atualizar();
  };

  const mudarQtd = (chave, delta) => {
    const linha = sacola.find((i) => i.chave === chave);
    if (!linha) return;
    linha.qtd = Math.min(MAX_QTD, linha.qtd + delta);
    if (linha.qtd <= 0) {
      sacola = sacola.filter((i) => i.chave !== chave);
      window.CardapimToast(`${linha.nome} saiu da sacola.`);
    }
    atualizar();
  };

  /* ---------- Desenho da sacola (painel lateral no computador e folha no celular) ---------- */
  const conteudos = document.querySelectorAll("[data-sacola-conteudo]");
  const barra = document.querySelector("[data-barra]");
  const dSacola = document.querySelector("[data-dialogo-sacola]");
  let avisoContinuar = "";

  const desenharSacola = (alvo) => {
    alvo.replaceChildren();
    if (sacola.length === 0) {
      const vazio = el("div", "cd-sacola-vazia");
      vazio.append(icone("shopping-bag", "icon icon-lg"), el("strong", "", "Sua sacola está vazia"), el("p", "", "Toque em um item do cardápio para adicionar."));
      alvo.append(vazio);
      return;
    }
    const lista = el("ul", "cd-sacola-lista");
    sacola.forEach((linha) => {
      const li = el("li", "cd-sacola-item" + (linha.indisponivel ? " is-indisponivel" : ""));
      const texto = el("div", "cd-sacola-texto");
      const editar = el("button", "cd-sacola-nome", linha.nome);
      editar.type = "button";
      editar.setAttribute("aria-label", `Editar ${linha.nome}`);
      editar.addEventListener("click", () => abrirProduto(produtos.get(linha.id), linha));
      texto.append(editar);
      if (linha.obs) texto.append(el("span", "cd-sacola-obs", linha.obs));
      if (linha.indisponivel) texto.append(el("span", "cd-sacola-alerta", "Indisponível agora. Remova para continuar."));
      else texto.append(el("span", "cd-sacola-preco", reais(linha.centavos * linha.qtd)));
      const passo = el("div", "cd-stepper cd-stepper-sm");
      passo.setAttribute("role", "group");
      passo.setAttribute("aria-label", `Quantidade de ${linha.nome}`);
      const menos = el("button");
      menos.type = "button";
      menos.setAttribute("aria-label", linha.qtd === 1 || linha.indisponivel ? `Remover ${linha.nome}` : `Diminuir ${linha.nome}`);
      menos.append(icone(linha.qtd === 1 || linha.indisponivel ? "trash" : "minus"));
      menos.addEventListener("click", () => mudarQtd(linha.chave, linha.indisponivel ? -linha.qtd : -1));
      const qtd = el("output", "", String(linha.qtd));
      const mais = el("button");
      mais.type = "button";
      mais.setAttribute("aria-label", `Aumentar ${linha.nome}`);
      mais.disabled = linha.qtd >= MAX_QTD || Boolean(linha.indisponivel);
      mais.append(icone("plus"));
      mais.addEventListener("click", () => mudarQtd(linha.chave, 1));
      passo.append(menos, qtd, mais);
      li.append(texto, passo);
      lista.append(li);
    });
    const t = totais();
    const resumo = el("dl", "cd-sacola-resumo");
    resumo.append(el("dt", "", "Subtotal"), el("dd", "", reais(t.centavos)), el("dt", "cd-resumo-entrega", "Entrega"), el("dd", "cd-resumo-entrega", "Calculada na próxima etapa"));
    const total = el("p", "cd-sacola-total");
    total.append(el("span", "", "Total"), el("strong", "", reais(t.centavos)));
    const continuar = el("button", "btn btn-primary btn-block cd-continuar");
    continuar.type = "button";
    continuar.append(el("span", "", "Continuar"), el("span", "cd-continuar-total", reais(t.centavos)));
    continuar.disabled = sacola.some((i) => i.indisponivel) || t.qtd === 0;
    continuar.addEventListener("click", continuarPedido);
    const limpar = el("button", "cd-link cd-limpar", "Esvaziar sacola");
    limpar.type = "button";
    limpar.addEventListener("click", () => {
      if (!window.confirm("Tirar todos os itens da sacola?")) return;
      sacola = [];
      avisoContinuar = "";
      atualizar();
    });
    alvo.append(lista, resumo, total);
    if (avisoContinuar) {
      const aviso = el("div", "cd-proxima");
      aviso.setAttribute("role", "status");
      aviso.append(icone("circle-check"), el("p", "", avisoContinuar));
      alvo.append(aviso);
    }
    alvo.append(continuar, limpar);
  };

  const atualizar = () => {
    salvar();
    conteudos.forEach(desenharSacola);
    const t = totais();
    if (barra) {
      barra.hidden = sacola.length === 0;
      barra.querySelector("[data-barra-qtd]").textContent = String(t.qtd);
      barra.querySelector("[data-barra-total]").textContent = reais(t.centavos);
      barra.querySelector("[data-abrir-sacola]").setAttribute("aria-label", `Ver sacola: ${t.qtd} ${t.qtd === 1 ? "item" : "itens"}, ${reais(t.centavos)}`);
    }
    document.body.classList.toggle("tem-sacola", sacola.length > 0);
    // Selo "2x" nos itens que ja estao na sacola.
    const porProduto = new Map();
    sacola.forEach((i) => porProduto.set(i.id, (porProduto.get(i.id) || 0) + i.qtd));
    produtos.forEach((p) => {
      const selo = p.botao.querySelector("[data-na-sacola]");
      if (!selo) return;
      const n = porProduto.get(p.id) || 0;
      selo.hidden = n === 0;
      selo.textContent = n ? `${n}x` : "";
      p.botao.classList.toggle("is-na-sacola", n > 0);
    });
    if (sacola.length === 0 && dSacola?.open) UI.fechar(dSacola);
  };

  // Continuar: entra (se precisar) e vai para "Finalizar pedido" (entrega, pagamento e resumo).
  const continuarPedido = async () => {
    const cliente = await Cliente.entrar({ motivo: "para continuar seu pedido" });
    if (!cliente) return;
    salvar();
    window.location.assign(`/${encodeURIComponent(slug)}/finalizar`);
  };

  /* ---------- Pedido em andamento (feito neste aparelho nas ultimas horas) ---------- */
  const andamento = document.querySelector("[data-ultimo-pedido]");
  try {
    const ultimo = JSON.parse(window.localStorage.getItem(`cardapim:ultimo-pedido:${slug}`) || "null");
    if (andamento && ultimo && Number.isInteger(ultimo.id) && Date.now() - (ultimo.salvo || 0) < 6 * 3600 * 1000) {
      andamento.href = `/${encodeURIComponent(slug)}/pedido/${ultimo.id}`;
      andamento.querySelector("[data-ultimo-numero]").textContent = `Pedido #${ultimo.numero}`;
      andamento.hidden = false;
    }
  } catch (e) {
    /* sem armazenamento */
  }

  document.querySelector("[data-abrir-sacola]")?.addEventListener("click", () => UI.abrir(dSacola));

  /* ---------- Janela do produto ---------- */
  const dProduto = document.querySelector("[data-dialogo-produto]");
  const form = dProduto.querySelector("[data-form-produto]");
  const campoObs = dProduto.querySelector("[data-produto-obs]");
  const saidaQtd = dProduto.querySelector("[data-qtd]");
  const btnMenos = dProduto.querySelector("[data-qtd-menos]");
  const btnMais = dProduto.querySelector("[data-qtd-mais]");
  const totalAdicionar = dProduto.querySelector("[data-adicionar-total]");
  const textoAdicionar = dProduto.querySelector("[data-adicionar-texto]");
  const contaObs = dProduto.querySelector("[data-obs-conta]");
  let atual = null;
  let qtdAtual = 1;
  let linhaEmEdicao = null;

  const pintarQtd = () => {
    saidaQtd.textContent = String(qtdAtual);
    btnMenos.disabled = qtdAtual <= 1;
    btnMais.disabled = qtdAtual >= MAX_QTD;
    totalAdicionar.textContent = reais(atual.centavos * qtdAtual);
  };

  const abrirProduto = (produto, linha = null) => {
    if (!produto || !produto.disponivel) return;
    atual = produto;
    linhaEmEdicao = linha ? linha.chave : null;
    qtdAtual = linha ? linha.qtd : 1;
    dProduto.querySelector("[data-produto-nome]").textContent = produto.nome;
    const desc = dProduto.querySelector("[data-produto-desc]");
    desc.textContent = produto.descricao;
    desc.hidden = produto.descricao === "";
    dProduto.querySelector("[data-produto-preco]").textContent = reais(produto.centavos);
    campoObs.value = linha ? linha.obs : "";
    contaObs.textContent = `${campoObs.value.length}/140`;
    textoAdicionar.textContent = linha ? "Atualizar" : "Adicionar";
    pintarQtd();
    UI.abrir(dProduto);
  };

  btnMenos.addEventListener("click", () => { qtdAtual = Math.max(1, qtdAtual - 1); pintarQtd(); });
  btnMais.addEventListener("click", () => { qtdAtual = Math.min(MAX_QTD, qtdAtual + 1); pintarQtd(); });
  campoObs.addEventListener("input", () => { contaObs.textContent = `${campoObs.value.length}/140`; });
  form.addEventListener("submit", (ev) => {
    ev.preventDefault();
    if (!atual) return;
    const editando = Boolean(linhaEmEdicao);
    adicionar(atual, qtdAtual, campoObs.value, linhaEmEdicao);
    UI.fechar(dProduto);
    window.CardapimToast(editando ? "Item atualizado na sacola." : `${qtdAtual}x ${atual.nome} na sacola.`);
    avisoContinuar = "";
  });

  produtos.forEach((p) => p.botao.addEventListener("click", () => abrirProduto(p)));

  /* ---------- Abas de categoria acompanham a rolagem ---------- */
  const abas = [...document.querySelectorAll("[data-aba]")];
  const listaAbas = document.querySelector("[data-abas]");
  const marcarAba = (id) => {
    abas.forEach((a) => {
      const ativa = a.dataset.aba === id;
      if (ativa) a.setAttribute("aria-current", "true");
      else a.removeAttribute("aria-current");
      if (ativa && listaAbas) {
        const alvo = a.offsetLeft - (listaAbas.clientWidth - a.offsetWidth) / 2;
        listaAbas.scrollTo({ left: Math.max(0, alvo), behavior: "smooth" });
      }
    });
  };
  let travaRolagem = 0;
  abas.forEach((a) => a.addEventListener("click", (ev) => {
    const secao = document.getElementById(`cat-${a.dataset.aba}`);
    if (!secao) return;
    ev.preventDefault();
    travaRolagem = Date.now();
    marcarAba(a.dataset.aba);
    secao.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start" });
    history.replaceState(null, "", `#cat-${a.dataset.aba}`);
  }));
  if (abas.length && "IntersectionObserver" in window) {
    const visiveis = new Map();
    const obs = new IntersectionObserver((entradas) => {
      entradas.forEach((e) => visiveis.set(e.target.dataset.categoria, e.isIntersecting ? e.boundingClientRect.top : null));
      if (Date.now() - travaRolagem < 700) return;
      const primeira = [...visiveis.entries()].filter(([, top]) => top !== null).sort((a, b) => a[1] - b[1])[0];
      if (primeira) marcarAba(primeira[0]);
    }, { rootMargin: "-140px 0px -55% 0px" });
    document.querySelectorAll("[data-categoria]").forEach((s) => obs.observe(s));
  }

  /* ---------- Busca no cardapio ---------- */
  const busca = document.querySelector("[data-busca]");
  const limparBusca = document.querySelectorAll("[data-busca-limpar]");
  const vazioBusca = document.querySelector("[data-busca-vazio]");
  const normalizar = (t) => t.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().trim();
  const itens = [...document.querySelectorAll("[data-produto-item]")].map((li) => ({ li, texto: normalizar(li.dataset.buscaTexto || "") }));
  let tempoBusca = null;
  const filtrar = () => {
    const termo = normalizar(busca.value);
    itens.forEach(({ li, texto }) => { li.hidden = termo !== "" && !texto.includes(termo); });
    let algum = false;
    document.querySelectorAll("[data-categoria]").forEach((s) => {
      const tem = [...s.querySelectorAll("[data-produto-item]")].some((li) => !li.hidden);
      s.hidden = !tem;
      algum = algum || tem;
    });
    limparBusca.forEach((b) => { if (b.closest(".cd-busca")) b.hidden = termo === ""; });
    if (listaAbas) listaAbas.hidden = termo !== "";
    if (vazioBusca) {
      vazioBusca.hidden = algum;
      vazioBusca.querySelector("[data-busca-termo]").textContent = busca.value.trim();
    }
  };
  busca?.addEventListener("input", () => {
    window.clearTimeout(tempoBusca);
    tempoBusca = window.setTimeout(filtrar, 120);
  });
  busca?.addEventListener("keydown", (ev) => { if (ev.key === "Enter") busca.blur(); });
  limparBusca.forEach((b) => b.addEventListener("click", () => {
    busca.value = "";
    filtrar();
    busca.focus();
  }));

  /* ---------- Inicio: recupera a sacola e confere com o servidor ---------- */
  sacola = ler();
  atualizar();
  if (sacola.length) {
    fetch(`/api/v1/restaurantes/${encodeURIComponent(slug)}/sacola`, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ itens: sacola.map((i) => ({ id: i.id, quantidade: i.qtd })) }),
    }).then((r) => (r.ok ? r.json() : null)).then((dados) => {
      if (!dados || !Array.isArray(dados.itens)) return;
      const atuais = new Map(dados.itens.map((i) => [i.id, i]));
      let mudou = false;
      sacola = sacola.filter((linha) => {
        const p = atuais.get(linha.id);
        if (!p) { mudou = true; return false; }
        if (p.centavos !== linha.centavos || p.nome !== linha.nome) mudou = true;
        linha.centavos = p.centavos;
        linha.nome = p.nome;
        linha.indisponivel = !p.disponivel;
        return true;
      });
      atualizar();
      if (mudou) window.CardapimToast("Atualizamos sua sacola com os preços de agora.");
    }).catch(() => { /* sem internet: segue com a sacola guardada */ });
  }
  Cliente?.ouvir?.(() => { if (!Cliente.atual()) { avisoContinuar = ""; atualizar(); } });
})();
