/* Demonstração local da home: sem APIs, sem persistência e sem pedidos reais. */
(() => {
  "use strict";
  const root = document.querySelector("[data-hero-demo]");
  if (!root) return;

  const screens = [...root.querySelectorAll("[data-demo-screen]")];
  const navButtons = [...root.querySelectorAll("[data-demo-view]")];
  const output = (selector) => root.querySelector(selector);
  const reducedMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches ?? false;
  const state = { view: "overview", order: false, accepted: false, cash: false, items: 0, manual: false };
  let pointerInside = false;
  let step = 0;

  const labelView = { overview: "Visão geral", orders: "Pedidos", cash: "Caixa" };
  const money = (value) => value.toLocaleString("pt-BR", { style: "currency", currency: "BRL" });

  function render() {
    screens.forEach((screen) => { screen.hidden = screen.dataset.demoScreen !== state.view; });
    navButtons.forEach((button) => {
      const active = button.dataset.demoView === state.view;
      button.classList.toggle("is-active", active);
      if (button.classList.contains("hero-demo-nav")) button.setAttribute("aria-pressed", String(active));
    });

    output("[data-demo-breadcrumb]").textContent = labelView[state.view];
    output("[data-demo-count]").textContent = state.order ? "1" : "0";
    output("[data-demo-total]").textContent = money((state.accepted ? 31.9 : 0) + state.items * 6);
    output("[data-demo-preparing]").textContent = state.accepted ? "1" : "0";
    output("[data-demo-empty]").hidden = state.order;
    output("[data-demo-order-row]").hidden = !state.order;

    const status = state.accepted ? "Em preparo" : "Novo";
    for (const element of root.querySelectorAll("[data-demo-overview-status], [data-demo-order-status]")) {
      element.textContent = status;
      element.classList.toggle("is-preparing", state.accepted);
    }
    const receive = output('[data-demo-action="receive"]');
    receive.disabled = state.accepted;
    output("[data-demo-receive-text]").textContent = state.accepted ? "Pedido em preparo" : "Aceitar e preparar";
    output("[data-demo-accepted]").hidden = !state.accepted;
    output("[data-demo-orders-empty]").hidden = state.order;
    output("[data-demo-orders-detail]").hidden = !state.order;

    const open = output('[data-demo-action="open"]');
    open.disabled = state.cash;
    output("[data-demo-open-text]").textContent = state.cash ? "Caixa aberto" : "Abrir caixa";
    output("[data-demo-cash-label]").textContent = state.cash ? "Caixa aberto" : "Caixa fechado";
    output("[data-demo-cash-total]").textContent = money(state.items * 6);
    output("[data-demo-items]").textContent = String(state.items);
    output("[data-demo-add-box]").hidden = !state.cash;
    output("[data-demo-caption]").textContent = state.manual
      ? "Você está no controle da demonstração."
      : pointerInside || root.contains(document.activeElement)
        ? "Animação pausada enquanto você explora."
        : "Veja a operação funcionar automaticamente.";
  }

  function reset() {
    state.order = false;
    state.accepted = false;
    state.cash = false;
    state.items = 0;
    state.view = "overview";
    step = 0;
  }

  function act(action) {
    if (action === "new") { state.order = true; state.view = "orders"; }
    if (action === "receive") { state.order = true; state.accepted = true; state.view = "orders"; }
    if (action === "open") { state.cash = true; state.view = "cash"; }
    if (action === "add" && state.cash) { state.items += 1; state.view = "cash"; }
    if (action === "restart") reset();
  }

  function announce(message) {
    output("[data-demo-announcement]").textContent = message;
  }

  root.addEventListener("click", (event) => {
    const target = event.target instanceof Element ? event.target.closest("button") : null;
    if (!target || !root.contains(target)) return;
    state.manual = true;
    if (target.dataset.demoView) {
      state.view = target.dataset.demoView;
      announce("Tela: " + labelView[state.view]);
    } else if (target.dataset.demoAction) {
      const action = target.dataset.demoAction;
      act(action);
      announce({ new: "Novo pedido de demonstração criado.", receive: "Pedido aceito e em preparo.", open: "Caixa aberto.", add: "Produto adicionado ao caixa.", restart: "Demonstração reiniciada." }[action] || "");
    }
    render();
  });

  // Mouse suspende o roteiro; teclado e toque não perdem o controle após clicar.
  root.addEventListener("pointerdown", (event) => {
    if (event.pointerType === "touch") {
      state.manual = true; // no celular, um toque interrompe o roteiro para sempre evitar cliques acidentais
      render();
    }
  });

  root.addEventListener("pointerenter", (event) => {
    if (event.pointerType === "mouse" || event.pointerType === "pen") {
      pointerInside = true;
      render();
    }
  });
  root.addEventListener("pointerleave", () => { pointerInside = false; render(); });
  root.addEventListener("focusin", render);
  root.addEventListener("focusout", () => { queueMicrotask(render); });

  const storyline = [
    () => { state.order = true; state.view = "overview"; },
    () => { state.view = "orders"; },
    () => { state.accepted = true; },
    () => { state.view = "cash"; },
    () => { state.cash = true; },
    () => { state.items += 1; },
    () => { state.view = "overview"; },
    () => reset(),
  ];

  render();
  if (!reducedMotion) {
    window.setInterval(() => {
      if (document.hidden || pointerInside || root.contains(document.activeElement) || state.manual) return;
      const index = step % storyline.length;
      storyline[index]();
      step = (index + 1) % storyline.length;
      render();
    }, 3300);
  }
})();
