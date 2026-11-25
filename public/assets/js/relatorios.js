/* Aprimoramento progressivo: sem JS o formulário continua funcional. */
(() => {
  const form = document.querySelector('.rel-filtros');
  if (!form) return;
  const periodo = form.querySelector('[name="periodo"]');
  const datas = form.querySelector('.rel-datas');
  if (periodo && datas) {
    const atualizar = () => { datas.hidden = periodo.value !== 'personalizado'; };
    periodo.addEventListener('change', atualizar);
    atualizar();
  }

  const raiz = form.querySelector('.rel-autocomplete');
  const campo = form.querySelector('[name="busca"]');
  const produto = form.querySelector('[name="produto"]');
  const alternativo = form.querySelector('.rel-produto-alternativo');
  if (!raiz || !campo || !produto || !alternativo || !raiz.dataset.produtosUrl) return;
  const painel = raiz.querySelector('.rel-sugestoes');
  const lista = raiz.querySelector('[role="listbox"]');
  const msg = raiz.querySelector('.rel-sugestoes-msg');
  if (!painel || !lista || !msg) return;

  campo.setAttribute('role', 'combobox');
  campo.setAttribute('aria-autocomplete', 'list');
  campo.setAttribute('aria-controls', lista.id);
  campo.setAttribute('aria-expanded', 'false');
  alternativo.hidden = true;
  let timer = null, pedido = null, versao = 0, resultados = [], ativo = -1;
  const limparHistorico = () => {
    for (const nome of ['nome_historico', 'unidade']) {
      const el = form.querySelector('[name="' + nome + '"]');
      if (el) el.value = '';
    }
  };
  const cancelar = () => {
    clearTimeout(timer);
    if (pedido) pedido.abort();
    pedido = null;
    versao++;
  };
  const abrir = () => {
    painel.hidden = false;
    campo.setAttribute('aria-expanded', 'true');
  };
  const fechar = () => {
    cancelar();
    painel.hidden = true;
    lista.replaceChildren();
    msg.textContent = '';
    resultados = [];
    ativo = -1;
    campo.setAttribute('aria-expanded', 'false');
    campo.removeAttribute('aria-activedescendant');
  };
  const marcar = (indice) => {
    ativo = indice;
    for (let i = 0; i < lista.children.length; i++) {
      lista.children[i].setAttribute('aria-selected', String(i === ativo));
    }
    if (ativo >= 0) {
      const linha = lista.children[ativo];
      campo.setAttribute('aria-activedescendant', linha.id);
      linha.scrollIntoView({block:'nearest'});
    } else campo.removeAttribute('aria-activedescendant');
  };
  const escolher = (p) => {
    if (!Array.from(produto.options).some(o => o.value === p.id)) {
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = p.nome;
      produto.appendChild(opt);
    }
    produto.value = p.id;
    campo.value = p.nome;
    limparHistorico();
    fechar();
    campo.focus();
  };
  const render = (itens) => {
    resultados = itens;
    ativo = -1;
    campo.removeAttribute('aria-activedescendant');
    lista.replaceChildren();
    for (let i = 0; i < itens.length; i++) {
      const p = itens[i], li = document.createElement('li');
      li.id = lista.id + '-' + i;
      li.setAttribute('role','option');
      li.setAttribute('aria-selected','false');
      li.textContent = p.nome;
      const detalhe = [p.categoria, p.ativo ? '' : 'Inativo', '#' + p.id].filter(Boolean).join(' · ');
      const sub = document.createElement('small');
      sub.textContent = detalhe;
      li.appendChild(sub);
      li.addEventListener('mousedown', e => e.preventDefault());
      li.addEventListener('click', () => escolher(p));
      lista.appendChild(li);
    }
    msg.textContent = itens.length ? '' : 'Nenhum produto encontrado. Você pode pesquisar pelo texto digitado.';
    abrir();
  };
  const buscar = async (termo, atual) => {
    const controller = new AbortController();
    pedido = controller;
    try {
      const resposta = await fetch(raiz.dataset.produtosUrl + '?' + new URLSearchParams({q:termo}), {
        signal:controller.signal, headers:{Accept:'application/json'}, credentials:'same-origin'
      });
      if (!resposta.ok) throw new Error('Consulta indisponível');
      const dados = await resposta.json();
      if (atual !== versao) return;
      const itens = Array.isArray(dados.produtos) ? dados.produtos.filter(p =>
        p && typeof p.id === 'string' && /^\d+$/.test(p.id) && typeof p.nome === 'string'
      ).slice(0,20) : [];
      render(itens);
    } catch (e) {
      if (atual !== versao || e.name === 'AbortError') return;
      msg.textContent = 'Sugestões indisponíveis. A pesquisa por texto continua funcionando.';
      abrir();
    } finally {
      if (pedido === controller) pedido = null;
    }
  };
  campo.addEventListener('input', () => {
    produto.value = '';
    limparHistorico();
    fechar();
    const termo = campo.value.trim();
    if (termo.length < 2) return;
    msg.textContent = 'Buscando produtos...';
    abrir();
    const atual = versao;
    timer = setTimeout(() => buscar(termo, atual), 250);
  });
  campo.addEventListener('keydown', e => {
    if (e.key === 'Escape') { fechar(); return; }
    if (painel.hidden || !resultados.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      marcar(e.key === 'ArrowDown' ? (ativo + 1) % resultados.length :
        (ativo <= 0 ? resultados.length - 1 : ativo - 1));
    } else if (e.key === 'Enter' && ativo >= 0) {
      e.preventDefault();
      escolher(resultados[ativo]);
    }
  });
  document.addEventListener('pointerdown', e => { if (!raiz.contains(e.target)) fechar(); });
  raiz.addEventListener('focusout', e => { if (!raiz.contains(e.relatedTarget)) fechar(); });
  form.addEventListener('submit', fechar);
})();
