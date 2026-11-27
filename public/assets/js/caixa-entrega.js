/*
 * Venda no balcao -> pedido de entrega (aba "Entrega" da Conta e popup "Preparar pedido"). Carregado com defer.
 * - Caixinha "Pedido para entrega": mostra os campos e troca "Receber e fechar conta" por "Preparar pedido".
 * - Telefone + Buscar: nome e ultimo endereco do cliente neste restaurante.
 * - Rua e numero: ao parar de digitar, busca o bairro (e o CEP); bairro trocado a mao recalcula a taxa.
 * - O que foi digitado fica guardado nesta aba do navegador: lancar itens redesenha a Conta e os campos voltam.
 * O servidor recalcula a taxa e o total no "Preparar pedido"; os valores daqui sao so para mostrar.
 */
(() => {
  "use strict";

  const $ = (sel, raiz = document) => raiz.querySelector(sel);
  const $$ = (sel, raiz = document) => Array.from(raiz.querySelectorAll(sel));
  const CAMPOS = ["telefone", "nome", "logradouro", "numero", "bairro", "complemento", "cep"];
  const dialogo = $("[data-dialogo-preparar]");
  if (!dialogo) return;

  const raizInicial = $("[data-entrega]");
  const idVenda = raizInicial ? raizInicial.dataset.entregaId : "";
  const chave = `cardapim-entrega-${idVenda}`;
  const csrf = () => $('input[name="_csrf"]', dialogo)?.value || "";

  /* ---------- Estado guardado (sobrevive a troca da Conta e a recarga com erro) ---------- */
  const ler = () => {
    try { return JSON.parse(window.sessionStorage.getItem(chave) || "null") || {}; } catch (e) { return {}; }
  };
  let estado = Object.assign({ ativo: false, campos: {}, calculo: null, aberta: false }, ler());
  const guardar = () => {
    try { window.sessionStorage.setItem(chave, JSON.stringify(estado)); } catch (e) { /* navegador sem armazenamento: so na memoria */ }
  };

  const raiz = () => $("[data-entrega]");
  const campo = (nome) => $(`[data-entrega-campo="${nome}"]`, raiz() || document);
  const valor = (nome) => (campo(nome)?.value || "").trim();

  const status = (texto, tipo = "info") => {
    const el = $("[data-entrega-status]", raiz() || document);
    if (!el) return;
    el.textContent = texto;
    el.hidden = texto === "";
    el.dataset.tipo = tipo;
  };

  /* ---------- Totais na Conta, resumo da aba e botao principal ---------- */
  const pronto = () => !!(estado.ativo && estado.calculo && estado.calculo.entrega && valor("nome").length >= 2);

  const desenhar = () => {
    const r = raiz();
    const ativo = estado.ativo;
    const calc = estado.calculo;
    if (r) {
      const caixa = $("[data-entrega-ativo]", r);
      if (caixa) caixa.checked = ativo;
      const camposEl = $("[data-entrega-campos]", r);
      if (camposEl) camposEl.hidden = !ativo;
      const resumo = $("[data-entrega-resumo]", r);
      if (resumo) {
        const temCalculo = ativo && calc && calc.entrega;
        resumo.hidden = !temCalculo;
        if (temCalculo) {
          $("[data-entrega-subtotal]", resumo).textContent = calc.subtotal;
          $("[data-entrega-taxa]", resumo).textContent = calc.taxa;
          $("[data-entrega-total]", resumo).textContent = calc.total;
          const linhaDesc = $("[data-entrega-linha-desconto]", resumo);
          linhaDesc.hidden = !calc.desconto_centavos;
          $("[data-entrega-desconto]", resumo).textContent = `− ${calc.desconto}`;
        }
      }
    }
    // Conta e barra do celular: taxa e total com a entrega.
    const comTaxa = ativo && calc && calc.entrega;
    const linhaTaxa = $("[data-conta-taxa-entrega]");
    if (linhaTaxa) {
      linhaTaxa.hidden = !comTaxa;
      if (comTaxa) $("[data-conta-taxa-valor]", linhaTaxa).textContent = calc.taxa;
    }
    $$("[data-conta-total], [data-barra-total]").forEach((el) => {
      el.textContent = comTaxa ? calc.total : el.dataset.totalNormal;
    });
    $$("[data-conta-receber]").forEach((a) => {
      const texto = $("[data-receber-texto]", a);
      if (texto) texto.textContent = ativo ? "Preparar pedido" : texto.dataset.textoNormal;
      a.classList.toggle("is-entrega", ativo);
      if (ativo) a.setAttribute("aria-haspopup", "dialog"); else a.removeAttribute("aria-haspopup");
    });
  };

  /* ---------- Ligar a aba (de novo a cada troca da Conta) ---------- */
  const ligar = () => {
    const r = raiz();
    if (!r) { desenhar(); return; }
    CAMPOS.forEach((nome) => {
      const el = campo(nome);
      if (el && typeof estado.campos[nome] === "string" && el.value === "") el.value = estado.campos[nome];
    });
    if (estado.aberta) r.open = true;
    desenhar();
    // Itens mudaram (ou a tela recarregou): o total com a entrega e calculado de novo.
    if (estado.ativo && estado.calculo) { window.clearTimeout(esperaTroca); esperaTroca = window.setTimeout(calcular, 300); }
  };
  let esperaTroca = 0;

  const post = async (url, dados) => {
    const corpo = new FormData();
    corpo.append("_csrf", csrf());
    Object.entries(dados).forEach(([k, v]) => corpo.append(k, v));
    const r = await fetch(url, { method: "POST", body: corpo, credentials: "same-origin", headers: { Accept: "application/json" } });
    let d = {};
    try { d = await r.json(); } catch (e) { d = { ok: false, mensagem: "Não foi possível falar com o servidor. Tente de novo." }; }
    return d;
  };

  const lerCampos = () => {
    CAMPOS.forEach((nome) => { estado.campos[nome] = valor(nome); });
    guardar();
  };

  /* ---------- Calcular a taxa ---------- */
  let calcSeq = 0;
  const calcular = async () => {
    const r = raiz();
    if (!r || !estado.ativo) return;
    lerCampos();
    const c = estado.campos;
    if (c.logradouro.length < 3 || c.numero === "" || c.bairro.length < 2) {
      estado.calculo = null;
      guardar();
      desenhar();
      return;
    }
    const seq = ++calcSeq;
    status("Calculando a taxa de entrega...", "carregando");
    const d = await post(r.dataset.urlCalcular, { cep: c.cep, logradouro: c.logradouro, numero: c.numero, bairro: c.bairro, complemento: c.complemento });
    if (seq !== calcSeq) return;
    estado.calculo = d.ok ? d : null;
    guardar();
    if (!d.ok) status(d.mensagem || "Confira o endereço.", "erro");
    else if (!d.entrega) status(`${d.mensagem} Este endereço está fora da área de entrega.`, "erro");
    else status(d.aviso ? `${d.mensagem} ${d.aviso}` : d.mensagem, d.aviso ? "aviso" : "ok");
    desenhar();
  };

  /* ---------- Buscar o bairro pela rua ---------- */
  let ultimaRua = "";
  const escolherBairro = (b) => {
    const bairro = campo("bairro");
    const cep = campo("cep");
    if (bairro) { bairro.value = b.bairro; bairro.dataset.automatico = "1"; }
    if (cep) cep.value = b.cep || "";
    const caixa = $("[data-entrega-bairros]", raiz() || document);
    if (caixa) caixa.hidden = true;
    calcular();
  };
  const buscarBairro = async () => {
    const r = raiz();
    if (!r || !estado.ativo) return;
    const rua = valor("logradouro");
    const bairroEl = campo("bairro");
    if (rua.length < 3 || valor("numero") === "") return;
    // Bairro digitado a mao continua valendo: so calcula de novo.
    if (bairroEl && bairroEl.value.trim() !== "" && bairroEl.dataset.automatico !== "1") { calcular(); return; }
    if (rua === ultimaRua && bairroEl && bairroEl.value.trim() !== "") { calcular(); return; }
    ultimaRua = rua;
    status("Buscando o bairro...", "carregando");
    const d = await post(r.dataset.urlBairro, { logradouro: rua });
    const lista = Array.isArray(d.bairros) ? d.bairros : [];
    const caixa = $("[data-entrega-bairros]", r);
    const opcoes = $("[data-entrega-bairros-opcoes]", r);
    if (lista.length === 1) { escolherBairro(lista[0]); return; }
    if (lista.length > 1 && caixa && opcoes) {
      opcoes.replaceChildren(...lista.map((b) => {
        const bt = document.createElement("button");
        bt.type = "button";
        bt.className = "chip";
        bt.textContent = b.bairro;
        bt.addEventListener("click", () => escolherBairro(b));
        return bt;
      }));
      caixa.hidden = false;
      status(d.mensagem || "Escolha o bairro.", "aviso");
      return;
    }
    if (caixa) caixa.hidden = true;
    status(d.mensagem || "Digite o bairro.", "aviso");
    if (bairroEl && bairroEl.value.trim() === "") bairroEl.focus();
    else calcular();
  };

  /* ---------- Buscar pelo telefone ---------- */
  const buscarTelefone = async () => {
    const r = raiz();
    if (!r) return;
    const botao = $("[data-entrega-buscar]", r);
    if (botao) botao.disabled = true;
    status("Buscando o cliente...", "carregando");
    try {
      const d = await post(r.dataset.urlTelefone, { telefone: valor("telefone") });
      if (!d.ok) { status(d.mensagem || "Confira o telefone.", "erro"); return; }
      if (d.nome && campo("nome")) campo("nome").value = d.nome;
      if (d.endereco) {
        ["logradouro", "numero", "bairro", "complemento", "cep"].forEach((n) => {
          if (campo(n)) campo(n).value = d.endereco[n] || "";
        });
        if (campo("bairro")) campo("bairro").dataset.automatico = "";
        ultimaRua = valor("logradouro");
        status(d.mensagem, "ok");
        await calcular();
        if (estado.calculo && estado.calculo.entrega) status(`${d.mensagem} ${estado.calculo.mensagem}`, estado.calculo.aviso ? "aviso" : "ok");
      } else {
        status(d.mensagem, "info");
        lerCampos();
        (d.nome ? campo("logradouro") : campo("nome"))?.focus();
      }
    } finally {
      if (botao) botao.disabled = false;
    }
  };

  /* ---------- Eventos (delegados: a Conta e redesenhada a cada item lancado) ---------- */
  let esperaRua = 0;
  let esperaBairro = 0;
  document.addEventListener("change", (ev) => {
    const caixa = ev.target.closest("[data-entrega-ativo]");
    if (!caixa) return;
    estado.ativo = caixa.checked;
    guardar();
    desenhar();
    if (estado.ativo) {
      window.setTimeout(() => campo("telefone")?.focus(), 50);
      if (!estado.calculo) calcular();
    }
  });
  document.addEventListener("input", (ev) => {
    const el = ev.target.closest("[data-entrega-campo]");
    if (!el) return;
    const nome = el.dataset.entregaCampo;
    if (nome === "bairro") el.dataset.automatico = "";
    lerCampos();
    if (nome === "logradouro" || nome === "numero") {
      if (nome === "logradouro") { const cep = campo("cep"); if (cep) cep.value = ""; }
      window.clearTimeout(esperaRua);
      esperaRua = window.setTimeout(buscarBairro, 900);
    } else if (nome === "bairro") {
      window.clearTimeout(esperaBairro);
      esperaBairro = window.setTimeout(calcular, 800);
    } else if (nome === "nome") {
      desenhar();
    }
  });
  document.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter" && ev.target.closest('[data-entrega-campo="telefone"]')) { ev.preventDefault(); buscarTelefone(); }
  });
  document.addEventListener("click", (ev) => {
    if (ev.target.closest("[data-entrega-buscar]")) { buscarTelefone(); return; }
    const receber = ev.target.closest("[data-conta-receber]");
    if (receber && estado.ativo) {
      ev.preventDefault();
      abrirPreparar();
    }
  });
  document.addEventListener("toggle", (ev) => {
    if (ev.target.matches && ev.target.matches("[data-entrega]")) { estado.aberta = ev.target.open; guardar(); }
  }, true);
  document.addEventListener("cardapim:regioes-trocadas", ligar);

  /* ---------- Popup "Preparar pedido" ---------- */
  const reais = (c) => (c / 100).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const centavos = (texto) => {
    const limpo = String(texto || "").replace(/[^\d,]/g, "").replace(",", ".");
    const n = Number.parseFloat(limpo);
    return Number.isFinite(n) ? Math.round(n * 100) : null;
  };
  const erroPreparar = (texto) => {
    const el = $("[data-preparar-erro]", dialogo);
    el.textContent = texto;
    el.hidden = texto === "";
  };
  const desenharPopup = () => {
    const quando = $('input[name="quando"]:checked', dialogo)?.value || "";
    const forma = $('input[name="forma"]:checked', dialogo)?.value || "";
    const total = estado.calculo ? estado.calculo.total_centavos : 0;
    const blocoDinheiro = $("[data-preparar-dinheiro]", dialogo);
    blocoDinheiro.hidden = forma !== "dinheiro" || quando === "";
    $("[data-preparar-valor-rotulo]", dialogo).textContent = quando === "agora" ? "Cliente entregou" : "Troco para quanto?";
    $("[data-preparar-valor-ajuda]", dialogo).textContent = quando === "agora"
      ? "Deixe vazio se o cliente deu o valor certo."
      : "Deixe vazio se o cliente não precisa de troco.";
    const recebido = centavos($("[data-preparar-valor]", dialogo).value);
    const troco = $("[data-preparar-troco]", dialogo);
    if (!blocoDinheiro.hidden && recebido !== null && recebido > 0) {
      troco.hidden = false;
      const falta = recebido < total;
      troco.classList.toggle("is-falta", falta);
      $("[data-preparar-troco-rotulo]", dialogo).textContent = falta ? "Ainda falta" : (quando === "agora" ? "Devolva de troco" : "Levar de troco");
      $("[data-preparar-troco-valor]", dialogo).textContent = reais(Math.abs(recebido - total));
    } else {
      troco.hidden = true;
    }
    $("[data-preparar-destino]", dialogo).textContent = quando === "agora"
      ? "O valor entra no caixa agora e o entregador não cobra nada."
      : (quando === "entrega" ? "O entregador recebe na entrega; o valor entra no caixa no \"Entregue e recebido\"." : "");
  };
  const abrirPreparar = () => {
    lerCampos();
    const r = raiz();
    if (r) r.open = true;
    if (!pronto()) {
      if (valor("nome").length < 2) status("Digite o nome do cliente.", "erro");
      else if (!estado.calculo) status("Preencha rua, número e bairro para calcular a taxa.", "erro");
      else if (!estado.calculo.entrega) status("Este endereço está fora da área de entrega. O pedido não pode ser enviado.", "erro");
      (valor("nome").length < 2 ? campo("nome") : campo("logradouro"))?.focus();
      return;
    }
    CAMPOS.forEach((nome) => {
      const oculto = $(`[data-preparar-campo="${nome}"]`, dialogo);
      if (oculto) oculto.value = estado.campos[nome] || "";
    });
    $("[data-preparar-total]", dialogo).textContent = estado.calculo.total;
    erroPreparar("");
    desenharPopup();
    if (typeof dialogo.showModal === "function") dialogo.showModal(); else dialogo.setAttribute("open", "");
  };
  dialogo.addEventListener("change", desenharPopup);
  dialogo.addEventListener("input", desenharPopup);
  $("[data-form-preparar]", dialogo).addEventListener("submit", (ev) => {
    const quando = $('input[name="quando"]:checked', dialogo);
    const forma = $('input[name="forma"]:checked', dialogo);
    if (!quando) { ev.preventDefault(); erroPreparar("Escolha quando o cliente paga: agora ou na entrega."); return; }
    if (!forma) { ev.preventDefault(); erroPreparar("Escolha a forma de pagamento."); return; }
    if (forma.value === "dinheiro") {
      const recebido = centavos($("[data-preparar-valor]", dialogo).value);
      if (recebido !== null && recebido > 0 && estado.calculo && recebido < estado.calculo.total_centavos) {
        ev.preventDefault();
        erroPreparar(`O valor precisa ser pelo menos ${estado.calculo.total}.`);
        return;
      }
    }
    const botao = $("[data-preparar-enviar]", dialogo);
    window.setTimeout(() => { botao.disabled = true; }, 0); // evita clique duplo (o envio ja comecou)
  });

  ligar();
})();
