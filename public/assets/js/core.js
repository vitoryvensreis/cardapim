/* Cardapim - comportamentos comuns das paginas publicas.
   Sem dependencias. Respeita prefers-reduced-motion. */
(() => {
  "use strict";

  const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const finePointer = window.matchMedia("(hover: hover) and (pointer: fine)").matches;
  window.Cardapim = Object.freeze({ reducedMotion, finePointer });

  /* Cabecalho: fundo ao rolar */
  const header = document.querySelector("[data-site-header]");
  if (header) {
    const update = () => header.classList.toggle("is-scrolled", window.scrollY > 24);
    update();
    window.addEventListener("scroll", update, { passive: true });
  }

  /* Menu movel */
  const toggle = document.querySelector("[data-menu-toggle]");
  const nav = document.getElementById("main-navigation");
  if (toggle && nav) {
    const setOpen = (open) => {
      toggle.setAttribute("aria-expanded", String(open));
      toggle.setAttribute("aria-label", open ? "Fechar menu" : "Abrir menu");
      nav.classList.toggle("is-open", open);
      if (open) header?.classList.add("is-scrolled");
      else if (header && window.scrollY <= 24) header.classList.remove("is-scrolled");
    };
    toggle.addEventListener("click", () => setOpen(toggle.getAttribute("aria-expanded") !== "true"));
    nav.querySelectorAll("a").forEach((link) => link.addEventListener("click", () => setOpen(false)));
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && nav.classList.contains("is-open")) {
        setOpen(false);
        toggle.focus();
      }
    });
    document.addEventListener("click", (event) => {
      if (!nav.contains(event.target) && !toggle.contains(event.target)) setOpen(false);
    });
  }

  /* Link ativo conforme a secao visivel */
  const navLinks = [...document.querySelectorAll("[data-nav-link]")];
  if (navLinks.length && "IntersectionObserver" in window) {
    const byId = new Map(navLinks.map((link) => [link.hash.slice(1), link]));
    const sectionObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        const link = byId.get(entry.target.id);
        if (!link) return;
        if (entry.isIntersecting) {
          navLinks.forEach((item) => item.removeAttribute("aria-current"));
          link.setAttribute("aria-current", "true");
        }
      });
    }, { rootMargin: "-45% 0px -50% 0px" });
    byId.forEach((_, id) => {
      const section = document.getElementById(id);
      if (section) sectionObserver.observe(section);
    });
  }

  /* Revelar elementos ao rolar, com atraso escalonado entre irmaos */
  const revealItems = [...document.querySelectorAll("[data-reveal]")];
  if (!reducedMotion && "IntersectionObserver" in window && revealItems.length) {
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("is-revealed");
        revealObserver.unobserve(entry.target);
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -40px 0px" });
    revealItems.forEach((item) => {
      const siblings = item.parentElement ? [...item.parentElement.children].filter((el) => el.hasAttribute("data-reveal")) : [];
      const index = Math.max(0, siblings.indexOf(item));
      item.style.setProperty("--reveal-delay", `${Math.min(index, 6) * 80}ms`);
      item.classList.add("will-reveal");
      revealObserver.observe(item);
    });
  }

  /* Brilho que acompanha o cursor nos botoes */
  if (finePointer && !reducedMotion) {
    document.addEventListener("pointermove", (event) => {
      const button = event.target.closest?.(".btn");
      if (!button) return;
      const rect = button.getBoundingClientRect();
      button.style.setProperty("--mx", `${event.clientX - rect.left}px`);
      button.style.setProperty("--my", `${event.clientY - rect.top}px`);
    }, { passive: true });
  }

  /* Voltar ao topo */
  document.querySelectorAll("[data-back-to-top]").forEach((button) => {
    button.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: reducedMotion ? "auto" : "smooth" });
      document.querySelector(".skip-link")?.focus({ preventScroll: true });
    });
  });
})();
