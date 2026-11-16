/* Painel ilustrativo e interativo da home. Dados locais; nenhuma requisição ou persistência. */
(() => {
  "use strict";

  const root = document.querySelector("[data-hero-demo]");
  if (!root) return;

  const $ = (selector) => root.querySelector(selector);
  const screens = [...root.querySelectorAll("[data-demo-screen]")];
  const navigation = [...root.querySelectorAll("[data-demo-view]")];
  const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const money = (cents) => (cents / 100).toLocaleString("pt-BR", { style: "currency", currency: "BRL" });
  const integer = (value) => value.toLocaleString("pt-BR");
  const titles = { overview: "Visão geral", orders: "Pedidos", cash: "Caixa" };

  const state = {
    view: "overview",
    manualView: false,
    orders: 248,
    salesCents: 1847590,
    preparing: 12,
    orderNumber: 1043,
    order: true,
    accepted: false,
    cashOpen: false,
    cashCents: 0,
    items: 0,
  };
  let nextStep = 0;
  const sequence = ["new", "orders", "receive", "cash", "open", "add", "overview"];

  const notify = (message) => {
    $("[data-demo-announcement]").textContent = message;
  };

  function render() {
    screens.forEach((screen) => {
      screen.hidden = screen.dataset.demoScreen !== state.view;
    });
    navigation.forEach((button) => {
      const active = button.dataset.demoView === state.view;
      button.classList.toggle("is-active", active);
      if (button.classList.contains("hero-demo-nav")) {
        button.setAttribute("aria-pressed", String(active));
      }
    });

    $("[data-demo-breadcrumb]").textContent = titles[state.view];
    $("[data-demo-count]").textContent = integer(state.orders);
    $("[data-demo-activity]").textContent = integer(state.orders) + " pedidos hoje";
    $("[data-demo-total]").textContent = money(state.salesCents);
    $("[data-demo-preparing]").textContent = integer(state.preparing);
    root.querySelectorAll("[data-demo-order-number]").forEach((element) => {
      element.textContent = "#" + state.orderNumber;
    });

    $("[data-demo-empty]").hidden = state.order;
    $("[data-demo-order-row]").hidden = !state.order;
    $("[data-demo-orders-empty]").hidden = state.order;
    $("[data-demo-orders-detail]").hidden = !state.order;

    const orderLabel = state.accepted ? "Em preparo" : "Novo";
    root.querySelectorAll("[data-demo-overview-status], [data-demo-order-status]").forEach((element) => {
      element.textContent = orderLabel;
      element.classList.toggle("is-preparing", state.accepted);
    });
    $('[data-demo-action="receive"]').disabled = state.accepted;
    $("[data-demo-receive-text]").textContent = state.accepted ? "Pedido em preparo" : "Aceitar e preparar";
    $("[data-demo-accepted]").hidden = !state.accepted;

    $('[data-demo-action="open"]').disabled = state.cashOpen;
    $("[data-demo-open-text]").textContent = state.cashOpen ? "Caixa aberto" : "Abrir caixa";
    $("[data-demo-cash-label]").textContent = state.cashOpen ? "Caixa aberto" : "Caixa fechado";
    $("[data-demo-cash-total]").textContent = money(state.cashCents);
    $("[data-demo-items]").textContent = integer(state.items);
    $("[data-demo-add-box]").hidden = !state.cashOpen;
  }

  function pulse() {
    // Feedback visual leve, sem mover telas, foco ou a posição dos botões.
    const targets = root.querySelectorAll("[data-demo-activity], [data-demo-count], [data-demo-total]");
    targets.forEach((el) => {
      el.classList.remove("is-updating");
      void el.offsetWidth;
      el.classList.add("is-updating");
    });
  }

  function receiveNewOrder() {
    state.orderNumber += 1;
    state.orders += 1;
    state.order = true;
    state.accepted = false;
  }

  function receiveOrder() {
    if (!state.order || state.accepted) return;
    state.accepted = true;
    state.preparing += 1;
    state.salesCents += 3190;
  }

  function openCash() {
    if (state.cashOpen) return;
    state.cashOpen = true;
    state.cashCents = 684250;
    state.items = 87;
  }

  function addProduct() {
    if (!state.cashOpen) return;
    state.items += 1;
    state.cashCents += 600;
    state.salesCents += 600;
  }

  root.addEventListener("click", (event) => {
    const button = event.target instanceof Element ? event.target.closest("button") : null;
    if (!button || !root.contains(button)) return;

    // O usuário escolhe a tela; o movimento dos números continua, sem tirá-lo dela.
    state.manualView = true;
    if (button.dataset.demoView) {
      state.view = button.dataset.demoView;
      notify("Tela: " + titles[state.view]);
    } else {
      switch (button.dataset.demoAction) {
        case "new":
          receiveNewOrder();
          state.view = "orders";
          notify("Novo pedido recebido.");
          break;
        case "receive":
          receiveOrder();
          state.view = "orders";
          notify("Pedido aceito e em preparo.");
          break;
        case "open":
          openCash();
          state.view = "cash";
          notify("Caixa aberto.");
          break;
        case "add":
          addProduct();
          state.view = "cash";
          notify("Produto adicionado.");
          break;
        default:
          return;
      }
    }
    render();
    pulse();
  });

  // Foco de teclado ou toque fixa só a tela escolhida; as métricas seguem mudando.
  root.addEventListener("focusin", () => { state.manualView = true; });
  root.addEventListener("pointerdown", (event) => {
    if (event.pointerType === "touch") state.manualView = true;
  });

  function advance() {
    const action = sequence[nextStep];
    nextStep = (nextStep + 1) % sequence.length;

    if (state.manualView) {
      // Mantenha controles estáveis enquanto a pessoa utiliza o painel.
      // O fluxo simulado continua nos números, inclusive com o mouse por cima.
      if (action === "new") state.orders += 1;
      if (action === "receive") {
        state.preparing += 1;
        state.salesCents += 3190;
      }
      if (action === "add" && state.cashOpen) addProduct();
    } else {
      switch (action) {
        case "new":
          receiveNewOrder();
          state.view = "overview";
          break;
        case "orders":
          state.view = "orders";
          break;
        case "receive":
          receiveOrder();
          break;
        case "cash":
          state.view = "cash";
          break;
        case "open":
          openCash();
          break;
        case "add":
          addProduct();
          break;
        case "overview":
          state.view = "overview";
          break;
      }
    }

    render();
    pulse();
  }

  render();
  // Mantém o ciclo contínuo, sem reinício e sem interromper no hover.
  // Respeita preferências de acessibilidade e evita trabalho com a aba oculta.
  if (!prefersReducedMotion) {
    window.setInterval(() => {
      if (!document.hidden) advance();
    }, 2200);
  }
})();
