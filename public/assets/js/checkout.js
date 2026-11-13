/*
 * Cardapim - finalizar pedido (/{slug}/finalizar).
 * Uma tela so, de cima para baixo: como receber -> endereco (CEP preenche o resto e a entrega e conferida
 * ao terminar de digitar) -> pagamento -> resumo com o total sempre visivel. O servidor recalcula tudo.
 * Desconto: cupom digitado ou recompensa do cartao fidelidade (nao somam), conferidos em /desconto.
 */
(() => {
  "use strict";

  const raiz = document.querySelector("[data-checkout]");
  const form = raiz?.querySelector("[data-ck-form]");
  const slug = document.body.dataset.cardapio;
  if (!raiz || !form || !slug) return;

  const Cliente = window.CardapimCliente;
  const config = JSON.parse(raiz.dataset.config || "{}");
  const CHAVE_SACOLA = `cardapim:sacola:v1:${slug}`;
  const CHAVE_ESCOLHAS = "cardapim:checkout:v1";
  const CHAVE_PEDIDO = `cardapim:pedido-chave:${slug}`;
  const dinheiro = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
  const reais = (c) => dinheiro.format(c / 100).replace(/ /g, " ");
  const $ = (sel) => raiz.querySelector(sel);
  const $$ = (sel) => Array.from(raiz.querySelectorAll(sel));
  const guardar = (chave, valor, sessao) => { try { (sessao ? sessionStorage : localStorage).setItem(chave, JSON.stringify(valor)); } catch (e) { /* sem armazenamento */ } };
  const lerJson = (chave, sessao) => { try { return JSON.parse((sessao ? sessionStorage : localStorage).getItem(chave) || "null"); } catch (e) { return null; } };

  /* ---------- Aviso rapido ---------- */
  const toast = raiz.querySelector("[data-toast]");
  let toastTempo = null;
  window.CardapimToast = window.CardapimToast || ((texto) => {
    if (!toast) return;
    toast.textContent = texto;
    toast.hidden = false;
    toast.classList.remove("is-saindo");
    window.clearTimeout(toastTempo);
    toastTempo = window.setTimeout(() => { toast.classList.add("is-saindo"); window.setTimeout(() => { toast.hidden = true; }, 200); }, 2800);
  });

  /* ---------- Sacola (do aparelho) ---------- */
  const lerSacola = () => {
    const d = lerJson(CHAVE_SACOLA);
    if (!d || !Array.isArray(d.itens) || Date.now() - (d.salvo || 0) > 2 * 24 * 3600 * 1000) return [];
    return d.itens.filter((i) => Number.isInteger(i.id) && Number.isInteger(i.qtd) && i.qtd > 0).slice(0, 50);
  };
  let sacola = lerSacola();
  const mostrarVazio = () => {
    form.hidden = true;
    $("[data-ck-vazio]").hidden = false;
    raiz.querySelector(".ck-alerta-fechado")?.setAttribute("hidden", "");
    raiz.querySelector("[data-ck-barra]")?.setAttribute("hidden", "");
  };
  if (sacola.length === 0) {
    mostrarVazio();
    return;
  }
  /* Grava a sacola de volta (mesmo formato do cardapio), ex.: ao remover um item que acabou. */
  const gravarSacola = () => {
    const d = lerJson(CHAVE_SACOLA) || {};
    const restantes = sacola.map(({ indisponivel, ...item }) => item);
    try {
      if (restantes.length === 0) localStorage.removeItem(CHAVE_SACOLA);
      else localStorage.setItem(CHAVE_SACOLA, JSON.stringify({ ...d, itens: restantes, salvo: Date.now() }));
    } catch (e) { /* sem armazenamento: segue so nesta tela */ }
  };

  /* ---------- Estado ---------- */
  const estado = {
    tipo: "",
    forma: "",
    entrega: null, // {entrega, mensagem, taxa_centavos, pedido_minimo_centavos, area}
    conferindo: false,
    temCpf: false,
    gps: null,
    enviando: false,
    desconto: { centavos: 0, origem: null, rotulo: "" },
    cupom: "",
  };
  const escolhas = lerJson(CHAVE_ESCOLHAS) || {};

  /* ---------- Resumo ---------- */
  const elItens = $("[data-ck-itens]");
  const subtotal = () => sacola.reduce((t, i) => t + (i.indisponivel ? 0 : i.qtd * i.centavos), 0);
  const taxa = () => (estado.tipo === "entrega" && estado.entrega?.entrega ? estado.entrega.taxa_centavos : 0);
  const temItens = () => sacola.some((i) => !i.indisponivel);
  const descontoAtual = () => (temItens() ? Math.min(estado.desconto.centavos || 0, subtotal()) : 0);
  const total = () => (temItens() ? Math.max(0, subtotal() + taxa() - descontoAtual()) : 0);

  const desenharItens = () => {
    elItens.replaceChildren();
    sacola.forEach((i) => {
      const li = document.createElement("li");
      if (i.indisponivel) li.className = "is-indisponivel";
      const qtd = document.createElement("span");
      qtd.className = "ck-item-qtd";
      qtd.textContent = `${i.qtd}x`;
      const nome = document.createElement("span");
      nome.className = "ck-item-nome";
      nome.textContent = i.nome;
      if (i.obs) {
        const obs = document.createElement("small");
        obs.textContent = i.obs;
        nome.append(obs);
      }
      const preco = document.createElement("span");
      preco.className = "ck-item-preco";
      preco.textContent = reais(i.qtd * i.centavos);
      li.append(qtd, nome, preco);
      if (i.indisponivel) {
        const alerta = document.createElement("small");
        alerta.className = "ck-item-alerta";
        alerta.textContent = "Acabou agora.";
        const remover = document.createElement("button");
        remover.type = "button";
        remover.className = "ck-item-remover";
        remover.dataset.ckRemover = String(sacola.indexOf(i));
        remover.textContent = "Remover";
        remover.setAttribute("aria-label", `Remover ${i.nome} do pedido`);
        const linha = document.createElement("span");
        linha.className = "ck-item-acabou";
        linha.append(alerta, remover);
        li.append(linha);
      }
      elItens.append(li);
    });
  };

  const desenharTotais = () => {
    $("[data-ck-subtotal]").textContent = reais(subtotal());
    const linhaTaxa = $("[data-ck-linha-taxa]");
    linhaTaxa.hidden = estado.tipo === "retirada";
    const ddTaxa = $("[data-ck-taxa]");
    if (estado.tipo !== "entrega" || !temItens()) ddTaxa.textContent = "—";
    else if (estado.conferindo) ddTaxa.textContent = "Calculando...";
    else if (estado.entrega?.entrega) ddTaxa.textContent = estado.entrega.taxa_centavos === 0 ? "Grátis" : reais(estado.entrega.taxa_centavos);
    else ddTaxa.textContent = "Informe o endereço";
    ddTaxa.classList.toggle("is-gratis", estado.tipo === "entrega" && estado.entrega?.entrega && estado.entrega.taxa_centavos === 0);
    const linhaDesconto = $("[data-ck-linha-desconto]");
    linhaDesconto.hidden = descontoAtual() <= 0;
    $("[data-ck-desconto-rotulo]").textContent = estado.desconto.rotulo || "Desconto";
    $("[data-ck-desconto]").textContent = `− ${reais(descontoAtual())}`;
    const t = reais(total());
    // Sem nenhum item disponivel nao ha o que pedir: o botao avisa em vez de enviar.
    $$("[data-ck-enviar]").forEach((b) => {
      b.disabled = !temItens() || estado.enviando;
      const texto = b.querySelector("[data-ck-enviar-texto]");
      if (texto && !estado.enviando) texto.textContent = temItens() ? "Fazer pedido" : "Sem itens disponíveis";
    });
    $("[data-ck-total]").textContent = t;
    $("[data-ck-barra-total]").textContent = t;
    $$("[data-ck-enviar-total]").forEach((n) => { n.textContent = t; });
    const min = $("[data-ck-minimo]");
    const minimo = estado.tipo === "entrega" && estado.entrega?.entrega ? estado.entrega.pedido_minimo_centavos : null;
    if (minimo && temItens() && subtotal() < minimo) {
      min.hidden = false;
      min.textContent = `Pedido mínimo para este endereço: ${reais(minimo)}. Faltam ${reais(minimo - subtotal())}.`;
    } else {
      min.hidden = true;
    }
  };

  /* ---------- Desconto: cartao fidelidade e cupom ---------- */
  const caixaFid = $("[data-ck-fidelidade]");
  const cupomDetalhes = $("[data-ck-cupom-detalhes]");
  const cupomCampo = $("[data-ck-cupom-campo]");
  const cupomOk = $("[data-ck-cupom-ok]");
  const cupomMsg = $("[data-ck-cupom-msg]");
  const avisoCupom = (texto, info) => {
    cupomMsg.hidden = !texto;
    cupomMsg.textContent = texto || "";
    cupomMsg.classList.toggle("is-info", !!info);
  };
  const desenharFidelidade = (f) => {
    if (!f) { caixaFid.hidden = true; return; }
    caixaFid.hidden = false;
    const ganhou = f.ganhou;
    const usandoCupom = estado.desconto.origem === "cupom";
    caixaFid.classList.toggle("is-ganhou", ganhou && !usandoCupom);
    $("[data-ck-fid-titulo]").textContent = ganhou
      ? (usandoCupom ? "Seu prêmio do cartão fidelidade fica guardado" : `Você ganhou ${f.recompensa} de desconto!`)
      : "Cartão fidelidade";
    $("[data-ck-fid-texto]").textContent = ganhou
      ? (usandoCupom ? "Cupom e fidelidade não se somam. Tire o cupom para usar o prêmio agora, ou use no próximo pedido." : "Prêmio do cartão fidelidade, já descontado neste pedido.")
      : `${f.compras} de ${f.necessarias} ${f.necessarias === 1 ? "pedido" : "pedidos"}. Faltam ${f.faltam} para ganhar ${f.recompensa} de desconto${f.minimo ? ` (contam pedidos a partir de ${f.minimo})` : ""}.`;
    const pontos = $("[data-ck-fid-pontos]");
    pontos.replaceChildren();
    if (!ganhou && f.necessarias <= 20) {
      for (let i = 0; i < f.necessarias; i++) {
        const p = document.createElement("span");
        p.className = `ck-ponto${i < f.compras ? " is-feito" : ""}`;
        pontos.append(p);
      }
    }
  };
  const mostrarCupom = () => {
    const ativo = estado.desconto.origem === "cupom" && estado.cupom !== "";
    cupomOk.hidden = !ativo;
    cupomDetalhes.hidden = ativo;
    if (ativo) $("[data-ck-cupom-ok-texto]").textContent = `${estado.desconto.rotulo}: − ${reais(descontoAtual())}`;
  };
  let pedidoDesconto = 0;
  const atualizarDesconto = async (tentativa) => {
    const codigo = tentativa !== undefined ? tentativa : estado.cupom;
    const meu = ++pedidoDesconto;
    const r = await Cliente.api(`/api/v1/restaurantes/${encodeURIComponent(slug)}/desconto`, {
      itens: sacola.filter((i) => !i.indisponivel).map((i) => ({ id: i.id, quantidade: i.qtd })),
      ...(codigo ? { cupom: codigo } : {}),
    });
    if (meu !== pedidoDesconto) return false;
    if (r.ok) {
      estado.cupom = r.origem === "cupom" ? (r.cupom || codigo) : "";
      estado.desconto = { centavos: r.desconto_centavos || 0, origem: r.origem || null, rotulo: r.rotulo || "" };
      if (tentativa) avisoCupom("");
    } else if (codigo) {
      // Cupom recusado: tira o cupom e confere de novo sem ele (pode valer a fidelidade).
      estado.cupom = "";
      avisoCupom(r.mensagem || "Não deu para usar este cupom.");
      if (r.http !== 429) await atualizarDesconto("");
      else { estado.desconto = { centavos: 0, origem: null, rotulo: "" }; }
      return false;
    }
    desenharFidelidade(r.ok ? r.fidelidade : null);
    mostrarCupom();
    desenharTotais();
    return r.ok;
  };
  $("[data-ck-cupom-aplicar]").addEventListener("click", async () => {
    const codigo = cupomCampo.value.trim().toUpperCase().replace(/[^A-Z0-9-]/g, "");
    if (codigo.length < 3) { avisoCupom("Digite o código do cupom."); cupomCampo.focus(); return; }
    avisoCupom("Conferindo o cupom...", true);
    if (await atualizarDesconto(codigo)) window.CardapimToast("Cupom aplicado.");
  });
  cupomCampo.addEventListener("keydown", (ev) => {
    if (ev.key === "Enter") { ev.preventDefault(); $("[data-ck-cupom-aplicar]").click(); }
  });
  $("[data-ck-cupom-remover]").addEventListener("click", async () => {
    estado.cupom = "";
    cupomCampo.value = "";
    avisoCupom("");
    await atualizarDesconto("");
    cupomDetalhes.open = false;
  });

  /* ---------- Como receber ---------- */
  const blocoEndereco = $("[data-ck-endereco]");
  const retiradaInfo = $("[data-ck-retirada]");
  const grupoEntrega = $("[data-ck-grupo-entrega]");
  const passoPag = $("[data-ck-passo-pag]");
  const escolherTipo = (tipo, silencioso) => {
    estado.tipo = tipo;
    $$("[data-ck-tipo]").forEach((r) => { r.checked = r.value === tipo; });
    blocoEndereco.hidden = tipo !== "entrega";
    retiradaInfo.hidden = tipo !== "retirada";
    if (grupoEntrega) grupoEntrega.textContent = tipo === "retirada" ? "Pagar na retirada" : "Pagar na entrega";
    if (passoPag) passoPag.textContent = tipo === "retirada" ? "2" : "3";
    escolhas.tipo = tipo;
    guardar(CHAVE_ESCOLHAS, escolhas);
    if (tipo === "entrega" && !silencioso) conferirDepois(0);
    desenharTotais();
  };
  $$("[data-ck-tipo]").forEach((r) => r.addEventListener("change", () => escolherTipo(r.value)));

  /* ---------- Endereco ---------- */
  const campos = {};
  $$("[data-ck-end]").forEach((c) => { campos[c.dataset.ckEnd] = c; });
  const caixaCampos = $("[data-ck-campos]");
  const caixaSalvo = $("[data-ck-salvo]");
  const status = $("[data-ck-entrega]");
  const endereco = () => ({
    cep: campos.cep.value.replace(/\D/g, ""),
    logradouro: campos.logradouro.value.trim(),
    numero: campos.numero.value.trim(),
    complemento: campos.complemento.value.trim(),
    bairro: campos.bairro.value.trim(),
    cidade: campos.cidade.value.trim(),
    estado: campos.estado.value,
    referencia: campos.referencia.value.trim(),
    ...(estado.gps ? { latitude: estado.gps.lat, longitude: estado.gps.lon } : {}),
  });
  const completo = (e) => e.logradouro.length >= 3 && e.numero !== "" && e.bairro.length >= 2 && e.cidade.length >= 2 && e.estado !== "";
  const faltando = (e) => {
    if (e.logradouro.length < 3) return ["logradouro", "Digite a rua."];
    if (e.numero === "") return ["numero", "Digite o número (ou S/N)."];
    if (e.bairro.length < 2) return ["bairro", "Digite o bairro."];
    if (e.cidade.length < 2) return ["cidade", "Digite a cidade."];
    if (e.estado === "") return ["estado", "Escolha o estado."];
    return null;
  };

  const mostrarStatus = (tipo, titulo, texto, acao) => {
    status.hidden = false;
    status.className = `ck-entrega is-${tipo}`;
    status.replaceChildren();
    const t = document.createElement("strong");
    t.textContent = titulo;
    status.append(t);
    if (texto) {
      const p = document.createElement("p");
      p.textContent = texto;
      status.append(p);
    }
    if (acao) status.append(acao);
  };

  let tempoConferir = null;
  let conferenciaAtual = 0;
  const conferirDepois = (ms = 600) => {
    window.clearTimeout(tempoConferir);
    tempoConferir = window.setTimeout(conferir, ms);
  };
  const conferir = async () => {
    if (estado.tipo !== "entrega") return;
    const e = endereco();
    estado.entrega = null;
    if (!completo(e)) {
      status.hidden = true;
      estado.conferindo = false;
      desenharTotais();
      return;
    }
    const minha = ++conferenciaAtual;
    estado.conferindo = true;
    desenharTotais();
    mostrarStatus("carregando", "Conferindo se entregamos aqui...", "");
    const r = await Cliente.api(`/api/v1/restaurantes/${encodeURIComponent(slug)}/entrega`, { endereco: e });
    if (minha !== conferenciaAtual) return;
    estado.conferindo = false;
    if (r.ok && r.entrega) {
      estado.entrega = r;
      const detalhe = [r.taxa_centavos === 0 ? "Entrega grátis" : `Taxa de entrega ${reais(r.taxa_centavos)}`, r.area].filter(Boolean).join(" · ");
      mostrarStatus("ok", "Entregamos no seu endereço", detalhe);
    } else if (r.ok) {
      estado.entrega = r;
      let acao = null;
      if ((config.modos || []).includes("retirada")) {
        acao = document.createElement("button");
        acao.type = "button";
        acao.className = "btn btn-outline btn-sm";
        acao.textContent = "Prefiro retirar no local";
        acao.addEventListener("click", () => escolherTipo("retirada"));
      }
      mostrarStatus("erro", "Fora da área de entrega", r.mensagem, acao);
    } else {
      mostrarStatus("erro", "Não conseguimos conferir agora", r.mensagem || "Tente de novo em instantes.");
    }
    desenharTotais();
  };

  const mostrarCampos = () => {
    caixaCampos.hidden = false;
    caixaSalvo.hidden = true;
  };
  const mostrarSalvo = (e) => {
    $("[data-ck-salvo-linha1]").textContent = [e.logradouro, e.numero].filter(Boolean).join(", ") + (e.complemento ? ` - ${e.complemento}` : "");
    $("[data-ck-salvo-linha2]").textContent = [e.bairro, [e.cidade, e.estado].filter(Boolean).join("/")].filter(Boolean).join(" · ");
    caixaCampos.hidden = true;
    caixaSalvo.hidden = false;
  };
  $("[data-ck-trocar]").addEventListener("click", () => {
    mostrarCampos();
    campos.cep.focus();
  });
  const preencher = (e) => {
    Object.entries(e).forEach(([k, v]) => { if (campos[k] && typeof v === "string") campos[k].value = k === "cep" && v.length === 8 ? `${v.slice(0, 5)}-${v.slice(5)}` : v; });
  };

  // CEP: mascara e busca automatica com 8 numeros.
  const cepMsg = $("[data-ck-cep-msg]");
  const cepCarregando = $("[data-ck-cep-carregando]");
  let ultimoCep = "";
  campos.cep.addEventListener("input", async () => {
    const d = campos.cep.value.replace(/\D/g, "").slice(0, 8);
    campos.cep.value = d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
    if (d.length !== 8 || d === ultimoCep) return;
    ultimoCep = d;
    cepCarregando.hidden = false;
    cepMsg.textContent = "Buscando o endereço...";
    cepMsg.classList.remove("is-erro");
    const r = await Cliente.api(`/api/v1/cep/${d}`);
    cepCarregando.hidden = true;
    if (r.cidade) {
      ["logradouro", "bairro", "cidade", "estado"].forEach((k) => { if (r[k]) campos[k].value = r[k]; });
      cepMsg.textContent = r.logradouro ? "Endereço encontrado. Agora digite o número." : "Cidade encontrada. Complete a rua e o bairro.";
      (r.logradouro ? campos.numero : campos.logradouro).focus();
      conferirDepois();
    } else {
      cepMsg.textContent = r.mensagem || "CEP não encontrado. Digite o endereço abaixo.";
      cepMsg.classList.add("is-erro");
    }
  });
  ["logradouro", "numero", "bairro", "cidade", "estado"].forEach((k) => {
    campos[k].addEventListener(k === "estado" ? "change" : "input", () => conferirDepois());
  });

  // "Estou neste endereco": localizacao do celular ajuda a calcular a distancia.
  const botaoGps = $("[data-ck-gps]");
  const textoGps = $("[data-ck-gps-texto]");
  if (!("geolocation" in navigator)) botaoGps.hidden = true;
  botaoGps.addEventListener("click", () => {
    textoGps.textContent = "Pegando sua localização...";
    botaoGps.disabled = true;
    navigator.geolocation.getCurrentPosition((pos) => {
      estado.gps = { lat: Number(pos.coords.latitude.toFixed(7)), lon: Number(pos.coords.longitude.toFixed(7)) };
      textoGps.textContent = "Localização do celular adicionada";
      botaoGps.classList.add("is-ok");
      botaoGps.disabled = false;
      conferirDepois(0);
    }, (falha) => {
      textoGps.textContent = falha && falha.code === 1 ? "Localização não permitida. Tudo bem, use o endereço." : "Não deu para pegar a localização. Tente de novo.";
      botaoGps.disabled = false;
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
  });

  /* ---------- Pagamento ---------- */
  const blocoTroco = $("[data-ck-troco]");
  const campoTrocoBox = $("[data-ck-troco-campo]");
  const precisaTroco = $("[data-ck-precisa-troco]");
  const campoTroco = $("#ck-troco");
  const blocoCpf = $("[data-ck-cpf]");
  const campoCpf = $("[data-ck-cpf-campo]");
  const escolherForma = (forma) => {
    estado.forma = forma;
    $$("[data-ck-forma]").forEach((r) => { r.checked = r.value === forma; });
    blocoTroco.hidden = forma !== "dinheiro";
    blocoCpf.hidden = !(forma === "pix_online" && !estado.temCpf);
    escolhas.forma = forma;
    guardar(CHAVE_ESCOLHAS, escolhas);
  };
  $$("[data-ck-forma]").forEach((r) => r.addEventListener("change", () => escolherForma(r.value)));
  precisaTroco.addEventListener("change", () => {
    campoTrocoBox.hidden = !precisaTroco.checked;
    if (precisaTroco.checked) campoTroco.focus();
  });
  campoTroco.addEventListener("input", () => {
    const d = campoTroco.value.replace(/\D/g, "").replace(/^0+/, "").slice(0, 7);
    campoTroco.value = d === "" ? "" : reais(Number(d));
  });
  campoCpf.addEventListener("input", () => {
    const d = campoCpf.value.replace(/\D/g, "").slice(0, 11);
    campoCpf.value = d.replace(/(\d{3})(\d)/, "$1.$2").replace(/(\d{3})(\d)/, "$1.$2").replace(/(\d{3})(\d{1,2})$/, "$1-$2");
  });
  const cpfValido = (c) => {
    c = c.replace(/\D/g, "");
    if (c.length !== 11 || /^(\d)\1{10}$/.test(c)) return false;
    for (let t = 9; t < 11; t++) {
      let s = 0;
      for (let i = 0; i < t; i++) s += Number(c[i]) * (t + 1 - i);
      if (((10 * s) % 11) % 10 !== Number(c[t])) return false;
    }
    return true;
  };

  /* ---------- Envio ---------- */
  const erroBox = $("[data-ck-erro]");
  const botoes = $$("[data-ck-enviar]");
  const mostrarErro = (texto, alvo) => {
    erroBox.hidden = !texto;
    erroBox.textContent = texto || "";
    // O aviso vermelho fica logo abaixo do resumo; o toast so ajuda quando o problema esta num campo longe dele.
    if (texto && alvo !== elItens) window.CardapimToast(texto);
    if (alvo) {
      alvo.scrollIntoView({ behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "center" });
      if (typeof alvo.focus === "function") window.setTimeout(() => alvo.focus({ preventScroll: true }), 250);
    }
  };
  const ocupado = (sim) => {
    estado.enviando = sim;
    botoes.forEach((b) => {
      b.disabled = sim;
      b.classList.toggle("is-carregando", sim);
      const t = b.querySelector("[data-ck-enviar-texto]");
      if (t) t.textContent = sim ? "Enviando pedido..." : "Fazer pedido";
    });
  };
  const chaveIdempotencia = () => {
    let k = lerJson(CHAVE_PEDIDO, true);
    if (typeof k !== "string" || !/^[a-f0-9]{32}$/.test(k)) {
      const bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      k = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
      guardar(CHAVE_PEDIDO, k, true);
    }
    return k;
  };

  const validar = () => {
    if (!config.aberto) return ["O restaurante está fechado agora.", null];
    if (sacola.some((i) => i.indisponivel)) return ["Alguns itens acabaram. Toque em \"Remover\" no resumo para continuar.", elItens];
    if (!estado.tipo) return ["Escolha se quer entrega ou retirar no local.", $("[data-ck-tipo]")];
    if (estado.tipo === "entrega") {
      if (!caixaCampos.hidden || !completo(endereco())) {
        const f = faltando(endereco());
        if (f) { mostrarCampos(); return [f[1], campos[f[0]]]; }
      }
      if (estado.conferindo) return ["Aguarde: estamos conferindo a entrega no seu endereço.", status];
      if (!estado.entrega) return ["Aguarde a conferência do endereço.", status];
      if (!estado.entrega.entrega) return [estado.entrega.mensagem, status];
      const minimo = estado.entrega.pedido_minimo_centavos;
      if (minimo && subtotal() < minimo) return [`O pedido mínimo para este endereço é ${reais(minimo)}.`, $("[data-ck-minimo]")];
    }
    if (!estado.forma) return ["Escolha a forma de pagamento.", $("[data-ck-forma]")];
    if (estado.forma === "dinheiro" && precisaTroco.checked) {
      const troco = Number(campoTroco.value.replace(/\D/g, ""));
      if (!troco || troco < total()) return [`O troco precisa ser para ${reais(total())} ou mais.`, campoTroco];
    }
    if (estado.forma === "pix_online" && !estado.temCpf && !cpfValido(campoCpf.value)) return ["Digite um CPF válido para gerar o Pix.", campoCpf];
    return null;
  };

  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    if (estado.enviando) return;
    mostrarErro("");
    const problema = validar();
    if (problema) {
      mostrarErro(problema[0], problema[1]);
      return;
    }
    const cliente = await Cliente.entrar({ motivo: "para fazer o pedido" });
    if (!cliente) return;
    ocupado(true);
    const corpo = {
      chave: chaveIdempotencia(),
      tipo: estado.tipo,
      forma_pagamento: estado.forma,
      itens: sacola.map((i) => ({ id: i.id, quantidade: i.qtd, observacao: i.obs || "" })),
      observacao: $("#ck-obs").value.trim(),
      ...(estado.tipo === "entrega" ? { endereco: endereco() } : {}),
      ...(estado.forma === "dinheiro" && precisaTroco.checked ? { troco_para: campoTroco.value } : {}),
      ...(estado.forma === "pix_online" && !estado.temCpf ? { cpf: campoCpf.value } : {}),
      ...(estado.cupom ? { cupom: estado.cupom } : {}),
    };
    const r = await Cliente.api(`/api/v1/restaurantes/${encodeURIComponent(slug)}/pedidos`, corpo);
    if (r.ok && r.pedido) {
      try {
        localStorage.removeItem(CHAVE_SACOLA);
        sessionStorage.removeItem(CHAVE_PEDIDO);
      } catch (e) { /* sem armazenamento */ }
      window.location.assign(`/${encodeURIComponent(slug)}/pedido/${r.pedido.id}`);
      return;
    }
    ocupado(false);
    if (r.http === 401 && r.login) {
      mostrarErro("Entre na sua conta para fazer o pedido.");
      return;
    }
    if (Array.isArray(r.indisponiveis) && r.indisponiveis.length > 0) {
      sacola.forEach((i) => { if (r.indisponiveis.includes(i.id)) i.indisponivel = true; });
      desenharItens();
      desenharTotais();
      mostrarErro("Alguns itens acabaram agora há pouco. Remova no resumo para continuar.", elItens);
      return;
    }
    if (r.http !== 409 && r.http !== 422 && r.http !== 502) {
      try { sessionStorage.removeItem(CHAVE_PEDIDO); } catch (e) { /* ok */ }
    }
    if (r.campo === "cupom") {
      estado.cupom = "";
      await atualizarDesconto("");
      avisoCupom(r.mensagem || "Não deu para usar este cupom.");
      mostrarErro(r.mensagem || "Confira o desconto e tente de novo.", cupomMsg);
      return;
    }
    const alvos = { endereco: status, tipo: $("[data-ck-tipo]"), forma_pagamento: $("[data-ck-forma]"), troco_para: campoTroco, cpf: campoCpf };
    if (r.campo === "endereco" && r.erros) {
      mostrarCampos();
      const k = Object.keys(r.erros)[0];
      mostrarErro(r.erros[k], campos[k] || status);
      return;
    }
    mostrarErro(r.mensagem || "Não foi possível enviar o pedido. Tente de novo.", alvos[r.campo] || null);
  });

  /* Remover item que acabou, sem sair da tela. */
  elItens.addEventListener("click", (ev) => {
    const b = ev.target.closest("[data-ck-remover]");
    if (!b) return;
    sacola.splice(Number(b.dataset.ckRemover), 1);
    gravarSacola();
    const aviso = raiz.querySelector("[data-toast]") || document.querySelector("[data-toast]");
    if (aviso) aviso.hidden = true;
    if (sacola.length === 0) {
      mostrarErro("");
      mostrarVazio();
      window.scrollTo({ top: 0 });
      return;
    }
    desenharItens();
    desenharTotais();
    atualizarDesconto();
    if (!sacola.some((i) => i.indisponivel)) mostrarErro("");
    window.CardapimToast("Item removido do pedido.");
  });

  /* ---------- Inicio ---------- */
  desenharItens();
  desenharTotais();
  const modos = config.modos || [];
  if (modos.length === 1) escolherTipo(modos[0], true);
  else if (modos.includes(escolhas.tipo)) escolherTipo(escolhas.tipo, true);
  const formas = config.formas || [];
  if (formas.length === 1) escolherForma(formas[0]);
  else if (formas.includes(escolhas.forma)) escolherForma(escolhas.forma);

  // Precos e disponibilidade de agora.
  Cliente.api(`/api/v1/restaurantes/${encodeURIComponent(slug)}/sacola`, { itens: sacola.map((i) => ({ id: i.id, quantidade: i.qtd })) }).then((r) => {
    if (!Array.isArray(r.itens)) return;
    const porId = new Map(r.itens.map((i) => [i.id, i]));
    let mudou = false;
    sacola = sacola.filter((i) => {
      const s = porId.get(i.id);
      if (!s) { mudou = true; return false; }
      if (s.centavos !== i.centavos) mudou = true;
      i.centavos = s.centavos;
      i.nome = s.nome;
      i.indisponivel = !s.disponivel;
      return true;
    });
    if (mudou) window.CardapimToast("Atualizamos sua sacola com os preços de agora.");
    if (sacola.length === 0) { window.location.assign(`/${encodeURIComponent(slug)}`); return; }
    desenharItens();
    desenharTotais();
    atualizarDesconto();
  });

  // Endereco salvo e CPF (depois de entrar).
  let contaDe = "";
  const carregarConta = async () => {
    const atual = Cliente.atual();
    if (!atual || contaDe === atual.whatsapp) return;
    contaDe = atual.whatsapp;
    const r = await Cliente.api("/api/v1/cliente/endereco");
    atualizarDesconto();
    estado.temCpf = r.tem_cpf === true;
    if (estado.forma) escolherForma(estado.forma);
    if (r.endereco && endereco().logradouro === "") {
      preencher(r.endereco);
      mostrarSalvo(r.endereco);
      if (estado.tipo === "entrega") conferirDepois(0);
    }
  };
  Cliente.pronto.then(() => {
    carregarConta();
    if (!Cliente.atual()) Cliente.entrar({ motivo: "para finalizar o pedido" }).then((c) => { if (c) carregarConta(); });
  });
  Cliente.ouvir((c) => { if (c) carregarConta(); });
})();
