/* Integração com a balança configurada no Agent Windows deste computador.
 * Pareamento feito pelo Agent (ao ligar o Windows ou em "Abrir Deliveryzim"); com uma só balança ligada
 * no restaurante, o servidor já escolhe ela. Nunca se escolhe o equipamento no PDV.
 * Peso estável e recente entra automaticamente no campo, mas o lançamento é sempre confirmado.
 */
(() => {
  "use strict";

  const dialog = document.querySelector("[data-dialogo-produto]");
  if (!dialog) return;

  const mode = dialog.querySelector("[data-balanca-pdv]");
  const toggle = dialog.querySelector("[data-balanca-automatica]");
  const badge = dialog.querySelector("[data-balanca-selo]");
  const status = dialog.querySelector("[data-balanca-status]");
  const input = dialog.querySelector("[data-peso-campo]");
  const block = dialog.querySelector("[data-peso-bloco]");
  if (!mode || !toggle || !badge || !status || !input || !block) return;

  let loading = false;
  let generation = 0;
  let paired = false;

  const writeWeight = (value) => {
    if (input.value === value) return;
    input.value = value;
    input.dispatchEvent(new Event("input", { bubbles: true }));
  };

  const formatWeight = (value) => {
    const kg = Number(value);
    return Number.isFinite(kg) && kg > 0 && kg <= 99.999
      ? kg.toFixed(3).replace(".", ",") : null;
  };

  const feedback = (name, message) => {
    badge.textContent = name;
    status.hidden = !message;
    status.textContent = message || "";
  };

  const auto = () => paired && toggle.checked && !mode.hidden;

  const apply = (device) => {
    // Sem vínculo com o Agent desta estação, o campo continua manual.
    if (!device) {
      if (auto()) writeWeight("");
      paired = false;
      mode.hidden = true;
      input.readOnly = false;
      feedback("", "");
      return;
    }
    const firstLink = !paired;
    paired = true;
    mode.hidden = false;
    if (firstLink) toggle.checked = true;
    if (!toggle.checked) {
      input.readOnly = false;
      feedback("Manual", "Digite o peso no campo abaixo.");
      return;
    }
    input.readOnly = true;
    const kg = device.estavel === true ? formatWeight(device.peso_kg) : null;
    if (kg === null) {
      writeWeight(""); // Jamais conservar peso após perda de estabilidade, conexão ou prazo de 3 s.
      feedback("Sem leitura", "Aguardando peso estável. Para digitar, desmarque Usar balança.");
    } else {
      writeWeight(kg);
      feedback("Conectada", "");
    }
  };

  const refresh = async () => {
    if (!dialog.open || block.hidden || block.querySelector("[data-peso-rotulo]")?.textContent?.trim() !== "Peso (kg)"
        || loading || document.hidden) return;
    const turn = generation;
    loading = true;
    try {
      const response = await fetch("/api/v1/desktop/balancas", {
        credentials: "same-origin", cache: "no-store",
        headers: { Accept: "application/json" },
      });
      if (!response.ok) throw new Error(String(response.status));
      const payload = await response.json();
      if (turn !== generation || !dialog.open) return;
      // O servidor seleciona exclusivamente o dispositivo pareado a este navegador.
      apply(payload.selecionada || null);
    } catch {
      if (turn !== generation || !dialog.open) return;
      if (auto()) {
        writeWeight("");
        feedback("Sem conexão", "Não foi possível confirmar o peso. Desmarque Usar balança para digitar.");
      }
    } finally {
      loading = false;
    }
  };

  // O caixa.js prepara o produto antes de abrir a folha.
  document.addEventListener("click", (event) => {
    if (!event.target.closest("[data-produto-abrir]")) return;
    generation++;
    paired = false;
    mode.hidden = true;
    toggle.checked = true;
    input.readOnly = false;
    feedback("", "");
    window.setTimeout(refresh, 0);
  });

  toggle.addEventListener("change", () => {
    if (!paired) return;
    writeWeight("");
    input.readOnly = toggle.checked;
    if (toggle.checked) {
      feedback("Conectando...", "Aguardando leitura da balança...");
      refresh();
    } else {
      feedback("Manual", "Digite o peso no campo abaixo.");
      input.focus();
    }
  });

  dialog.addEventListener("close", () => { generation++; });
  window.setInterval(refresh, 1500);
  document.addEventListener("visibilitychange", () => { if (!document.hidden) refresh(); });
})();
