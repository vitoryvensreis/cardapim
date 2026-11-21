/* Cardapim - aba Estoque: ficha tecnica dinamica, custo estimado, nova categoria e unidade do insumo.
   Tudo funciona sem JS (o servidor valida); aqui so deixamos o formulario mais rapido de usar. */
(() => {
  "use strict";

  const paraNumero = (texto) => {
    const limpo = String(texto || "").trim().replace(/\./g, "").replace(",", ".");
    const valor = Number.parseFloat(limpo);
    return Number.isFinite(valor) ? valor : 0;
  };
  const reais = (valor) => valor.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });

  const mascaraQuantidade = (input) => {
    let valor = input.value.replace(/\./g, ",").replace(/[^\d,]/g, "");
    const [inteiro, ...resto] = valor.split(",");
    valor = resto.length ? `${inteiro.slice(0, 9)},${resto.join("").slice(0, 3)}` : inteiro.slice(0, 9);
    if (valor !== input.value) input.value = valor;
  };

  /* Leva o foco para o resumo de erros, para quem usa leitor de tela ou teclado. */
  const resumoErros = document.querySelector("[data-erros-form]");
  if (resumoErros) resumoErros.focus();

  /* ---------- Combo (ficha do produto) ---------- */
  const combo = document.querySelector("[data-combo]");
  if (combo) {
    const ativo = combo.querySelector("[data-combo-ativo]");
    const corpo = combo.querySelector("[data-combo-corpo]");
    const soma = combo.querySelector("[data-combo-soma]");
    const semGrupo = combo.querySelector("[data-combo-sem-grupo]");
    const precoCombo = document.querySelector("[data-preco]");
    const avisoRegraCombo = document.querySelector("[data-regra-combo]");
    const atualizarCombo = () => {
      corpo.hidden = !ativo.checked;
      // Combo: a obrigatoriedade de cada grupo e a da secao Combo (a do grupo fica riscada na lista).
      if (avisoRegraCombo) avisoRegraCombo.hidden = !ativo.checked;
      document.querySelectorAll("[data-regra-grupo]").forEach((r) => r.classList.toggle("is-substituida", ativo.checked));
      // So mostra as opcoes dos grupos marcados em Complementos.
      let algum = false;
      combo.querySelectorAll("[data-combo-grupo]").forEach((g) => {
        const marcado = document.getElementById(`grupo-${g.dataset.comboGrupo}`)?.checked === true;
        g.hidden = !marcado;
        algum = algum || marcado;
      });
      if (semGrupo) semGrupo.hidden = algum;
      // Escolha obrigatoria ou opcional em cada grupo: "pelo menos" so aparece quando e obrigatoria.
      combo.querySelectorAll("[data-combo-limite]").forEach((l) => {
        const obrigatoria = l.querySelector("[data-combo-obrigatorio]")?.value === "1";
        const campoMin = l.querySelector("[data-combo-minimo]");
        const min = campoMin?.querySelector("input");
        if (campoMin) campoMin.hidden = !obrigatoria;
        if (min) min.disabled = !obrigatoria;
        const nMin = obrigatoria ? Math.max(1, parseInt(min?.value || "1", 10) || 1) : 0;
        const nMax = parseInt(l.querySelector("input[name$='[maximo]']")?.value || "0", 10) || 0;
        const regra = l.closest("[data-combo-grupo]")?.querySelector("[data-combo-regra]");
        if (!regra) return;
        if (!obrigatoria) regra.textContent = nMax > 1 ? `opcional, até ${nMax}` : "opcional";
        else regra.textContent = nMin === nMax || !nMax ? `obrigatório, escolhe ${nMin}` : `obrigatório, de ${nMin} a ${nMax}`;
      });
      let centavos = 0;
      combo.querySelectorAll("[data-combo-fixo]").forEach((sel) => {
        const opcao = sel.selectedOptions[0];
        const qtd = parseInt(sel.parentElement.querySelector("[data-combo-qtd]")?.value || "1", 10) || 1;
        if (opcao && opcao.value) centavos += (parseInt(opcao.dataset.centavos, 10) || 0) * qtd;
      });
      const preco = Math.round(paraNumero(precoCombo?.value) * 100);
      if (!centavos) { soma.textContent = ""; return; }
      const texto = `Itens inclusos somam ${reais(centavos / 100)}.`;
      soma.textContent = preco > 0 && preco < centavos ? `${texto} Desconto sobre os inclusos: ${reais((centavos - preco) / 100)}.` : texto;
    };
    combo.addEventListener("input", atualizarCombo);
    combo.addEventListener("change", atualizarCombo);
    precoCombo?.addEventListener("input", () => window.setTimeout(atualizarCombo, 0));
    document.querySelectorAll("input[name='grupos[]']").forEach((c) => c.addEventListener("change", atualizarCombo));
    atualizarCombo();
  }

  /* ---------- Produto ---------- */
  const formProduto = document.querySelector("[data-produto-form]");
  if (formProduto) {
    const categoria = formProduto.querySelector("[data-categoria]");
    const novaCategoria = formProduto.querySelector("[data-nova-categoria]");
    const areaFicha = formProduto.querySelector("[data-ficha-area]");
    const ficha = formProduto.querySelector("[data-ficha]");
    const modelo = formProduto.querySelector("[data-ficha-modelo]");
    const resumo = formProduto.querySelector("[data-ficha-resumo]");
    const preco = formProduto.querySelector("[data-preco]");

    /* Como vende: por unidade, por quilo ou por litro (rotulo do preco, porcoes e titulo da ficha mudam juntos). */
    const blocoPeso = formProduto.querySelector("[data-bloco-peso]");
    const rotuloPreco = formProduto.querySelector("label[for='campo-preco']");
    const milesimos = (texto) => {
      const [i = "0", d = ""] = String(texto || "").split(",");
      return (Number.parseInt(i, 10) || 0) * 1000 + (Number.parseInt((d + "000").slice(0, 3), 10) || 0);
    };
    const atualizarPorcoes = () => {
      const centavos = Math.round(paraNumero(preco?.value) * 100);
      formProduto.querySelectorAll("[data-porcao]").forEach((campo) => {
        const alvo = campo.closest("label")?.querySelector("[data-porcao-preco]");
        const m = milesimos(campo.value);
        if (alvo) alvo.textContent = m > 0 && centavos > 0 ? reais(Math.floor((centavos * m + 500) / 1000) / 100) : "";
      });
    };
    const atualizarComoVende = () => {
      const unidade = formProduto.querySelector("[data-unidade-venda]:checked")?.value || "un";
      const peso = unidade !== "un";
      if (blocoPeso) blocoPeso.hidden = !peso;
      if (rotuloPreco) rotuloPreco.textContent = { un: "Preço de venda", kg: "Preço do quilo", l: "Preço do litro" }[unidade];
      formProduto.querySelectorAll("[data-nome-unidade]").forEach((el) => { el.textContent = unidade === "l" ? "litro" : "quilo"; });
      formProduto.querySelectorAll("[data-sigla-unidade]").forEach((el) => { el.textContent = unidade === "l" ? "L" : "kg"; });
      const tituloFicha = formProduto.querySelector("[data-ficha-unidade-venda]");
      if (tituloFicha) tituloFicha.textContent = { un: "1 unidade", kg: "1 kg vendido", l: "1 litro vendido" }[unidade];
      // "Contar as unidades deste produto": a quantidade segue como o produto e vendido.
      formProduto.querySelectorAll("[data-proprio-area] .field-sufixo").forEach((el) => { el.textContent = { un: "un", kg: "kg", l: "L" }[unidade]; });
      atualizarPorcoes();
    };
    formProduto.querySelectorAll("[data-unidade-venda]").forEach((r) => r.addEventListener("change", atualizarComoVende));
    formProduto.querySelectorAll("[data-porcao]").forEach((c) => c.addEventListener("input", () => window.setTimeout(atualizarPorcoes, 0)));
    preco?.addEventListener("input", () => window.setTimeout(atualizarPorcoes, 0));
    atualizarPorcoes();

    categoria?.addEventListener("change", () => {
      const nova = categoria.value === "nova";
      if (!novaCategoria) return;
      novaCategoria.hidden = !nova;
      if (nova) novaCategoria.querySelector("input")?.focus();
    });

    const areaProprio = formProduto.querySelector("[data-proprio-area]");
    formProduto.querySelectorAll("[data-controle]").forEach((radio) => {
      radio.addEventListener("change", () => {
        const modo = formProduto.querySelector("[data-controle]:checked")?.value;
        if (areaFicha) areaFicha.hidden = modo !== "estoque";
        if (areaProprio) {
          areaProprio.hidden = modo !== "proprio";
          if (modo === "proprio") areaProprio.querySelector("input:not([type=hidden])")?.focus();
        }
      });
    });

    const atualizarResumo = () => {
      if (!ficha || !resumo) return;
      let custo = 0;
      let semCusto = 0;
      let itens = 0;
      ficha.querySelectorAll("[data-ficha-linha]").forEach((linha) => {
        const opcao = linha.querySelector("[data-ficha-insumo]")?.selectedOptions[0];
        const qtd = paraNumero(linha.querySelector("[data-ficha-qtd]")?.value);
        if (!opcao || !opcao.value || qtd <= 0) return;
        itens += 1;
        if (opcao.dataset.custo === "") { semCusto += 1; return; }
        custo += Number.parseFloat(opcao.dataset.custo) * qtd;
      });
      if (itens === 0) { resumo.hidden = true; return; }
      const venda = paraNumero(preco?.value);
      const partes = [`Custo dos insumos: <strong>${reais(custo)}</strong>`];
      if (venda > 0) {
        const lucro = venda - custo;
        partes.push(`Sobra por venda: <strong>${reais(lucro)}</strong> (${Math.round((lucro / venda) * 100)}% do preço)`);
      }
      if (semCusto > 0) partes.push(`${semCusto} insumo(s) sem custo informado`);
      resumo.innerHTML = partes.map((p) => `<span>${p}</span>`).join("");
      resumo.hidden = false;
    };

    const atualizarUnidade = (linha) => {
      const opcao = linha.querySelector("[data-ficha-insumo]")?.selectedOptions[0];
      const alvo = linha.querySelector("[data-ficha-unidade]");
      if (alvo) alvo.textContent = opcao?.dataset.unidade || "";
    };

    formProduto.querySelector("[data-ficha-adicionar]")?.addEventListener("click", () => {
      if (!ficha || !modelo) return;
      const linha = modelo.content.firstElementChild.cloneNode(true);
      linha.querySelector("[data-ficha-qtd]")?.removeAttribute("data-mask");
      ficha.append(linha);
      linha.querySelector("select")?.focus();
    });

    ficha?.addEventListener("click", (evento) => {
      const botao = evento.target.closest("[data-ficha-remover]");
      if (!botao) return;
      const linhas = ficha.querySelectorAll("[data-ficha-linha]");
      const linha = botao.closest("[data-ficha-linha]");
      if (linhas.length === 1) {
        /* Sempre deixa uma linha: so limpa. */
        linha.querySelector("select").value = "";
        linha.querySelector("input").value = "";
        atualizarUnidade(linha);
      } else {
        linha.remove();
      }
      atualizarResumo();
    });

    ficha?.addEventListener("change", (evento) => {
      const linha = evento.target.closest("[data-ficha-linha]");
      if (linha) atualizarUnidade(linha);
      atualizarResumo();
    });

    ficha?.addEventListener("input", (evento) => {
      const campo = evento.target.closest("[data-ficha-qtd]");
      if (campo && !campo.hasAttribute("data-mask")) mascaraQuantidade(campo);
      atualizarResumo();
    });

    preco?.addEventListener("input", atualizarResumo);
    atualizarResumo();
  }

  /* ---------- Insumo ---------- */
  const formInsumo = document.querySelector("[data-insumo-form]");
  if (formInsumo) {
    const sufixos = () => formInsumo.querySelectorAll(".field-sufixo");
    formInsumo.querySelectorAll("[data-unidade]").forEach((radio) => {
      radio.addEventListener("change", () => {
        const sigla = radio.dataset.unidade;
        sufixos().forEach((sufixo) => {
          const ehCusto = sufixo.closest("[data-field]")?.querySelector("[data-custo]");
          sufixo.textContent = ehCusto ? `por ${sigla}` : sigla;
        });
      });
    });
  }
  /* ---------- Grupo de complementos: adicionar e remover opcoes ---------- */
  const formGrupo = document.querySelector("[data-grupo-form]");
  if (formGrupo) {
    const lista = formGrupo.querySelector("[data-opcoes]");
    const modelo = formGrupo.querySelector("[data-opcao-modelo]");
    let proximo = Number.parseInt(lista?.dataset.proximo || "0", 10) || 0;
    formGrupo.querySelector("[data-opcao-adicionar]")?.addEventListener("click", () => {
      if (!lista || !modelo) return;
      const html = modelo.innerHTML.replaceAll("__i__", String(proximo));
      proximo += 1;
      const area = document.createElement("div");
      area.innerHTML = html;
      const linha = area.firstElementChild;
      lista.append(linha);
      window.CardapimMascaras?.(linha);
      linha.querySelector("input[name$='[nome]']")?.focus();
    });
    lista?.addEventListener("click", (evento) => {
      const botao = evento.target.closest("[data-opcao-remover]");
      if (!botao) return;
      botao.closest("[data-opcao-linha]")?.remove();
    });
  }
})();
