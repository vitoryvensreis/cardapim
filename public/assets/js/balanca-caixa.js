/* Balança no caixa: consulta o último peso estável do Agent pelo backend.
 * Apenas sugere a quantidade; operador deve clicar para preencher e lançar.
 * O preço e o estoque continuam calculados pelo PHP.
 */
(() => {
  "use strict";
  const dialog = document.querySelector("[data-dialogo-produto]");
  if (!dialog) return;

  const panel = dialog.querySelector("[data-balanca-pdv]");
  const selector = dialog.querySelector("[data-balanca-seletor]");
  const status = dialog.querySelector("[data-balanca-status]");
  const useButton = dialog.querySelector("[data-balanca-usar]");
  const weightInput = dialog.querySelector("[data-peso-campo]");
  if (!panel || !selector || !status || !useButton || !weightInput) return;

  let devices = [];
  let loading = false;
  let selectedId = "";

  const show = (message) => { status.textContent = message; };
  const selected = () => devices.find((d) => String(d.id) === selectedId);
  const formatWeight = (value) => {
    const weight = Number(value);
    return Number.isFinite(weight) && weight > 0 && weight <= 99.999
      ? weight.toFixed(3).replace(".", ",") : null;
  };
  const render = () => {
    const previous = selector.value || selectedId;
    selector.replaceChildren();
    for (const device of devices) {
      const option = document.createElement("option");
      option.value = String(device.id);
      option.textContent = device.nome || "Balança sem nome";
      selector.append(option);
    }
    if (!devices.some((device) => String(device.id) === previous)) {
      selectedId = devices.length ? String(devices[0].id) : "";
    } else selectedId = previous;
    selector.value = selectedId;

    const device = selected();
    const kg = device && device.estavel ? formatWeight(device.peso_kg) : null;
    useButton.disabled = !kg;
    useButton.textContent = kg ? "Usar " + kg + " kg" : "Aguardando peso estável";
    if (!device) show("Nenhuma balança vinculada. Configure a balança no Agent Windows ou digite o peso.");
    else if (!kg) show("Balança " + device.nome + ": aguardando leitura estável e recente.");
    else show("Leitura pronta: " + kg + " kg. Confira o visor físico antes de aplicar.");
  };

  const refresh = async () => {
    if (!dialog.open || panel.hidden || loading || document.hidden) return;
    loading = true;
    try {
      const response = await fetch("/api/v1/desktop/balancas", {
        credentials: "same-origin", cache: "no-store",
        headers: { "Accept": "application/json" },
      });
      if (!response.ok) throw new Error(String(response.status));
      const json = await response.json();
      if (!Array.isArray(json.balancas)) throw new Error("Resposta inválida");
      devices = json.balancas;
      render();
    } catch (_) {
      devices = [];
      useButton.disabled = true;
      useButton.textContent = "Aguardando peso estável";
      show("Sem conexão com a leitura da balança. O peso manual continua disponível.");
    } finally { loading = false; }
  };

  document.addEventListener("click", (event) => {
    if (!event.target.closest("[data-produto-abrir]")) return;
    // O caixa.js atualiza o produto e a unidade antes desta rotina.
    window.setTimeout(() => {
      const label = dialog.querySelector("[data-peso-rotulo]");
      const isKg = label && label.textContent.trim() === "Peso (kg)"
        && !dialog.querySelector("[data-peso-bloco]").hidden;
      panel.hidden = !isKg;
      devices = [];
      useButton.disabled = true;
      if (isKg) { show("Buscando balanças do restaurante..."); refresh(); }
    }, 0);
  });

  selector.addEventListener("change", () => {
    selectedId = selector.value;
    render();
  });

  useButton.addEventListener("click", async () => {
    // Nunca aplicar uma leitura armazenada no navegador sem consultar
    // novamente o servidor. O peso deve estar recente NO INSTANTE da ação.
    const deviceId = selectedId;
    if (!deviceId || useButton.disabled) return;
    useButton.disabled = true;
    try {
      const response = await fetch("/api/v1/desktop/balancas", {
        credentials: "same-origin", cache: "no-store",
        headers: { "Accept": "application/json" },
      });
      if (!response.ok) throw new Error("API indisponível");
      const payload = await response.json();
      const fresh = (payload.balancas || []).find((d) => String(d.id) === deviceId);
      const kg = fresh && fresh.estavel ? formatWeight(fresh.peso_kg) : null;
      if (!kg || !dialog.open || panel.hidden || selector.value !== deviceId) {
        show("A leitura mudou ou expirou. Confira o visor e tente novamente.");
        return;
      }
      weightInput.value = kg;
      weightInput.dispatchEvent(new Event("input", { bubbles: true }));
      weightInput.focus();
      show("Peso preenchido. Confira o valor e clique em Lançar para adicionar à comanda.");
    } catch (_) {
      show("Não foi possível confirmar o peso agora. Digite manualmente ou tente novamente.");
    }
  });

  window.setInterval(refresh, 1700);
})();
