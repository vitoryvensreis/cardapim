/*
 * Cardapim - regra dos complementos no navegador (adicionais, bordas...), igual a do servidor
 * (app/Modules/Catalog/Complementos.php). Serve so para mostrar preco e avisos na hora: o servidor confere tudo de novo.
 * Grupos: [{id, nome, minimo, maximo, opcoes: [{id, nome, centavos, disponivel}]}]. Escolhas: [{id, qtd}].
 */
(() => {
  "use strict";

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

  window.CardapimComplementos = { conferir, regra };
})();
