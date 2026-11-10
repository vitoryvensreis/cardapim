/*
 * Cardapim - acompanhar pedido (/{slug}/pedido/{id}).
 * Busca o pedido na API e atualiza sozinho (a cada 5 s esperando o Pix, a cada 15 s depois) ate terminar.
 */
(() => {
  "use strict";

  const raiz = document.querySelector("[data-acompanhar]");
  if (!raiz) return;
  const id = raiz.dataset.acompanhar;
  const Cliente = window.CardapimCliente;
  const dinheiro = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
  const reais = (c) => dinheiro.format(c / 100).replace(/ /g, " ");
  const $ = (sel) => raiz.querySelector(sel);
  const mostrar = (nome) => {
    ["carregando", "login", "erro", "conteudo"].forEach((n) => { $(`[data-ap-${n}]`).hidden = n !== nome; });
  };
  const icone = (nome) => {
    const ns = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(ns, "svg");
    svg.setAttribute("class", "icon");
    svg.setAttribute("aria-hidden", "true");
    const use = document.createElementNS(ns, "use");
    const sprite = document.querySelector("svg use")?.getAttribute("href")?.split("#")[0] || "/assets/icons/lucide.svg";
    use.setAttribute("href", `${sprite}#${nome}`);
    svg.append(use);
    return svg;
  };

  const toast = raiz.querySelector("[data-toast]");
  const avisar = (texto) => {
    if (!toast) return;
    toast.textContent = texto;
    toast.hidden = false;
    window.setTimeout(() => { toast.hidden = true; }, 2400);
  };

  const TEXTOS = {
    aguardando_pagamento: ["qr-code", "Falta só o Pix", "Assim que o pagamento cair, o restaurante começa a preparar."],
    recebido: ["circle-check", "Pedido recebido", "O restaurante já recebeu seu pedido."],
    em_preparo: ["chef-hat", "Em preparo", "Seu pedido está sendo preparado."],
    pronto: ["package-check", "Pronto", "Seu pedido está pronto."],
    saiu_entrega: ["bike", "Saiu para entrega", "O entregador está a caminho."],
    concluido: ["circle-check", "Pedido concluído", "Bom apetite! Obrigado por pedir pelo Cardapim."],
    cancelado: ["circle-x", "Pedido cancelado", ""],
  };

  let ultimoStatus = "";
  let qrDesenhado = "";
  let relogio = null;
  let segundosPix = 0;
  let proxima = null;

  const desenharPix = (pix) => {
    const bloco = $("[data-ap-pix]");
    if (!pix) {
      bloco.hidden = true;
      window.clearInterval(relogio);
      return;
    }
    bloco.hidden = false;
    if (qrDesenhado !== pix.copia_cola) {
      qrDesenhado = pix.copia_cola;
      $("[data-ap-pix-codigo]").value = pix.copia_cola;
      try {
        const qr = window.qrcode(0, "M");
        qr.addData(pix.copia_cola);
        qr.make();
        $("[data-ap-pix-qr]").innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true });
      } catch (e) {
        $("[data-ap-pix-qr]").textContent = "Use o código abaixo no app do seu banco.";
      }
    }
    segundosPix = pix.expira_em_segundos;
    window.clearInterval(relogio);
    const pintar = () => {
      const m = Math.floor(segundosPix / 60);
      const s = String(segundosPix % 60).padStart(2, "0");
      $("[data-ap-pix-tempo]").textContent = segundosPix > 0 ? `Pague em até ${m}:${s} para garantir seu pedido.` : "O prazo do Pix terminou. Atualizando...";
    };
    pintar();
    relogio = window.setInterval(() => {
      segundosPix = Math.max(0, segundosPix - 1);
      pintar();
    }, 1000);
  };

  const desenhar = (p) => {
    mostrar("conteudo");
    const [ic, titulo, texto] = TEXTOS[p.status] || ["info", p.status_rotulo, ""];
    const caixa = $("[data-ap-status]");
    caixa.className = `ck-status is-${p.status}`;
    $("[data-ap-icone]").replaceChildren(icone(ic));
    $("[data-ap-numero]").textContent = `Pedido #${p.numero} · feito às ${p.criado_em}`;
    $("[data-ap-titulo]").textContent = p.status === "pronto" && p.tipo === "retirada" ? "Pronto para retirar" : (p.status === "concluido" ? (p.tipo === "entrega" ? "Pedido entregue" : "Pedido retirado") : titulo);
    let t = texto;
    if (p.status === "cancelado") t = p.cancelado_motivo || "Este pedido foi cancelado.";
    else if (["recebido", "em_preparo"].includes(p.status) && p.previsao) t += ` Previsão: ${p.previsao}.`;
    $("[data-ap-texto]").textContent = t;
    document.title = `Pedido #${p.numero} · ${p.status_rotulo}`;

    desenharPix(p.pix);

    // Linha do tempo (some enquanto espera o Pix e quando cancelado).
    const etapas = $("[data-ap-etapas]");
    $("[data-ap-etapas-bloco]").hidden = p.status === "aguardando_pagamento" || p.status === "cancelado";
    etapas.replaceChildren();
    const ordem = p.etapas.map((e) => e.status);
    const atual = ordem.indexOf(p.status);
    p.etapas.forEach((e, i) => {
      const li = document.createElement("li");
      li.className = i < atual ? "is-feito" : (i === atual ? "is-atual" : "");
      if (i === atual) li.setAttribute("aria-current", "step");
      const marca = document.createElement("span");
      marca.className = "ck-etapa-marca";
      marca.setAttribute("aria-hidden", "true");
      if (i <= atual) marca.append(icone("check"));
      const nome = document.createElement("span");
      nome.className = "ck-etapa-nome";
      nome.textContent = e.rotulo;
      const hora = document.createElement("small");
      hora.textContent = e.em || "";
      li.append(marca, nome, hora);
      etapas.append(li);
    });

    const itens = $("[data-ap-itens]");
    itens.replaceChildren();
    p.itens.forEach((i) => {
      const li = document.createElement("li");
      const q = document.createElement("span");
      q.className = "ck-item-qtd";
      q.textContent = `${i.quantidade}x`;
      const n = document.createElement("span");
      n.className = "ck-item-nome";
      n.textContent = i.nome;
      if (i.observacao) {
        const o = document.createElement("small");
        o.textContent = i.observacao;
        n.append(o);
      }
      const v = document.createElement("span");
      v.className = "ck-item-preco";
      v.textContent = reais(i.total_centavos);
      li.append(q, n, v);
      itens.append(li);
    });
    $("[data-ap-subtotal]").textContent = reais(p.subtotal_centavos);
    $("[data-ap-linha-taxa]").hidden = p.tipo !== "entrega";
    $("[data-ap-taxa]").textContent = p.taxa_centavos === 0 ? "Grátis" : reais(p.taxa_centavos);
    $("[data-ap-total]").textContent = reais(p.total_centavos);
    let pag = p.forma_pagamento;
    if (p.pagamento_status === "pago") pag += " · pago";
    if (p.troco_para_centavos) pag += ` · troco para ${reais(p.troco_para_centavos)}`;
    $("[data-ap-pagamento]").textContent = pag;
    $("[data-ap-endereco-rotulo]").textContent = p.tipo === "entrega" ? "Entregar em" : "Retirar no restaurante";
    $("[data-ap-endereco]").textContent = p.tipo === "entrega" ? [p.endereco, p.referencia].filter(Boolean).join(" · Ref.: ") : "Você busca no restaurante.";
    $("[data-ap-linha-obs]").hidden = !p.observacao;
    $("[data-ap-obs]").textContent = p.observacao;

    if (ultimoStatus && ultimoStatus !== p.status) {
      avisar(p.status === "recebido" && ultimoStatus === "aguardando_pagamento" ? "Pix confirmado! Pedido enviado ao restaurante." : `Agora: ${p.status_rotulo}`);
      if (navigator.vibrate) navigator.vibrate(120);
    }
    ultimoStatus = p.status;
    window.clearTimeout(proxima);
    if (!p.final) proxima = window.setTimeout(carregar, p.status === "aguardando_pagamento" ? 5000 : 15000);
  };

  const carregar = async () => {
    const r = await Cliente.api(`/api/v1/pedidos/${id}`);
    if (r.id) {
      desenhar(r);
      return;
    }
    if (r.http === 401) {
      mostrar("login");
      return;
    }
    if (r.http === 404) {
      mostrar("erro");
      return;
    }
    // Sem internet ou erro temporario: mantem a tela e tenta de novo.
    if (ultimoStatus === "") {
      $("[data-ap-erro-titulo]").textContent = "Não foi possível carregar agora";
      $("[data-ap-erro-texto]").textContent = r.mensagem || "Confira sua internet. Vamos tentar de novo sozinhos.";
      mostrar("erro");
    }
    window.clearTimeout(proxima);
    proxima = window.setTimeout(carregar, 8000);
  };

  $("[data-ap-entrar]").addEventListener("click", () => {
    Cliente.entrar({ motivo: "para ver seu pedido" }).then((c) => { if (c) { mostrar("carregando"); carregar(); } });
  });

  $("[data-ap-copiar]").addEventListener("click", async () => {
    const campo = $("[data-ap-pix-codigo]");
    const texto = $("[data-ap-copiar-texto]");
    try {
      await navigator.clipboard.writeText(campo.value);
    } catch (e) {
      campo.select();
      document.execCommand("copy");
    }
    texto.textContent = "Código copiado!";
    avisar("Código Pix copiado. Cole no app do seu banco.");
    window.setTimeout(() => { texto.textContent = "Copiar código Pix"; }, 2500);
  });

  // Ao voltar para a aba (ex.: depois de pagar no app do banco), atualiza na hora.
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && ultimoStatus && !["concluido", "cancelado"].includes(ultimoStatus)) carregar();
  });

  Cliente.pronto.then(carregar);
})();
