/* Cardapim - catalogo com rolagem infinita e busca sem recarregar a pagina.
   Sem JS, a pagina continua funcionando com o link "Ver mais restaurantes". */
(() => {
  "use strict";

  const list = document.querySelector("[data-catalog-list]");
  const template = document.querySelector("[data-catalog-template]");
  if (!list || !template) return;

  const els = {
    sentinel: document.querySelector("[data-catalog-sentinel]"),
    loader: document.querySelector("[data-catalog-loader]"),
    error: document.querySelector("[data-catalog-error]"),
    retry: document.querySelector("[data-catalog-retry]"),
    end: document.querySelector("[data-catalog-end]"),
    emptySearch: document.querySelector('[data-catalog-empty="busca"]'),
    emptyAll: document.querySelector('[data-catalog-empty="vazio"]'),
    more: document.querySelector("[data-catalog-more]"),
    status: document.querySelector("[data-catalog-status]"),
    search: document.querySelector("[data-catalog-search]"),
  };
  const state = {
    cursor: list.dataset.nextCursor || "",
    busca: list.dataset.busca || "",
    loading: false,
    controller: null,
  };

  // Com JS, a rolagem infinita substitui o link de paginacao.
  els.more?.setAttribute("hidden", "");

  const show = (el, visible) => el && (visible ? el.removeAttribute("hidden") : el.setAttribute("hidden", ""));

  const renderCard = (item) => {
    const node = template.content.firstElementChild.cloneNode(true);
    node.classList.add("is-new");
    node.querySelector(".rest-link").href = item.url;
    node.querySelector(".rest-name").textContent = item.nome;
    const initials = node.querySelector(".rest-initials");
    if (item.logo) {
      const img = document.createElement("img");
      img.src = item.logo;
      img.alt = "";
      img.width = 64;
      img.height = 64;
      img.loading = "lazy";
      initials?.replaceWith(img);
    } else if (initials) {
      initials.textContent = item.iniciais;
    }
    return node;
  };

  const load = async ({ reset = false } = {}) => {
    if (state.loading && !reset) return;
    if (!reset && !state.cursor) return;
    state.controller?.abort();
    state.controller = new AbortController();
    state.loading = true;
    show(els.error, false);
    show(els.loader, true);
    if (reset) show(els.end, false);

    const params = new URLSearchParams();
    if (!reset && state.cursor) params.set("cursor", state.cursor);
    if (state.busca) params.set("busca", state.busca);

    try {
      const response = await fetch(`/api/v1/restaurantes?${params}`, {
        headers: { Accept: "application/json" },
        signal: state.controller.signal,
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      if (reset) list.replaceChildren();
      const fragment = document.createDocumentFragment();
      data.itens.forEach((item) => fragment.appendChild(renderCard(item)));
      list.appendChild(fragment);
      state.cursor = data.proximo_cursor || "";

      const total = list.children.length;
      show(els.emptySearch, total === 0 && state.busca !== "");
      show(els.emptyAll, total === 0 && state.busca === "");
      show(els.end, total > 0 && !state.cursor);
      if (reset && els.status) {
        els.status.textContent = state.busca
          ? `${total}${state.cursor ? "+" : ""} resultado${total === 1 ? "" : "s"} para “${state.busca}”`
          : "";
      }
    } catch (error) {
      if (error.name === "AbortError") return;
      show(els.error, true);
    } finally {
      state.loading = false;
      show(els.loader, false);
      // Se a tela ainda nao encheu, continua carregando.
      if (state.cursor && els.sentinel && els.sentinel.getBoundingClientRect().top < window.innerHeight + 200) {
        window.requestAnimationFrame(() => load());
      }
    }
  };

  if ("IntersectionObserver" in window && els.sentinel) {
    new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) load();
    }, { rootMargin: "400px 0px" }).observe(els.sentinel);
  } else {
    els.more?.removeAttribute("hidden");
  }

  els.retry?.addEventListener("click", () => load());

  /* Busca: atualiza a lista enquanto digita e mantem a URL compartilhavel */
  if (els.search) {
    const input = els.search.querySelector("input[name=busca]");
    let timer = 0;
    const apply = () => {
      const value = input.value.trim().replace(/\s+/g, " ").slice(0, 80);
      if (value === state.busca) return;
      state.busca = value;
      state.cursor = "";
      const url = value ? `/catalogo?busca=${encodeURIComponent(value)}` : "/catalogo";
      window.history.replaceState(null, "", url);
      load({ reset: true });
    };
    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(apply, 350);
    });
    els.search.addEventListener("submit", (event) => {
      event.preventDefault();
      clearTimeout(timer);
      apply();
    });
  }
})();
