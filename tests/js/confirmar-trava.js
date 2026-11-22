// Teste sem navegador: forms.js (trava contra clique duplo) + confirmar.js (janela "Tem certeza?").
// Garante que o envio confirmado sai (ex.: Fechar caixa), que o clique duplo continua travado e que campo invalido
// nao abre a janela. Uso: node tests/js/confirmar-trava.js
"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");

let falhas = 0;
const ok = (cond, nome) => { console.log((cond ? "  ok  " : "  FALHOU  ") + nome); if (!cond) falhas++; };

function montar({ confirmar = true, valido = true } = {}) {
  const docListeners = {};
  const winListeners = {};
  const classes = () => { const s = new Set(); return { add: (c) => s.add(c), remove: (c) => s.delete(c), contains: (c) => s.has(c), toggle: (c, f) => (f ? s.add(c) : s.delete(c)) }; };
  const enviados = [];
  const botao = { type: "submit", disabled: false, classList: classes(), dataset: {}, querySelector: () => null };
  const campo = { value: valido ? "10,00" : "", required: true, dataset: {}, focus() {}, setAttribute() {}, removeAttribute() {},
    closest: () => campoField, addEventListener() {} };
  const campoField = { classList: classes(), querySelector: () => null };
  const form = {
    dataset: confirmar ? { confirm: "Depois de fechar...", confirmTitulo: "Fechar o caixa agora?" } : {},
    listeners: [],
    addEventListener(t, f) { if (t === "submit") this.listeners.push(f); },
    querySelector: (s) => (s.includes("submit") ? botao : null),
    querySelectorAll: (s) => (s === "[data-field] input" ? [campo] : []),
    requestSubmit(submitter) { disparar(submitter || null); },
    submit() { enviados.push("submit()"); },
  };
  Object.setPrototypeOf(form, null);
  function disparar(submitter) {
    const ev = { target: form, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    for (const f of form.listeners) f(ev);
    for (const f of docListeners.submit || []) f(ev);
    if (!ev.defaultPrevented) enviados.push("enviado");
  }
  const dialogo = { listeners: {}, returnValue: "", aberto: false, showModal() { this.aberto = true; },
    addEventListener(t, f) { (this.listeners[t] ||= []).push(f); },
    fechar(v) { this.aberto = false; this.returnValue = v; (this.listeners.close || []).forEach((f) => f()); } };
  const el = () => ({ textContent: "", classList: classes() });
  const elementos = { "[data-confirmar-dialogo]": dialogo, "[data-confirmar-titulo]": el(), "[data-confirmar-texto]": el(), "[data-confirmar-ok]": el() };
  const document = {
    querySelector: (s) => elementos[s] || null,
    querySelectorAll: (s) => (s === "[data-validate-form]" ? [form] : []),
    addEventListener(t, f) { (docListeners[t] ||= []).push(f); },
  };
  const window = { addEventListener(t, f) { (winListeners[t] ||= []).push(f); }, matchMedia: () => ({ matches: false }) };
  const ctx = vm.createContext({ document, window, HTMLFormElement: Object, console, setTimeout, clearTimeout, URL, Map, WeakMap, Set });
  // form instanceof HTMLFormElement: com HTMLFormElement = Object e prototipo nulo daria false; usa uma classe propria.
  class FakeForm {}
  Object.setPrototypeOf(form, FakeForm.prototype);
  ctx.HTMLFormElement = FakeForm;
  for (const arq of ["confirmar.js", "forms.js"]) {
    vm.runInContext(fs.readFileSync(path.join(__dirname, "../../public/assets/js", arq), "utf8"), ctx, { filename: arq });
  }
  return { form, botao, dialogo, enviados, clicar: () => disparar(botao) };
}

{
  const t = montar();
  t.clicar();
  ok(t.dialogo.aberto && t.enviados.length === 0, "fechar caixa: primeiro clique abre a confirmacao e nao envia");
  ok(!t.botao.classList.contains("is-loading"), "botao nao fica travado antes de confirmar");
  t.dialogo.fechar("confirmar");
  ok(t.enviados.length === 1, "depois de Confirmar o formulario e enviado");
  ok(t.botao.classList.contains("is-loading"), "depois de confirmar o botao trava (clique duplo)");
}
{
  const t = montar();
  t.clicar();
  t.dialogo.fechar("cancel");
  ok(t.enviados.length === 0, "Cancelar na janela nao envia");
  t.clicar();
  ok(t.dialogo.aberto, "pode tentar de novo depois de cancelar");
  t.dialogo.fechar("confirmar");
  ok(t.enviados.length === 1, "e enviar confirmando");
}
{
  const t = montar({ confirmar: false });
  t.clicar();
  ok(t.enviados.length === 1, "abrir caixa (sem confirmacao) envia no primeiro clique");
  t.clicar();
  ok(t.enviados.length === 1, "segundo clique rapido continua travado");
}
{
  const t = montar({ valido: false });
  t.clicar();
  ok(!t.dialogo.aberto && t.enviados.length === 0, "campo obrigatorio vazio: nao abre a janela nem envia");
}

if (falhas) { console.log(falhas + " falha(s)."); process.exit(1); }
console.log("Confirmacao e trava de envio: tudo certo.");
