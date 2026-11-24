const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('public/assets/js/rastreamento.js', 'utf8');
function ambiente(tags, cookie = '', href = 'https://example.test/') {
  const scripts = [], eventos = {}, winEventos = [];
  let recargas = 0;
  const document = { currentScript: { dataset: { rastreamento: JSON.stringify(tags) } }, cookie, referrer: '',
    createElement: () => ({}), head: { appendChild: s => scripts.push(s.src) },
    addEventListener: (nome, fn) => eventos[nome] = fn };
  const window = { location: { href, reload: () => recargas++ }, navigator: {},
    matchMedia: () => ({ matches: false }), addEventListener: (nome, fn) => winEventos[nome] = fn };
  vm.runInNewContext(code, { document, window, URL, Date });
  return { scripts, document, window, eventos, recargas: () => recargas };
}
const direto = { ga4: 'G-ABC123', meta: '123456789' };
const a = ambiente(direto, 'cardapim_consentimento=todos.20261112');
assert.equal(a.scripts.length, 0);
a.document.cookie = 'cardapim_consentimento=todos.v2.20261112';
a.eventos['cardapim:consentimento']();
a.eventos['cardapim:consentimento']();
assert.equal(a.scripts.length, 2);
assert.match(a.scripts[0], /gtag\/js/);
assert.match(a.scripts[1], /fbevents\.js/);
a.document.cookie = 'cardapim_consentimento=necessarios.v2.20261112';
a.eventos['cardapim:consentimento']();
assert.equal(a.recargas(), 1);
assert.equal(a.window['ga-disable-G-ABC123'], true);
const b = ambiente({ ...direto, gtm: 'GTM-ABC123' }, 'cardapim_consentimento=todos.v2.20261112');
assert.equal(b.scripts.length, 1);
assert.match(b.scripts[0], /gtm\.js/);
for (const href of ['https://example.test/cadastro', 'https://example.test/catalogo?busca=telefone', 'https://example.test/painel']) {
  assert.equal(ambiente(direto, 'cardapim_consentimento=todos.v2.20261112', href).scripts.length, 0);
}
assert.equal(ambiente(direto, 'cardapim_consentimento=necessarios.v2.20261112').scripts.length, 0);
console.log('OK: rastreamento');
