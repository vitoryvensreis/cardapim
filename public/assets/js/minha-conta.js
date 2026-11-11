/* Cardapim - Minha conta: QR Code do autenticador, copiar chave/codigos, baixar e imprimir codigos. */
(() => {
  "use strict";
  // Nome da marca vem do servidor (configuracoes.nome_sistema), via <meta name="application-name">.
  const marca = () => document.querySelector('meta[name="application-name"]')?.content || "Deliveryzim";

  /* QR Code gerado no proprio navegador (biblioteca qrcode-generator, MIT): o segredo nao sai da pagina. */
  const alvo = document.querySelector("[data-qr]");
  if (alvo && typeof window.qrcode === "function") {
    try {
      const qr = window.qrcode(0, "M");
      qr.addData(alvo.dataset.qr);
      qr.make();
      alvo.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    } catch {
      alvo.textContent = "Não foi possível mostrar o QR Code. Use a chave abaixo.";
    }
  } else if (alvo) {
    alvo.textContent = "Não foi possível mostrar o QR Code. Use a chave abaixo.";
  }

  const avisar = (botao, texto) => {
    const aviso = botao.closest("section, form, li, div")?.querySelector("[data-copiado]") || document.querySelector("[data-copiado]");
    if (aviso) aviso.textContent = texto;
  };

  document.querySelectorAll("[data-copiar]").forEach((botao) => {
    botao.addEventListener("click", async () => {
      try {
        await navigator.clipboard.writeText(botao.dataset.copiar);
        avisar(botao, "Copiado!");
      } catch {
        avisar(botao, "Não deu para copiar. Selecione e copie manualmente.");
      }
    });
  });

  document.querySelector("[data-baixar-codigos]")?.addEventListener("click", (evento) => {
    const codigos = [...document.querySelectorAll("[data-codigos] li")].map((li) => li.textContent.trim());
    const texto = `Códigos de recuperação do ${marca()}\nCada código funciona uma vez.\n\n${codigos.join("\n")}\n`;
    const url = URL.createObjectURL(new Blob([texto], { type: "text/plain;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = evento.currentTarget.dataset.arquivo || "codigos.txt";
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });

  document.querySelector("[data-imprimir]")?.addEventListener("click", () => window.print());
})();
