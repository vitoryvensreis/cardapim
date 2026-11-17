/*
 * Cardapim - cardapio publico /{slug}: abas de categoria que acompanham a rolagem, busca,
 * janela do produto (quantidade, complementos e observacao) e sacola.
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
  /* Venda por peso: a linha guarda a porcao em milesimos (500 = 500 g). Valor = preco do quilo x quantidade x
     porcao, arredondado ao centavo (a mesma conta do servidor). */
  const textoPeso = (m, unidade) => (m < 1000
    ? `${m} ${unidade === "l" ? "ml" : "g"}`
    : `${Math.floor(m / 1000)},${String(m % 1000).padStart(3, "0")} ${unidade === "l" ? "L" : "kg"}`);
  /* Complementos (adicionais, bordas...): a linha guarda as escolhas (comps) e quanto somam por unidade (compsCentavos). */
  const Comp = window.CardapimComplementos;
  const valorLinha = (i) => (i.porcao ? Math.floor((i.centavos * i.qtd * i.porcao + 500) / 1000) : (i.centavos + (i.compsCentavos || 0)) * i.qtd);
  const textoLinha = (i) => (i.porcao ? textoPeso(i.qtd * i.porcao, i.unidade) : `${i.qtd}x`);
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
      unidade: b.dataset.unidade || "un",
      precoTexto: b.dataset.precoTexto || "",
      porcoes: (b.dataset.porcoes || "").split(",").map(Number).filter((n) => Number.isInteger(n) && n > 0),
      grupos: (() => { try { return JSON.parse(b.dataset.grupos || "[]"); } catch (e) { return []; } })(),
      imagem: b.dataset.imagem || "",
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
      return dados.itens.filter((i) => Number.isInteger(i.id) && Number.isInteger(i.qtd) && i.qtd > 0
        && (i.porcao === undefined || i.porcao === null || (Number.isInteger(i.porcao) && i.porcao > 0))
        && (i.comps === undefined || (Array.isArray(i.comps) && i.comps.every((c) => Number.isInteger(c.id) && Number.isInteger(c.qtd) && c.qtd > 0)))).slice(0, 50);
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
  const chaveLinha = (id, obs, porcao, comps = []) => `${id}|${porcao || ""}|${comps.map((c) => `${c.id}x${c.qtd}`).sort().join(",")}|${obs.trim().toLowerCase()}`;
  const totais = () => sacola.reduce((t, i) => {
    if (i.indisponivel) return t;
    t.qtd += i.qtd;
    t.centavos += valorLinha(i);
    return t;
  }, { qtd: 0, centavos: 0 });

  const adicionar = (produto, qtd, obs, linhaEditada, porcao = null, comps = [], conferido = null) => {
    obs = obs.trim().slice(0, 140);
    if (linhaEditada) sacola = sacola.filter((i) => i.chave !== linhaEditada);
    const chave = chaveLinha(produto.id, obs, porcao, comps);
    const existente = sacola.find((i) => i.chave === chave);
    if (existente) existente.qtd = Math.min(MAX_QTD, existente.qtd + qtd);
    else {
      sacola.push({
        chave, id: produto.id, nome: produto.nome, centavos: produto.centavos, qtd, obs, porcao: porcao || null, unidade: produto.unidade,
        comps, compsCentavos: conferido ? conferido.centavos : 0, compsTexto: conferido ? conferido.texto : "",
      });
    }
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
      if (linha.porcao) texto.append(el("span", "cd-sacola-obs", linha.qtd > 1 ? `${linha.qtd} porções de ${textoPeso(linha.porcao, linha.unidade)}` : `Porção de ${textoPeso(linha.porcao, linha.unidade)}`));
      if (linha.compsTexto) texto.append(el("span", "cd-sacola-obs cd-sacola-comps", linha.compsTexto));
      if (linha.obs) texto.append(el("span", "cd-sacola-obs", linha.obs));
      if (linha.indisponivel) texto.append(el("span", "cd-sacola-alerta", "Indisponível agora. Remova para continuar."));
      else texto.append(el("span", "cd-sacola-preco", reais(valorLinha(linha))));
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
  const campoPorcoes = dProduto.querySelector("[data-produto-porcoes]");
  const opcoesPorcoes = dProduto.querySelector("[data-porcoes-opcoes]");
  const areaGrupos = dProduto.querySelector("[data-produto-grupos]");
  const erroGrupos = dProduto.querySelector("[data-grupos-erro]");
  const fotoProduto = dProduto.querySelector("[data-produto-foto]");
  let compsAtual = new Map(); // id da opcao -> quantidade
  let atual = null;
  let qtdAtual = 1;
  let porcaoAtual = null;
  let linhaEmEdicao = null;

  const pintarQtd = () => {
    saidaQtd.textContent = String(qtdAtual);
    btnMenos.disabled = qtdAtual <= 1;
    btnMais.disabled = qtdAtual >= MAX_QTD;
    totalAdicionar.textContent = reais(valorLinha({ centavos: atual.centavos, qtd: qtdAtual, porcao: porcaoAtual, compsCentavos: somaComps() }));
  };

  /* Complementos: cada grupo vira uma lista de escolhas. Maximo 1 = escolha unica (tocar de novo desmarca se for opcional). */
  const escolhasAtuais = () => [...compsAtual.entries()].map(([id, qtd]) => ({ id, qtd }));
  const somaComps = () => {
    if (!atual || !atual.grupos.length) return 0;
    let total = 0;
    atual.grupos.forEach((g) => g.opcoes.forEach((o) => { total += (compsAtual.get(o.id) || 0) * o.centavos; }));
    return total;
  };
  const marcarGrupo = (g) => {
    const caixa = areaGrupos.querySelector(`[data-grupo="${g.id}"]`);
    if (!caixa) return;
    const soma = g.opcoes.reduce((t, o) => t + (compsAtual.get(o.id) || 0), 0);
    caixa.querySelectorAll("input[data-opcao]").forEach((input) => {
      const id = Number(input.value);
      input.checked = compsAtual.has(id);
      const opcao = g.opcoes.find((o) => o.id === id);
      input.disabled = !opcao?.disponivel || (g.maximo > 1 && soma >= g.maximo && !compsAtual.has(id));
    });
    const contador = caixa.querySelector("[data-grupo-contador]");
    if (contador) contador.textContent = g.maximo > 1 ? `${soma} de ${g.maximo}` : "";
    caixa.classList.toggle("is-completo", soma >= g.minimo);
  };
  const desenharGrupos = (produto) => {
    areaGrupos.replaceChildren();
    erroGrupos.hidden = true;
    areaGrupos.hidden = produto.grupos.length === 0;
    produto.grupos.forEach((g) => {
      const caixa = el("fieldset", "cd-grupo");
      caixa.dataset.grupo = String(g.id);
      const legenda = el("legend", "cd-grupo-titulo");
      legenda.append(el("strong", "", g.nome), el("small", g.minimo > 0 ? "cd-grupo-regra is-obrigatorio" : "cd-grupo-regra", Comp.regra(g)));
      const contador = el("span", "cd-grupo-contador");
      contador.dataset.grupoContador = "";
      contador.setAttribute("aria-live", "polite");
      legenda.append(contador);
      caixa.append(legenda);
      g.opcoes.forEach((o) => {
        const rotulo = el("label", "cd-opcao" + (o.disponivel ? "" : " is-indisponivel"));
        const input = el("input");
        input.type = g.maximo === 1 ? "radio" : "checkbox";
        input.name = `grupo-${g.id}`;
        input.value = String(o.id);
        input.dataset.opcao = "";
        input.addEventListener("click", () => {
          if (g.maximo === 1) {
            const jaEra = compsAtual.has(o.id);
            g.opcoes.forEach((x) => compsAtual.delete(x.id));
            if (!jaEra || g.minimo > 0) compsAtual.set(o.id, 1);
          } else if (compsAtual.has(o.id)) compsAtual.delete(o.id);
          else compsAtual.set(o.id, 1);
          erroGrupos.hidden = true;
          caixa.classList.remove("tem-erro");
          marcarGrupo(g);
          pintarQtd();
        });
        if (o.imagem) {
          const foto = el("img", "cd-opcao-foto");
          foto.src = o.imagem;
          foto.alt = "";
          foto.loading = "lazy";
          foto.width = 48;
          foto.height = 48;
          rotulo.append(input, foto);
        } else rotulo.append(input);
        const texto = el("span", "cd-opcao-texto");
        texto.append(el("strong", "", o.nome));
        if (o.descricao) texto.append(el("small", "", o.descricao));
        rotulo.append(texto, el("span", "cd-opcao-preco", !o.disponivel ? "Acabou" : (o.centavos > 0 ? `+ ${reais(o.centavos)}` : "Grátis")));
        caixa.append(rotulo);
      });
      areaGrupos.append(caixa);
      marcarGrupo(g);
    });
  };

  // Produto por peso: o cliente escolhe uma das porcoes cadastradas pelo restaurante.
  const desenharPorcoes = (produto) => {
    opcoesPorcoes.replaceChildren();
    campoPorcoes.hidden = produto.porcoes.length === 0;
    produto.porcoes.forEach((m) => {
      const rotulo = el("label", "cd-porcao");
      const radio = el("input");
      radio.type = "radio";
      radio.name = "porcao";
      radio.value = String(m);
      radio.checked = m === porcaoAtual;
      radio.addEventListener("change", () => { porcaoAtual = m; pintarQtd(); });
      const texto = el("span");
      texto.append(el("strong", "", textoPeso(m, produto.unidade)), el("small", "", reais(Math.floor((produto.centavos * m + 500) / 1000))));
      rotulo.append(radio, texto);
      opcoesPorcoes.append(rotulo);
    });
  };

  const abrirProduto = (produto, linha = null) => {
    if (!produto || !produto.disponivel) return;
    atual = produto;
    linhaEmEdicao = linha ? linha.chave : null;
    qtdAtual = linha ? linha.qtd : 1;
    porcaoAtual = produto.porcoes.length ? (linha && produto.porcoes.includes(linha.porcao) ? linha.porcao : produto.porcoes[0]) : null;
    compsAtual = new Map((linha?.comps || []).map((c) => [c.id, c.qtd]));
    desenharPorcoes(produto);
    desenharGrupos(produto);
    fotoProduto.hidden = !produto.imagem;
    if (produto.imagem) fotoProduto.src = produto.imagem;
    dProduto.querySelector("[data-produto-nome]").textContent = produto.nome;
    const desc = dProduto.querySelector("[data-produto-desc]");
    desc.textContent = produto.descricao;
    desc.hidden = produto.descricao === "";
    dProduto.querySelector("[data-produto-preco]").textContent = produto.porcoes.length ? produto.precoTexto : reais(produto.centavos);
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
    const comps = atual.grupos.length ? escolhasAtuais() : [];
    const conferido = atual.grupos.length ? Comp.conferir(atual.grupos, comps) : null;
    if (conferido && !conferido.ok) {
      erroGrupos.textContent = conferido.erro;
      erroGrupos.hidden = false;
      const caixa = conferido.grupo ? areaGrupos.querySelector(`[data-grupo="${conferido.grupo}"]`) : null;
      if (caixa) {
        caixa.classList.add("tem-erro");
        caixa.scrollIntoView({ block: "center", behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth" });
        caixa.querySelector("input:not(:disabled)")?.focus({ preventScroll: true });
      }
      return;
    }
    const editando = Boolean(linhaEmEdicao);
    adicionar(atual, qtdAtual, campoObs.value, linhaEmEdicao, porcaoAtual, comps, conferido);
    UI.fechar(dProduto);
    window.CardapimToast(editando ? "Item atualizado na sacola." : `${textoLinha({ qtd: qtdAtual, porcao: porcaoAtual, unidade: atual.unidade })} ${atual.nome} na sacola.`);
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
      body: JSON.stringify({ itens: sacola.map((i) => ({ id: i.id, quantidade: i.qtd, porcao: i.porcao || null, complementos: (i.comps || []).map((c) => ({ id: c.id, quantidade: c.qtd })) })) }),
    }).then((r) => (r.ok ? r.json() : null)).then((dados) => {
      if (!dados || !Array.isArray(dados.itens)) return;
      const atuais = new Map(dados.itens.map((i) => [i.id, i]));
      let mudou = false;
      sacola = sacola.filter((linha) => {
        const p = atuais.get(linha.id);
        if (!p) { mudou = true; return false; }
        // Porcao que o restaurante tirou (ou produto que mudou de unidade): a linha sai da sacola.
        const porcoes = Array.isArray(p.porcoes) ? p.porcoes : [];
        if ((linha.porcao && !porcoes.includes(linha.porcao)) || (!linha.porcao && p.unidade && p.unidade !== "un")) { mudou = true; return false; }
        // Complementos de agora: escolha que sumiu, acabou ou ficou faltando tira a linha (o cliente escolhe de novo).
        const grupos = Array.isArray(p.grupos) ? p.grupos : [];
        if (grupos.length || (linha.comps || []).length) {
          const r = Comp.conferir(grupos, linha.comps || []);
          if (!r.ok) { mudou = true; return false; }
          if (r.centavos !== (linha.compsCentavos || 0)) mudou = true;
          linha.compsCentavos = r.centavos;
          linha.compsTexto = r.texto;
        }
        const produto = produtos.get(linha.id);
        if (produto) produto.grupos = grupos;
        if (p.centavos !== linha.centavos || p.nome !== linha.nome) mudou = true;
        linha.centavos = p.centavos;
        linha.nome = p.nome;
        linha.unidade = p.unidade || "un";
        linha.indisponivel = !p.disponivel;
        return true;
      });
      atualizar();
      if (mudou) window.CardapimToast("Atualizamos sua sacola com os preços de agora.");
    }).catch(() => { /* sem internet: segue com a sacola guardada */ });
  }
  Cliente?.ouvir?.(() => { if (!Cliente.atual()) { avisoContinuar = ""; atualizar(); } });
})();
