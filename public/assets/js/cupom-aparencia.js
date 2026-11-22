/*
 * Gerencia > Impressoras > Aparencia do cupom (carregado com defer).
 * Previa em tempo real: a cada escolha (fonte, tamanho, pesos ou papel da previa) pede ao servidor o mesmo desenho
 * que vai para a impressora (CupomImagem) e troca a imagem. Os pesos mudam conforme a fonte escolhida.
 */
(() => {
  "use strict";

  const raiz = document.querySelector("[data-cupom-aparencia]");
  if (!raiz) return;
  const form = raiz.querySelector("[data-aparencia-form]");
  const imagem = raiz.querySelector("[data-previa-imagem]");
  const carregando = raiz.querySelector("[data-previa-carregando]");
  const legenda = raiz.querySelector("[data-previa-legenda]");
  const aviso = raiz.querySelector("[data-aparencia-aviso]");
  const selTexto = form.querySelector("[data-peso='texto']");
  const selDestaque = form.querySelector("[data-peso='destaque']");
  const botoesPapel = Array.from(raiz.querySelectorAll("[data-previa-papel]"));
  const lerJson = (texto, padrao) => { try { return texto ? JSON.parse(texto) : padrao; } catch (_) { return padrao; } };
  const pesos = lerJson(raiz.dataset.pesos, {});
  const padrao = lerJson(raiz.dataset.padrao, {});
  const fraco = parseInt(raiz.dataset.fraco, 10) || 300;
  let papel = "80";
  let espera = null;
  let pedido = 0;

  const fonte = () => (form.querySelector("input[name='fonte']:checked") || {}).value || padrao.fonte;

  // Troca as opcoes de peso pela lista da fonte, mantendo a escolha mais proxima.
  const preencherPesos = (sel, preferido) => {
    const lista = pesos[fonte()] || [];
    let melhor = lista.length ? lista[0].valor : preferido;
    for (const p of lista) if (Math.abs(p.valor - preferido) < Math.abs(melhor - preferido)) melhor = p.valor;
    sel.replaceChildren(...lista.map((p) => {
      const o = document.createElement("option");
      o.value = String(p.valor);
      o.textContent = p.rotulo;
      o.selected = p.valor === melhor;
      return o;
    }));
  };

  const atualizarAviso = () => {
    const fino = parseInt(selTexto.value, 10) <= fraco || parseInt(selDestaque.value, 10) <= fraco;
    aviso.hidden = !fino;
  };

  const mostrarCarregando = (sim) => {
    carregando.hidden = !sim;
    raiz.classList.toggle("is-carregando", sim);
  };

  const atualizar = async () => {
    const meu = ++pedido;
    mostrarCarregando(true);
    const dados = new FormData(form);
    dados.set("papel", papel);
    try {
      const r = await fetch(raiz.dataset.previa, {
        method: "POST", body: dados, credentials: "same-origin", headers: { Accept: "application/json" },
      });
      const j = await r.json().catch(() => ({}));
      if (meu !== pedido) return; // chegou uma escolha mais nova
      if (!r.ok || !j.ok) throw new Error(j.erro || "Não deu para montar a prévia agora.");
      imagem.src = j.imagem;
      imagem.dataset.papel = String(j.papel || papel);
      imagem.hidden = false;
      legenda.textContent = "Prévia no papel de " + (j.papel || papel) + " mm, com um pedido de exemplo.";
      legenda.classList.remove("is-erro");
    } catch (e) {
      if (meu !== pedido) return;
      legenda.textContent = e.message || "Não deu para montar a prévia agora. Tente de novo.";
      legenda.classList.add("is-erro");
    } finally {
      if (meu === pedido) mostrarCarregando(false);
    }
  };

  const agendar = () => {
    atualizarAviso();
    window.clearTimeout(espera);
    espera = window.setTimeout(atualizar, 180);
  };

  form.addEventListener("change", (ev) => {
    if (ev.target.name === "fonte") {
      preencherPesos(selTexto, parseInt(selTexto.value, 10) || padrao.peso_texto);
      preencherPesos(selDestaque, parseInt(selDestaque.value, 10) || padrao.peso_destaque);
    }
    agendar();
  });

  for (const b of botoesPapel) {
    b.addEventListener("click", () => {
      papel = b.dataset.previaPapel;
      for (const x of botoesPapel) {
        const ativo = x === b;
        x.classList.toggle("is-ativo", ativo);
        x.setAttribute("aria-pressed", ativo ? "true" : "false");
      }
      agendar();
    });
  }

  atualizarAviso();
  atualizar();
})();
