/*
 * Gerencia > Impressoras > Testar impressao (carregado com defer).
 * Monta a lista de produtos do teste, pede ao servidor os cupons (mesma regra e mesmo montador da impressao real)
 * e desenha cada cupom como sai no papel, quebrando as linhas do mesmo jeito que o Deliveryzim Agent.
 */
(() => {
  "use strict";

  const raiz = document.querySelector("[data-impressao-teste]");
  if (!raiz) return;
  const form = raiz.querySelector("[data-teste-form]");
  const lista = raiz.querySelector("[data-teste-itens]");
  const vazio = raiz.querySelector("[data-teste-vazio]");
  const resultado = raiz.querySelector("[data-teste-resultado]");
  const papel = raiz.querySelector("[data-teste-papel]");
  const conta = raiz.querySelector("[data-teste-conta]");
  const botaoVer = raiz.querySelector("[data-teste-ver]");
  const botaoImprimir = raiz.querySelector("[data-teste-imprimir]");
  const areaEscolhas = raiz.querySelector("[data-teste-escolhas]");
  const erroEscolhas = raiz.querySelector("[data-teste-escolhas-erro]");
  const logo = raiz.dataset.logo || "";
  const regraTexto = window.CardapimComplementos ? window.CardapimComplementos.regra : null;
  const MAX = 20;
  let ultimo = null;

  const el = (tag, classe, texto) => {
    const n = document.createElement(tag);
    if (classe) n.className = classe;
    if (texto !== undefined) n.textContent = texto;
    return n;
  };

  const reais = (c) => "R$ " + (c / 100).toFixed(2).replace(".", ",");
  const lerJson = (texto, padrao) => { try { return texto ? JSON.parse(texto) : padrao; } catch (_) { return padrao; } };

  /* ---------- Escolhas do produto (complementos e combo), como no caixa ---------- */
  let grupos = [];
  let combo = null;
  const select = raiz.querySelector("[data-teste-produto]");

  const avisoEscolha = (texto) => {
    if (!erroEscolhas) return;
    erroEscolhas.textContent = texto || "";
    erroEscolhas.hidden = !texto;
  };

  // Quantidade escolhida de cada opcao: Map(id -> qtd), por grupo.
  const escolhidas = () => {
    const mapa = new Map();
    if (!areaEscolhas) return mapa;
    areaEscolhas.querySelectorAll("[data-opcao]").forEach((c) => {
      const q = c.type === "checkbox" ? (c.checked ? 1 : 0) : (parseInt(c.value, 10) || 0);
      if (q > 0) mapa.set(Number(c.dataset.opcao), q);
    });
    return mapa;
  };
  const somaGrupo = (g, mapa) => (g.opcoes || []).reduce((t, o) => t + (mapa.get(o.id) || 0), 0);
  const somaTudo = (mapa) => [...mapa.values()].reduce((t, q) => t + q, 0);

  const regra = (g) => (regraTexto ? regraTexto(g) : (g.minimo > 0 ? "Obrigatório" : "Opcional"));

  const passo = (g, o) => {
    const linha = el("div", "impressao-teste-passo");
    const nome = el("span", "impressao-teste-passo-nome", o.nome + (o.centavos > 0 ? " +" + reais(o.centavos) : "") + (o.maximo ? " · até " + o.maximo : ""));
    const campo = document.createElement("input");
    campo.type = "hidden";
    campo.value = "0";
    campo.dataset.opcao = String(o.id);
    const valor = el("output", "impressao-teste-passo-valor", "0");
    const botao = (rotulo, delta) => {
      const b = el("button", "impressao-teste-passo-btn", delta > 0 ? "+" : "\u2212");
      b.type = "button";
      b.setAttribute("aria-label", rotulo + " " + o.nome);
      b.addEventListener("click", () => {
        const atual = parseInt(campo.value, 10) || 0;
        const mapa = escolhidas();
        if (delta > 0) {
          if (somaGrupo(g, mapa) >= g.maximo) { avisoEscolha("Em \"" + g.nome + "\" o máximo é " + g.maximo + "."); return; }
          if (o.maximo && atual >= o.maximo) { avisoEscolha("No máximo " + o.maximo + " de \"" + o.nome + "\" neste combo."); return; }
          if (combo && combo.maximo_escolhas !== null && somaTudo(mapa) >= combo.maximo_escolhas) { avisoEscolha("Este combo deixa escolher até " + combo.maximo_escolhas + "."); return; }
        } else if (atual === 0) return;
        campo.value = String(atual + delta);
        valor.textContent = campo.value;
        linha.classList.toggle("is-escolhido", atual + delta > 0);
        avisoEscolha("");
      });
      return b;
    };
    linha.append(nome, botao("Tirar um", -1), valor, botao("Mais um", 1), campo);
    return linha;
  };

  const chip = (g, o, caixa) => {
    const rotulo = el("label", "impressao-teste-chip");
    const input = document.createElement("input");
    input.type = "checkbox";
    input.dataset.opcao = String(o.id);
    input.addEventListener("change", () => {
      // Maximo 1: escolha unica. Maximo maior: nao passa do limite.
      if (g.maximo === 1 && input.checked) caixa.querySelectorAll("input:checked").forEach((c) => { if (c !== input) c.checked = false; });
      else if (caixa.querySelectorAll("input:checked").length > g.maximo) { input.checked = false; avisoEscolha("Em \"" + g.nome + "\" o máximo é " + g.maximo + "."); return; }
      avisoEscolha("");
    });
    rotulo.append(input, el("span", "", o.nome + (o.centavos > 0 ? " +" + reais(o.centavos) : "")));
    return rotulo;
  };

  const desenharEscolhas = () => {
    const opt = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
    grupos = lerJson(opt && opt.dataset.grupos, []);
    combo = lerJson(opt && opt.dataset.combo, null);
    avisoEscolha("");
    if (!areaEscolhas) return;
    areaEscolhas.replaceChildren();
    areaEscolhas.hidden = grupos.length === 0 && !combo;
    if (areaEscolhas.hidden) return;
    const topo = el("p", "impressao-teste-escolhas-topo");
    topo.append(el("strong", "", combo ? "Monte o combo" : "Escolhas do produto"));
    const partes = [];
    if (combo && combo.inclusos.length) partes.push("Incluso: " + combo.inclusos.join(" · "));
    if (combo && combo.maximo_escolhas !== null && grupos.length) partes.push("Escolha até " + combo.maximo_escolhas);
    if (partes.length) topo.append(el("span", "", partes.join(" · ")));
    areaEscolhas.append(topo);
    grupos.forEach((g) => {
      const caixa = el("fieldset", "impressao-teste-grupo" + (combo || g.repetir ? " is-combo" : ""));
      const legenda = el("legend", "", g.nome);
      legenda.append(el("span", "etiqueta " + (g.minimo > 0 ? "etiqueta-atencao" : "etiqueta-neutra"), regra(g)));
      caixa.append(legenda);
      (g.opcoes || []).forEach((o) => caixa.append(combo || g.repetir ? passo(g, o) : chip(g, o, caixa)));
      areaEscolhas.append(caixa);
    });
  };
  select?.addEventListener("change", desenharEscolhas);

  // Confere as escolhas (o servidor confere tudo de novo). Devolve {erro} ou {valor, texto}.
  const conferirEscolhas = () => {
    const mapa = escolhidas();
    const partes = [];
    for (const g of grupos) {
      const soma = somaGrupo(g, mapa);
      if (soma < g.minimo) return { erro: g.minimo === 1 && g.maximo === 1 ? "Escolha uma opção em \"" + g.nome + "\"." : "Escolha pelo menos " + g.minimo + " em \"" + g.nome + "\"." };
      if (soma > g.maximo) return { erro: "Escolha no máximo " + g.maximo + " em \"" + g.nome + "\"." };
      const nomes = (g.opcoes || []).filter((o) => mapa.get(o.id)).map((o) => (mapa.get(o.id) > 1 ? mapa.get(o.id) + "x " : "") + o.nome);
      if (nomes.length) partes.push(g.nome + ": " + nomes.join(", "));
    }
    if (combo && !combo.inclusos.length && !somaTudo(mapa)) return { erro: "Escolha os itens do combo." };
    return { valor: [...mapa].map(([id, q]) => id + ":" + q).join(","), texto: partes.join(" · ") };
  };

  /* ---------- Lista de produtos do teste ---------- */
  const atualizarVazio = () => { vazio.hidden = lista.children.length > 0; };

  const adicionar = () => {
    if (!select || !select.value) return;
    if (lista.children.length >= MAX) { mostrarErro("Use até " + MAX + " produtos no teste."); return; }
    const conf = conferirEscolhas();
    if (conf.erro) { avisoEscolha(conf.erro); return; }
    const qtdCampo = raiz.querySelector("[data-teste-qtd]");
    const obsCampo = raiz.querySelector("[data-teste-obs]");
    const qtd = Math.min(99, Math.max(1, parseInt(qtdCampo.value, 10) || 1));
    const obs = obsCampo.value.trim().slice(0, 140);
    const nome = select.options[select.selectedIndex].textContent.replace(/ \((combo|com escolhas)\)$/, "");

    const li = el("li");
    const texto = el("span", "impressao-teste-item-texto");
    texto.append(el("strong", "", qtd + "x " + nome));
    if (combo && combo.inclusos.length) texto.append(el("small", "", "Incluso: " + combo.inclusos.join(" · ")));
    if (conf.texto) texto.append(el("small", "", conf.texto));
    if (obs) texto.append(el("small", "", "Obs.: " + obs));
    for (const [nomeCampo, valor] of [["produto[]", select.value], ["quantidade[]", String(qtd)], ["observacao[]", obs], ["complementos[]", conf.valor]]) {
      const h = document.createElement("input");
      h.type = "hidden"; h.name = nomeCampo; h.value = valor;
      li.append(h);
    }
    const tirar = el("button", "btn btn-outline btn-sm", "Tirar");
    tirar.type = "button";
    tirar.setAttribute("aria-label", "Tirar " + nome + " do teste");
    tirar.addEventListener("click", () => { li.remove(); atualizarVazio(); });
    li.append(texto, tirar);
    lista.append(li);
    qtdCampo.value = "1";
    obsCampo.value = "";
    desenharEscolhas();
    atualizarVazio();
  };
  raiz.querySelector("[data-teste-adicionar]")?.addEventListener("click", adicionar);
  raiz.querySelector("[data-teste-obs]")?.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter") { ev.preventDefault(); adicionar(); }
  });

  const situacao = () => (form.querySelector('input[name="situacao"]:checked') || {}).value || "delivery";
  const trocarSituacao = () => { conta.hidden = situacao() !== "conta"; };
  form.addEventListener("change", (ev) => { if (ev.target.name === "situacao") trocarSituacao(); });
  trocarSituacao();

  /* ---------- Papel: mesma quebra de linha do Agent (printing.py) ---------- */
  // A impressora entende o alfabeto cp850 (acentos do portugues); o resto (emoji, simbolos) sai como "?".
  let semSuporte = false;
  const limpar = (texto) => String(texto ?? "").replace(/[^ -ÿ]/gu, () => { semSuporte = true; return "?"; });

  const quebrar = (texto, largura) => {
    const palavras = limpar(texto).split(/\s+/).filter(Boolean);
    const linhas = [];
    let atual = "";
    for (let p of palavras) {
      while (p.length > largura) {
        if (atual) { linhas.push(atual); atual = ""; }
        linhas.push(p.slice(0, largura));
        p = p.slice(largura);
      }
      if (!atual) atual = p;
      else if (atual.length + 1 + p.length <= largura) atual += " " + p;
      else { linhas.push(atual); atual = p; }
    }
    if (atual) linhas.push(atual);
    return linhas;
  };

  const par = (rotulo, valor, largura) => {
    valor = limpar(valor);
    const espaco = largura - valor.length - 1;
    if (espaco < 6) return [...quebrar(rotulo, largura), valor.padStart(largura)];
    const partes = quebrar(rotulo, espaco);
    if (!partes.length) partes.push("");
    const fim = partes.pop();
    return [...partes, fim.padEnd(espaco) + " " + valor];
  };

  const desenhar = (blocos, largura) => {
    const papelEl = el("div", "cupom-papel");
    papelEl.dataset.colunas = String(largura);
    // Logo oficial no topo, como o Agent imprime (quando "Imprimir a logo" esta marcado nele).
    if (logo) {
      const img = document.createElement("img");
      img.className = "cupom-logo";
      img.src = logo;
      img.alt = "Deliveryzim";
      img.decoding = "async";
      papelEl.append(img);
    }
    const linha = (texto, classe) => papelEl.append(el("span", "cupom-linha" + (classe ? " " + classe : ""), texto || " "));
    for (const b of blocos) {
      const t = b.t;
      if (t === "titulo") quebrar(b.texto, Math.floor(largura / 2)).forEach((l) => linha(l, "is-titulo"));
      else if (t === "centro") quebrar(b.texto, largura).forEach((l) => linha(l, "is-centro"));
      else if (t === "destaque") quebrar(b.texto, largura).forEach((l) => linha(l, "is-forte"));
      else if (t === "linha") linha("-".repeat(largura));
      else if (t === "valor") par(b.rotulo, b.valor, largura).forEach((l) => linha(l, b.forte ? "is-forte" : ""));
      else if (t === "item") {
        const nome = ((b.qtd || "").trim() + " " + (b.nome || "")).trim();
        par(nome, b.valor || "", largura).forEach((l) => linha(l, "is-forte"));
        (b.detalhes || []).forEach((d) => quebrar(d, largura - 2).forEach((l) => linha("  " + l)));
      } else quebrar(b.texto, largura).forEach((l) => linha(l));
    }
    return papelEl;
  };

  /* ---------- Resultado ---------- */
  const mostrarErro = (texto) => {
    resultado.replaceChildren(el("p", "impressao-teste-erro", texto));
  };

  const mostrar = (dados) => {
    const largura = parseInt(papel.value, 10) || 48;
    semSuporte = false;
    // Com o cupom em imagem, a largura vem da impressora (informada pelo Agent): a escolha da tela some.
    const campoPapel = papel.closest(".impressao-teste-papel");
    if (campoPapel) campoPapel.hidden = (dados.cupons || []).some((c) => c.imagem);
    const cartoes = el("div", "impressao-teste-cartoes");
    for (const c of dados.cupons || []) {
      const cartao = el("article", "impressao-teste-cartao" + (c.blocos ? "" : " is-vazio"));
      const topo = el("header", "impressao-teste-cartao-topo");
      topo.append(el("h3", "", c.apelido));
      const etiquetas = el("span", "impressao-teste-etiquetas");
      if (c.padrao && situacao() !== "conta") etiquetas.append(el("span", "etiqueta etiqueta-neutra", "Padrão"));
      etiquetas.append(el("span", "etiqueta " + (c.ligada ? "etiqueta-ok" : "etiqueta-neutra"), c.ligada ? "Ligada" : "Desligada"));
      topo.append(etiquetas);
      cartao.append(topo);
      if (!c.blocos) {
        cartao.append(el("p", "impressao-teste-nada", "Não sai nada nesta impressora."));
      } else {
        if (c.imagem) {
          // Agent 0.9.0+: o cupom sai exatamente como esta imagem (papel, logo e espaço antes do corte da impressora).
          const img = document.createElement("img");
          img.className = "cupom-imagem";
          img.src = c.imagem;
          img.alt = "Cupom de " + c.apelido + " como sai na impressora";
          img.decoding = "async";
          img.dataset.papel = String(c.papel || 80);
          cartao.append(img, el("p", "impressao-teste-papel-info", "Papel de " + (c.papel || 80) + " mm. Confira no Deliveryzim Agent se é a largura certa."));
        } else {
          cartao.append(desenhar(c.blocos, largura));
        }
        const por = el("ul", "impressao-teste-motivos");
        for (const m of c.motivos || []) {
          const li = el("li");
          li.append(el("strong", "", m.nome), el("span", "", m.motivo));
          por.append(li);
        }
        cartao.append(por);
      }
      cartoes.append(cartao);
    }
    const avisos = [...(dados.avisos || [])];
    if (semSuporte) avisos.push("Há letras ou símbolos que a impressora não imprime (ex.: emoji). Eles aparecem como \"?\" no papel.");
    const partes = [];
    if (dados.mensagem) partes.push(el("p", "impressao-teste-ok", dados.mensagem));
    if (avisos.length) {
      const ul = el("ul", "impressao-teste-avisos");
      avisos.forEach((a) => ul.append(el("li", "", a)));
      partes.push(ul);
    }
    partes.push(cartoes);
    if (logo) partes.push(el("p", "impressao-teste-dica", "A logo sai no topo quando \"Imprimir a logo do Deliveryzim no cupom\" está marcado no Deliveryzim Agent."));
    resultado.replaceChildren(...partes);
  };

  const enviar = async (imprimir) => {
    if (!lista.children.length) { mostrarErro("Adicione pelo menos um produto ao teste."); return; }
    const dados = new FormData(form);
    if (imprimir) dados.set("imprimir", "1");
    botaoVer.disabled = botaoImprimir.disabled = true;
    resultado.replaceChildren(el("p", "impressao-teste-carregando", imprimir ? "Enviando o teste para as impressoras..." : "Montando os cupons..."));
    try {
      const r = await fetch(form.action, { method: "POST", body: dados, headers: { Accept: "application/json" }, credentials: "same-origin" });
      let json = {};
      try { json = await r.json(); } catch (_) { json = {}; }
      if (!r.ok || !json.ok) { mostrarErro(json.erro || "Não foi possível montar o teste. Tente de novo."); return; }
      ultimo = json;
      mostrar(json);
    } catch (_) {
      mostrarErro("Sem conexão com o sistema. Confira a internet e tente de novo.");
    } finally {
      botaoVer.disabled = botaoImprimir.disabled = false;
    }
  };

  form.addEventListener("submit", (ev) => { ev.preventDefault(); enviar(false); });
  botaoImprimir.addEventListener("click", () => enviar(true));
  papel.addEventListener("change", () => { if (ultimo) mostrar(ultimo); });
  desenharEscolhas();
  atualizarVazio();
})();
