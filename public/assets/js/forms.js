/* Cardapim - formularios: mascaras, validacao amigavel, senha.
   A validacao no navegador so orienta o usuario; o backend sempre revalida. */
(() => {
  "use strict";

  const onlyDigits = (value) => value.replace(/\D+/g, "");

  /* ---------- Mascaras ---------- */
  const patterns = {
    cpf: (d) => d.slice(0, 11).replace(/^(\d{3})(\d)/, "$1.$2").replace(/^(\d{3})\.(\d{3})(\d)/, "$1.$2.$3").replace(/\.(\d{3})(\d{1,2})$/, ".$1-$2"),
    cnpj: (d) => d.slice(0, 14).replace(/^(\d{2})(\d)/, "$1.$2").replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3").replace(/\.(\d{3})(\d)/, ".$1/$2").replace(/(\d{4})(\d{1,2})$/, "$1-$2"),
    cep: (d) => d.slice(0, 8).replace(/^(\d{5})(\d)/, "$1-$2"),
    phone: (d) => {
      const v = d.slice(0, 11);
      if (v.length <= 2) return v.length ? `(${v}` : "";
      if (v.length <= 6) return `(${v.slice(0, 2)}) ${v.slice(2)}`;
      if (v.length <= 10) return `(${v.slice(0, 2)}) ${v.slice(2, 6)}-${v.slice(6)}`;
      return `(${v.slice(0, 2)}) ${v.slice(2, 7)}-${v.slice(7)}`;
    },
  };

  /* Reaplica a mascara mantendo o cursor depois do mesmo numero de digitos. */
  const applyMask = (input, type) => {
    const format = patterns[type];
    if (!format) return;
    const caret = input.selectionStart ?? input.value.length;
    const digitsBeforeCaret = onlyDigits(input.value.slice(0, caret)).length;
    const formatted = format(onlyDigits(input.value));
    input.value = formatted;
    if (document.activeElement !== input) return;
    let position = 0;
    let seen = 0;
    while (position < formatted.length && seen < digitsBeforeCaret) {
      if (/\d/.test(formatted[position])) seen += 1;
      position += 1;
    }
    input.setSelectionRange(position, position);
  };

  const sanitizeUsername = (input) => {
    const cleaned = input.value.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9._-]/g, "");
    if (cleaned !== input.value) input.value = cleaned;
  };

  document.querySelectorAll("[data-mask]").forEach((input) => {
    const type = input.dataset.mask;
    const handler = type === "username" ? () => sanitizeUsername(input) : () => applyMask(input, type);
    input.addEventListener("input", handler);
    if (input.value) handler();
  });

  /* ---------- Validadores ---------- */
  const isValidCpf = (value) => {
    const cpf = onlyDigits(value);
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    for (let length = 9; length < 11; length += 1) {
      let sum = 0;
      for (let i = 0; i < length; i += 1) sum += Number(cpf[i]) * (length + 1 - i);
      const digit = ((sum * 10) % 11) % 10;
      if (digit !== Number(cpf[length])) return false;
    }
    return true;
  };

  const isValidCnpj = (value) => {
    const cnpj = onlyDigits(value);
    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) return false;
    const calc = (length) => {
      const weights = length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
      const sum = weights.reduce((total, weight, i) => total + Number(cnpj[i]) * weight, 0);
      const rest = sum % 11;
      return rest < 2 ? 0 : 11 - rest;
    };
    return calc(12) === Number(cnpj[12]) && calc(13) === Number(cnpj[13]);
  };

  const rules = {
    required: (v) => v.trim() !== "" || "Preencha este campo.",
    name: (v) => v.trim().length >= 2 || "Digite pelo menos 2 letras.",
    cpf: (v) => isValidCpf(v) || "CPF inválido. Confira os números.",
    cnpj: (v) => isValidCnpj(v) || "CNPJ inválido. Confira os números.",
    phone: (v) => {
      const d = onlyDigits(v);
      if (d.length < 10) return "Digite o DDD e o número completo.";
      if (d.length === 11 && d[2] !== "9") return "Celular deve começar com 9 depois do DDD.";
      return true;
    },
    email: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()) || "Digite um e-mail válido, como nome@exemplo.com.",
    username: (v) => /^[a-z0-9._-]{3,60}$/.test(v) || "Use de 3 a 60 caracteres: letras minúsculas, números, ponto, hífen ou sublinhado.",
    password: (v) => v.length >= 8 || "A senha precisa ter pelo menos 8 caracteres.",
  };

  /* Resultados da verificacao de disponibilidade (campo|valor -> resposta da API). */
  const uniqueCache = new Map();
  const uniqueKey = (input) => `${input.dataset.unique}|${input.value.trim().toLowerCase()}`;

  const setState = (field, input, result, okMessage = "") => {
    const message = field.querySelector(".field-msg");
    const hint = message?.dataset.hint || "";
    field.classList.remove("is-valid", "is-invalid", "is-checking");
    message?.classList.remove("is-ok");
    if (result === null) {
      input.removeAttribute("aria-invalid");
      if (message) message.textContent = hint;
      return;
    }
    if (result === true) {
      field.classList.add("is-valid");
      input.setAttribute("aria-invalid", "false");
      if (message) message.textContent = okMessage || hint;
      if (okMessage) message?.classList.add("is-ok");
    } else {
      field.classList.add("is-invalid");
      input.setAttribute("aria-invalid", "true");
      if (message) message.textContent = result;
    }
  };

  const validate = (input, force = false) => {
    const field = input.closest("[data-field]");
    const rule = rules[input.dataset.validate || (input.required ? "required" : "")];
    if (!field || !rule) return true;
    const optionalEmpty = !input.required && input.value.trim() === "";
    if (optionalEmpty || (!force && input.value === "")) {
      setState(field, input, null);
      return true;
    }
    let result = input.required && input.value.trim() === "" ? "Preencha este campo." : rule(input.value);
    let okMessage = "";
    if (result === true && input.dataset.unique) {
      const cached = uniqueCache.get(uniqueKey(input));
      if (cached?.disponivel === false) result = cached.mensagem;
      else if (cached?.disponivel === true) okMessage = cached.mensagem;
      else if (!force) { setState(field, input, null); return true; } // aguarda a consulta
    }
    setState(field, input, result, okMessage);
    return result === true;
  };

  /* ---------- Disponibilidade (usuario, e-mail, CNPJ) enquanto digita ---------- */
  const uniqueTimers = new WeakMap();
  const uniqueRequests = new WeakMap();
  const checkUnique = async (input) => {
    const field = input.closest("[data-field]");
    const rule = rules[input.dataset.validate];
    if (!field || !rule || input.value.trim() === "" || rule(input.value) !== true) return;
    const key = uniqueKey(input);
    if (uniqueCache.has(key)) { validate(input, true); return; }

    uniqueRequests.get(input)?.abort();
    const controller = new AbortController();
    uniqueRequests.set(input, controller);
    field.classList.remove("is-valid", "is-invalid");
    field.classList.add("is-checking");
    const message = field.querySelector(".field-msg");
    if (message) { message.classList.remove("is-ok"); message.textContent = "Verificando disponibilidade..."; }

    try {
      const params = new URLSearchParams({ campo: input.dataset.unique, valor: input.value.trim() });
      const response = await fetch(`/api/v1/cadastro/disponibilidade?${params}`, {
        headers: { Accept: "application/json" },
        signal: controller.signal,
        credentials: "same-origin",
      });
      const data = await response.json().catch(() => ({}));
      if (key !== uniqueKey(input)) return; // valor mudou durante a consulta
      if (response.ok && typeof data.disponivel === "boolean") {
        uniqueCache.set(key, data);
        validate(input, true);
      } else {
        field.classList.remove("is-checking");
        if (message) message.textContent = data.erro || "Não foi possível verificar agora. Vamos conferir ao enviar.";
      }
    } catch (error) {
      if (error.name === "AbortError") return;
      field.classList.remove("is-checking");
      if (message) message.textContent = "Sem conexão para verificar agora. Vamos conferir ao enviar.";
    }
  };

  document.querySelectorAll("[data-unique]").forEach((input) => {
    input.addEventListener("input", () => {
      clearTimeout(uniqueTimers.get(input));
      uniqueRequests.get(input)?.abort();
      const field = input.closest("[data-field]");
      field?.classList.remove("is-checking");
      uniqueTimers.set(input, setTimeout(() => checkUnique(input), 450));
    });
    input.addEventListener("blur", () => {
      clearTimeout(uniqueTimers.get(input));
      checkUnique(input);
    });
  });

  document.querySelectorAll("[data-field] input").forEach((input) => {
    input.addEventListener("blur", () => validate(input));
    input.addEventListener("input", () => {
      const field = input.closest("[data-field]");
      if (field?.classList.contains("is-invalid")) validate(input);
    });
  });

  /* ---------- Mostrar / ocultar senha ---------- */
  document.querySelectorAll("[data-toggle-password]").forEach((button) => {
    const input = button.parentElement?.querySelector("input");
    if (!input) return;
    button.addEventListener("click", () => {
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      button.setAttribute("aria-pressed", String(show));
      button.setAttribute("aria-label", show ? "Ocultar senha" : "Mostrar senha");
      input.focus({ preventScroll: true });
    });
  });

  /* ---------- Forca da senha ---------- */
  const strengthLabels = ["Senha muito fraca", "Senha fraca", "Senha razoável", "Senha forte"];
  document.querySelectorAll("[data-strength]").forEach((meter) => {
    const field = meter.closest("[data-field]");
    const input = field?.querySelector("input");
    const message = field?.querySelector(".field-msg");
    if (!input) return;
    input.addEventListener("input", () => {
      const v = input.value;
      let score = 0;
      if (v.length >= 8) score += 1;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score += 1;
      if (/\d/.test(v)) score += 1;
      if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) score += 1;
      meter.dataset.score = v ? String(Math.max(score, 1)) : "0";
      if (message && v && !field.classList.contains("is-invalid")) message.textContent = strengthLabels[Math.max(score, 1) - 1];
      if (message && !v) message.textContent = message.dataset.hint || "";
    });
  });

  /* ---------- Previa: nunca envia dados ---------- */
  document.querySelectorAll("[data-preview-form]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      const inputs = [...form.querySelectorAll("[data-field] input")];
      const firstInvalid = inputs.find((input) => !validate(input, true));
      firstInvalid?.focus();
    });
  });
})();
