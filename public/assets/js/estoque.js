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

    formProduto.querySelectorAll("[data-controle]").forEach((radio) => {
      radio.addEventListener("change", () => {
        if (areaFicha) areaFicha.hidden = formProduto.querySelector("[data-controle]:checked")?.value === "livre";
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
