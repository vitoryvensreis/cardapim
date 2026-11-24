const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const code = fs.readFileSync('public/assets/js/rastreamento.js', 'utf8');
const aceito = 'cardapim_consentimento=todos.v2.20261124';
const recusado = 'cardapim_consentimento=necessarios.v2.20261124';
function ambiente(tags, cookie = '', href = 'https://example.test/', referrer = '') {
  const scripts = [], eventos = {}, winEventos = {}, naHora = [], apagados = [];
  let recargas = 0, valor = cookie;
  const document = { currentScript: { dataset: { rastreamento: JSON.stringify(tags) } }, referrer,
    get cookie() { return valor; },
    set cookie(v) { if (v.includes('Max-Age=0')) apagados.push(v); else valor = v; },
    createElement: () => ({ dataset: {} }),
    head: { appendChild: s => { scripts.push(s.src); naHora.push(JSON.parse(JSON.stringify(window.dataLayer || []))); } },
    addEventListener: (nome, fn) => eventos[nome] = fn };
  const window = { location: { href, reload: () => recargas++ }, navigator: {},
    matchMedia: () => ({ matches: false }), addEventListener: (nome, fn) => winEventos[nome] = fn };
  vm.runInNewContext(code, { document, window, URL, Date });
  return { scripts, document, window, eventos, naHora, apagados, recargas: () => recargas };
}
function consent(layer, comando) {
  return layer.find(v => v[0] === 'consent' && v[1] === comando)?.[2];
}
const negado = {analytics_storage:'denied', ad_storage:'denied', ad_user_data:'denied', ad_personalization:'denied'};
const concedido = {analytics_storage:'granted', ad_storage:'granted', ad_user_data:'granted', ad_personalization:'granted'};
const direto = { ga4: 'G-ABC123', meta: '123456789' };
const a = ambiente(direto, 'cardapim_consentimento=todos.20261112');
assert.equal(a.scripts.length, 0);
a.document.cookie = aceito;
a.eventos['cardapim:consentimento']();
a.eventos['cardapim:consentimento']();
assert.equal(a.scripts.length, 2);
assert.match(a.scripts[0], /gtag\/js/);
assert.match(a.scripts[1], /fbevents\.js/);
assert.deepEqual(consent(a.naHora[0], 'default'), negado);
assert.deepEqual(consent(a.naHora[0], 'update'), concedido);
a.document.cookie = recusado + '; _ga=123; _fbp=456; cardapim_sessao=abc';
a.eventos['cardapim:consentimento']();
assert.equal(a.recargas(), 1);
assert.equal(a.window['ga-disable-G-ABC123'], true);
assert.equal(a.apagados.length, 4);
assert.ok(a.apagados.every(v => !v.includes('cardapim_sessao')));
const b = ambiente({ ...direto, gtm: 'GTM-ABC123' }, aceito);
assert.equal(b.scripts.length, 1);
assert.match(b.scripts[0], /gtm\.js/);
assert.deepEqual(consent(b.naHora[0], 'default'), negado);
assert.deepEqual(consent(b.naHora[0], 'update'), concedido);
const advanced = {...direto, google_advanced:true};
const c = ambiente(advanced);
assert.equal(c.scripts.length, 1);
assert.deepEqual(consent(c.naHora[0], 'default'), negado);
assert.equal(consent(c.naHora[0], 'update'), undefined);
c.document.cookie = aceito;
c.eventos['cardapim:consentimento']();
assert.equal(c.scripts.length, 2);
assert.match(c.scripts[1], /fbevents\.js/);
assert.equal(c.recargas(), 0);
const d = ambiente({ gtm:'GTM-ABC123', google_advanced:true });
assert.equal(d.scripts.length, 1);
assert.deepEqual(consent(d.naHora[0], 'default'), negado);
assert.deepEqual(d.naHora[0].find(v => v['gtm.allowlist'])['gtm.allowlist'], ['dbg']);
d.document.cookie = aceito;
d.eventos['cardapim:consentimento']();
assert.equal(d.recargas(), 1);
assert.equal(d.scripts.length, 1);
const e = ambiente(advanced);
e.document.cookie = recusado;
e.eventos['cardapim:consentimento']();
assert.equal(e.recargas(), 1);
assert.equal(ambiente(advanced, recusado).scripts.length, 0);
assert.equal(ambiente({gtm:'GTM-ABC123',google_advanced:true}, recusado).scripts.length, 0);
assert.equal(ambiente({meta:'123456789',google_advanced:true}).scripts.length, 0);
for (const href of ['https://example.test/cadastro', 'https://example.test/catalogo?busca=telefone', 'https://example.test/painel', 'https://example.test/?_dbg=segredo', 'https://example.test/?gtm_auth=segredo']) {
  assert.equal(ambiente(advanced, aceito, href).scripts.length, 0);
}
for (const href of ['https://example.test/?_dbg=1', 'https://example.test/?gtm_debug=1234567890', 'https://example.test/?gtm_preview=env-1']) {
  assert.equal(ambiente(direto, aceito, href).scripts.length, 2);
}
assert.equal(ambiente(direto, aceito, 'https://example.test/', 'https://example.test/cookies').scripts.length, 2);
assert.equal(ambiente(direto, aceito, 'https://example.test/', 'https://example.test/painel?token=abc').scripts.length, 0);
assert.equal(ambiente(direto, recusado).scripts.length, 0);
console.log('OK: rastreamento');
