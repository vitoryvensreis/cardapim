/* Cardapim - interacoes da landing page (depende de core.js). */
(() => {
  "use strict";

  const { reducedMotion, finePointer } = window.Cardapim || { reducedMotion: false, finePointer: false };
  const onVisible = (element, callback, threshold = 0.35) => {
    if (!element) return;
    if (!("IntersectionObserver" in window)) { callback(); return; }
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        observer.disconnect();
        callback();
      }
    }, { threshold });
    observer.observe(element);
  };

  /* Palavra rotativa no topo do hero */
  const rotator = document.querySelector("[data-rotator]");
  if (rotator && !reducedMotion) {
    const words = [...rotator.children];
    let index = 0;
    window.setInterval(() => {
      if (document.hidden) return;
      const current = words[index];
      index = (index + 1) % words.length;
      current.classList.remove("is-active");
      current.classList.add("is-leaving");
      words[index].classList.add("is-active");
      window.setTimeout(() => current.classList.remove("is-leaving"), 400);
    }, 2400);
  }

  /* Inclinacao 3D do painel acompanhando o cursor */
  const tilt = document.querySelector("[data-tilt]");
  const tiltInner = document.querySelector("[data-tilt-inner]");
  if (tilt && tiltInner && finePointer && !reducedMotion) {
    let frame = 0;
    tilt.addEventListener("pointermove", (event) => {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(() => {
        const rect = tilt.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width - 0.5;
        const y = (event.clientY - rect.top) / rect.height - 0.5;
        tiltInner.style.setProperty("--ry", `${(x * 14 - 4).toFixed(2)}deg`);
        tiltInner.style.setProperty("--rx", `${(-y * 10 + 2).toFixed(2)}deg`);
      });
    });
    tilt.addEventListener("pointerleave", () => {
      tiltInner.style.removeProperty("--ry");
      tiltInner.style.removeProperty("--rx");
    });
  }

  /* Contadores animados */
  const counters = [...document.querySelectorAll("[data-count-to]")];
  const formatNumber = (value, decimals) => value.toLocaleString("pt-BR", {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  if (!reducedMotion) {
    counters.forEach((counter) => {
      const target = Number(counter.dataset.countTo);
      const decimals = Number(counter.dataset.decimals || 0);
      if (!Number.isFinite(target)) return;
      counter.textContent = formatNumber(0, decimals);
      onVisible(counter, () => {
        const start = performance.now();
        const duration = 1400;
        const step = (now) => {
          const progress = Math.min((now - start) / duration, 1);
          const eased = 1 - Math.pow(1 - progress, 3);
          counter.textContent = formatNumber(target * eased, decimals);
          if (progress < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      });
    });
  }

  /* Pedidos "ao vivo" no painel ilustrativo */
  const liveOrders = document.querySelector("[data-live-orders]");
  const toast = document.querySelector("[data-toast]");
  const toastNumber = document.querySelector("[data-toast-number]");
  if (liveOrders && !reducedMotion) {
    const flow = [
      ["status-new", "Novo"],
      ["status-preparing", "Preparando"],
      ["status-ready", "Pronto"],
    ];
    const badges = [...liveOrders.querySelectorAll("[data-status]")];
    let orderNumber = 1043;
    let cursor = 0;
    window.setInterval(() => {
      if (document.hidden || !badges.length) return;
      const badge = badges[cursor % badges.length];
      cursor += 1;
      const currentIndex = flow.findIndex(([css]) => badge.classList.contains(css));
      const [nextClass, nextLabel] = flow[(currentIndex + 1) % flow.length];
      flow.forEach(([css]) => badge.classList.remove(css));
      badge.classList.add(nextClass);
      badge.textContent = nextLabel;
      badge.classList.remove("is-changing");
      void badge.offsetWidth;
      badge.classList.add("is-changing");

      if (toast && toastNumber && cursor % 3 === 0) {
        orderNumber += 1;
        toastNumber.textContent = `#${orderNumber}`;
        toast.classList.remove("is-new");
        void toast.offsetWidth;
        toast.classList.add("is-new");
      }
    }, 2600);
  }

  /* Luz que acompanha o cursor nos cards */
  if (finePointer && !reducedMotion) {
    document.querySelectorAll("[data-spotlight]").forEach((card) => {
      card.addEventListener("pointermove", (event) => {
        const rect = card.getBoundingClientRect();
        card.style.setProperty("--spot-x", `${event.clientX - rect.left}px`);
        card.style.setProperty("--spot-y", `${event.clientY - rect.top}px`);
      });
    });
  }

  /* Linha de progresso das etapas conforme a rolagem */
  const stepsWrap = document.querySelector("[data-steps]");
  const progress = document.querySelector("[data-steps-progress]");
  if (stepsWrap && progress) {
    const steps = [...stepsWrap.querySelectorAll(".step")];
    let ticking = false;
    const update = () => {
      ticking = false;
      const rect = stepsWrap.getBoundingClientRect();
      const anchor = window.innerHeight * 0.6;
      const ratio = Math.min(Math.max((anchor - rect.top) / rect.height, 0), 1);
      progress.style.setProperty("--progress", ratio.toFixed(3));
      steps.forEach((step) => {
        step.classList.toggle("is-active", step.getBoundingClientRect().top + 26 < anchor);
      });
    };
    const request = () => {
      if (!ticking) {
        ticking = true;
        requestAnimationFrame(update);
      }
    };
    update();
    window.addEventListener("scroll", request, { passive: true });
    window.addEventListener("resize", request, { passive: true });
  }
})();
