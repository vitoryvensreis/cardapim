/* Cardapim - aba Integracoes: acompanha a conexao do WhatsApp (QR Code), copiar valores e mostrar segredos. */
(() => {
  "use strict";

  const resumoErros = document.querySelector("[data-erros-form]");
  if (resumoErros) resumoErros.focus();

  /* ---------- Horario no iFood: o do Perfil ou um diferente ---------- */
  const horario = document.querySelector("[data-ifood-horario]");
  if (horario) {
    const chave = horario.querySelector("[data-ifood-horario-proprio]");
    const perfil = horario.querySelector("[data-ifood-horario-perfil]");
    const proprio = horario.querySelector("[data-ifood-horario-form]");
    const trocar = () => {
      if (perfil) perfil.hidden = chave.checked;
      if (proprio) proprio.hidden = !chave.checked;
    };
    chave?.addEventListener("change", trocar);
  }

  /* ---------- Pausas do iFood: criar e remover sem recarregar; o aviso aparece dentro do card ---------- */
  const pausas = document.querySelector("[data-pausas]");
  if (pausas) {
    const suave = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const mostrarCard = () => {
      pausas.scrollIntoView({ behavior: suave ? "smooth" : "auto", block: "start" });
      pausas.querySelector("[data-pausas-aviso]")?.focus({ preventScroll: true });
    };
    const avisoErro = (mensagem) => {
      pausas.querySelector("[data-pausas-aviso]")?.remove();
      const aviso = document.createElement("div");
      aviso.className = "notice notice-warning ifood-pausa-aviso";
      aviso.setAttribute("role", "alert");
      aviso.tabIndex = -1;
      aviso.dataset.pausasAviso = "";
      const texto = document.createElement("p");
      texto.textContent = mensagem;
      aviso.append(texto);
      pausas.querySelector(".bloco-cabecalho")?.after(aviso);
    };

    pausas.addEventListener("submit", async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.matches("[data-pausa-form]")) return;
      // Remover pede confirmacao antes (confirmar.js); o envio volta aqui depois do "Remover".
      if (form.dataset.confirm && form.dataset.confirmado !== "1") return;
      event.preventDefault();
      const botao = form.querySelector("[type=submit]");
      if (!botao || botao.disabled) return;
      const rotulo = botao.querySelector("span");
      const original = rotulo ? rotulo.textContent : "";
      botao.disabled = true;
      botao.setAttribute("aria-busy", "true");
      if (rotulo && botao.dataset.submitLabel) rotulo.textContent = botao.dataset.submitLabel;
      try {
        const resposta = await fetch(form.action, {
          method: "POST",
          body: new FormData(form),
          credentials: "same-origin",
          headers: { Accept: "application/json", "X-Requested-With": "fetch" },
        });
        const dados = await resposta.json().catch(() => ({}));
        if (typeof dados.html !== "string") throw new Error(dados.mensagem || "");
        pausas.innerHTML = dados.html;
      } catch (erro) {
        botao.disabled = false;
        botao.removeAttribute("aria-busy");
        if (rotulo) rotulo.textContent = original;
        delete form.dataset.confirmado;
        avisoErro(erro.message || "Não foi possível falar com o servidor. Confira a internet e tente de novo.");
      }
      mostrarCard();
    });
  }

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
  let proxima = null;
  const agendar = (ms) => {
    clearTimeout(proxima);
    proxima = setTimeout(consultar, ms);
  };

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
    clearTimeout(proxima);
    if (document.hidden) {
      agendar(3000);
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
    agendar(espera);
  };

  // Tempo real: o celular conectou ou caiu em outra aba/aparelho? Atualiza esta tela na hora.
  document.addEventListener("cardapim:tempo-real", (ev) => {
    if (!ev.detail || ev.detail.tipo !== "integracao.whatsapp.estado") return;
    ev.preventDefault();
    if (ev.detail.dados && ev.detail.dados.estado === "desconectado") {
      window.location.reload();
      return;
    }
    consultar();
  });

  consultar();
})();
