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
  const MAX = 20;
  let ultimo = null;

  const el = (tag, classe, texto) => {
    const n = document.createElement(tag);
    if (classe) n.className = classe;
    if (texto !== undefined) n.textContent = texto;
    return n;
  };

  /* ---------- Lista de produtos do teste ---------- */
  const atualizarVazio = () => { vazio.hidden = lista.children.length > 0; };

  const adicionar = () => {
    const select = raiz.querySelector("[data-teste-produto]");
    if (!select || !select.value) return;
    if (lista.children.length >= MAX) { mostrarErro("Use até " + MAX + " produtos no teste."); return; }
    const qtdCampo = raiz.querySelector("[data-teste-qtd]");
    const obsCampo = raiz.querySelector("[data-teste-obs]");
    const qtd = Math.min(99, Math.max(1, parseInt(qtdCampo.value, 10) || 1));
    const obs = obsCampo.value.trim().slice(0, 140);
    const nome = select.options[select.selectedIndex].textContent;

    const li = el("li");
    const texto = el("span", "impressao-teste-item-texto");
    texto.append(el("strong", "", qtd + "x " + nome));
    if (obs) texto.append(el("small", "", "Obs.: " + obs));
    for (const [nomeCampo, valor] of [["produto[]", select.value], ["quantidade[]", String(qtd)], ["observacao[]", obs]]) {
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
        cartao.append(desenhar(c.blocos, largura));
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
  atualizarVazio();
})();
