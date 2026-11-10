/* Cardapim - aba Integracoes: acompanha a conexao do WhatsApp (QR Code), copiar valores e mostrar segredos. */
(() => {
  "use strict";

  const resumoErros = document.querySelector("[data-erros-form]");
  if (resumoErros) resumoErros.focus();

  /* ---------- Copiar e mostrar ---------- */
  document.querySelectorAll("[data-copiar]").forEach((botao) => {
    botao.addEventListener("click", async () => {
      const aviso = botao.closest("details, li, section")?.querySelector("[data-copiado]");
      try {
        await navigator.clipboard.writeText(botao.dataset.copiar);
        if (aviso) aviso.textContent = "Copiado!";
      } catch {
        if (aviso) aviso.textContent = "Não deu para copiar. Selecione e copie manualmente.";
      }
    });
  });

  document.querySelectorAll("[data-mostrar-segredo]").forEach((botao) => {
    botao.addEventListener("click", () => {
      const linha = botao.closest(".copiar-linha");
      const segredo = linha?.querySelector("[data-segredo]");
      const mascara = linha?.querySelector("[data-segredo-mascara]");
      if (!segredo || !mascara) return;
      const mostrar = segredo.hidden;
      segredo.hidden = !mostrar;
      mascara.hidden = mostrar;
      botao.lastChild.textContent = mostrar ? "Esconder" : "Mostrar";
    });
  });

  /* ---------- iFood: recarrega quando o codigo de vinculo vence ---------- */
  const expira = document.querySelector("[data-expira]");
  if (expira) {
    const restante = Number(expira.dataset.expira) * 1000 - Date.now();
    if (restante > 0) setTimeout(() => window.location.reload(), Math.min(restante + 1000, 2147483647));
  }

  /* ---------- WhatsApp: consulta o estado e mostra o QR Code ---------- */
  const bloco = document.querySelector("[data-whatsapp-estado]");
  if (!bloco) return;

  const paineis = bloco.querySelectorAll("[data-painel]");
  const imagem = bloco.querySelector("[data-qr-imagem]");
  const numero = bloco.querySelector("[data-numero]");
  const teste = document.querySelector("[data-so-conectado]");
  let espera = bloco.dataset.estadoInicial === "conectado" ? 30000 : 3000;
  let erros = 0;

  const mostrar = (nome, texto) => {
    paineis.forEach((painel) => {
      painel.hidden = painel.dataset.painel !== nome;
      if (texto && painel.dataset.painel === nome) {
        const alvo = painel.querySelector("[data-texto-estado]");
        if (alvo) alvo.textContent = texto;
      }
    });
    if (teste) teste.hidden = nome !== "conectado";
  };

  const consultar = async () => {
    if (document.hidden) {
      setTimeout(consultar, 3000);
      return;
    }
    try {
      const resposta = await fetch(bloco.dataset.whatsappEstado, { headers: { Accept: "application/json" }, credentials: "same-origin" });
      const dados = await resposta.json();
      erros = 0;
      switch (dados.estado) {
        case "conectado":
          mostrar("conectado");
          if (numero && dados.numero) numero.textContent = dados.numero.replace(/^55(\d{2})(\d{4,5})(\d{4})$/, "($1) $2-$3");
          espera = 30000;
          break;
        case "qrcode":
          if (imagem && dados.qrcode && imagem.src !== dados.qrcode) imagem.src = dados.qrcode;
          mostrar("qrcode");
          espera = 3000;
          break;
        case "expirado":
          mostrar("expirado", dados.mensagem);
          return; /* para de consultar: a pessoa gera outro QR Code */
        case "erro":
          mostrar("erro", dados.mensagem);
          espera = 15000;
          break;
        case "sessao":
          mostrar("erro", dados.mensagem);
          return;
        case "desconectado":
        case "indisponivel":
          window.location.reload();
          return;
        default:
          mostrar("carregando", dados.mensagem || "Preparando o WhatsApp...");
          espera = 3000;
      }
    } catch {
      erros += 1;
      if (erros >= 5) {
        mostrar("erro", "Sem resposta do servidor. Confira sua internet e recarregue a página.");
        return;
      }
    }
    setTimeout(consultar, espera);
  };

  consultar();
})();
