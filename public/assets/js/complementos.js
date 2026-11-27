/*
 * Cardapim - regra dos complementos no navegador (adicionais, bordas...), igual a do servidor
 * (app/Modules/Catalog/Complementos.php). Serve so para mostrar preco e avisos na hora: o servidor confere tudo de novo.
 * Grupos: [{id, nome, minimo, maximo, meio?, opcoes: [{id, nome, centavos, sabor_centavos?, disponivel}]}]. Escolhas: [{id, qtd}].
 * Grupo com "meio" (Meio a Meio): sabores; o preco do item segue a regra (fracionado, maior ou personalizado).
 */
(() => {
  "use strict";

  /* Meio a Meio: fracionado = media arredondada; maior = sabor mais caro; personalizado = preco do produto. */
  const precoMeio = (regra, base, sabores) => {
    if (!sabores.length || regra === "personalizado") return base;
    if (regra === "maior") return Math.max(...sabores);
    const soma = sabores.reduce((t, c) => t + c, 0);
    return Math.floor((soma * 2 + sabores.length) / (sabores.length * 2));
  };

  const conferir = (grupos, escolhas) => {
    const lista = Array.isArray(grupos) ? grupos : [];
    const porOpcao = new Map();
    lista.forEach((g, gi) => (g.opcoes || []).forEach((o) => porOpcao.set(o.id, [gi, o])));
    const porGrupo = new Map();
    for (const e of Array.isArray(escolhas) ? escolhas : []) {
      const achado = porOpcao.get(e.id);
      if (!achado) return { ok: false, erro: "Um complemento escolhido não existe mais. Escolha de novo.", centavos: 0, texto: "" };
      if (!achado[1].disponivel) return { ok: false, erro: `"${achado[1].nome}" acabou. Escolha outra opção.`, centavos: 0, texto: "" };
      if (!porGrupo.has(achado[0])) porGrupo.set(achado[0], []);
      porGrupo.get(achado[0]).push([achado[1], e.qtd || 1]);
    }
    let centavos = 0;
    const partes = [];
    for (let gi = 0; gi < lista.length; gi += 1) {
      const g = lista[gi];
      const escolhidos = porGrupo.get(gi) || [];
      const soma = escolhidos.reduce((t, [, q]) => t + q, 0);
      if (soma < g.minimo) {
        return { ok: false, grupo: g.id, erro: g.minimo === 1 && g.maximo === 1 ? `Escolha uma opção em "${g.nome}".` : `Escolha pelo menos ${g.minimo} em "${g.nome}".`, centavos: 0, texto: "" };
      }
      if (soma > g.maximo) return { ok: false, grupo: g.id, erro: `Escolha no máximo ${g.maximo} em "${g.nome}".`, centavos: 0, texto: "" };
      // Quantidade por opcao: so no grupo que repete (g.repetir), com o limite de cada opcao (o.maximo).
      for (const [o, q] of escolhidos) {
        if (q > 1 && g.repetir === false) return { ok: false, grupo: g.id, erro: `Em "${g.nome}" cada opção pode ser escolhida uma vez.`, centavos: 0, texto: "" };
        if (g.repetir && o.maximo && q > o.maximo) return { ok: false, grupo: g.id, erro: `No máximo ${o.maximo} de "${o.nome}".`, centavos: 0, texto: "" };
      }
      // Meio a Meio: o preco do item vem da regra (diferenca sobre o preco do produto) e cada sabor e uma fracao.
      if (g.meio) {
        if (escolhidos.length) {
          const n = escolhidos.length;
          centavos += precoMeio(g.meio.regra, g.meio.base, escolhidos.map(([o]) => o.sabor_centavos || 0)) - g.meio.base;
          partes.push(`${g.nome}: ${escolhidos.map(([o]) => (n > 1 ? `1/${n} ` : "") + o.nome).join(", ")}`);
        }
        continue;
      }
      const nomes = escolhidos.map(([o, q]) => { centavos += o.centavos * q; return (q > 1 ? `${q}x ` : "") + o.nome; });
      if (nomes.length) partes.push(`${g.nome}: ${nomes.join(", ")}`);
    }
    return { ok: true, erro: null, centavos, texto: partes.join(" · ") };
  };

  /* "Obrigatório · escolha 1", "Opcional · até 3"... */
  const regra = (g) => {
    if (g.minimo === 0) return g.maximo === 1 ? "Opcional · escolha 1" : `Opcional · até ${g.maximo}`;
    return g.minimo === g.maximo ? `Obrigatório · escolha ${g.minimo}` : `Obrigatório · de ${g.minimo} a ${g.maximo}`;
  };

  /* Rotulo do preco de uma opcao: sabor do Meio a Meio mostra o preco dele (sem "+"). */
  const rotuloOpcao = (g, o, reais) => {
    if (!o.disponivel) return "Acabou";
    if (g && g.meio) return g.meio.regra === "personalizado" ? "" : reais(o.sabor_centavos || 0);
    return o.centavos > 0 ? `+ ${reais(o.centavos)}` : "Grátis";
  };

  window.CardapimComplementos = { conferir, regra, precoMeio, rotuloOpcao };
})();
