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

  /* ---------- Pausas iFood: pedido unico, card otimista e confirmacao remota ---------- */
  const pausas = document.querySelector("[data-pausas]");
  if (pausas) {
    const overlay = document.querySelector("[data-ifood-overlay]");
    const aguardar = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));
    let enviando = false;
    const aviso = (mensagem, erro = false) => {
      pausas.querySelector("[data-pausas-aviso]")?.remove();
      const caixa = document.createElement("div");
      caixa.className = "notice ifood-pausa-aviso " + (erro ? "notice-warning" : "notice-info");
      caixa.dataset.pausasAviso = "";
      caixa.setAttribute("role", erro ? "alert" : "status");
      caixa.tabIndex = -1;
      const p = document.createElement("p");
      p.textContent = mensagem;
      caixa.append(p);
      pausas.querySelector(".bloco-cabecalho")?.after(caixa);
      caixa.focus({ preventScroll: true });
    };
    const mostrarOverlay = (texto) => {
      if (!overlay) return;
      overlay.querySelector("[data-ifood-overlay-mensagem]").textContent = texto;
      overlay.hidden = false;
      document.body.classList.add("ifood-bloqueada");
      pausas.setAttribute("aria-busy", "true");
    };
    const atualizarOverlay = (texto) => {
      if (overlay && !overlay.hidden) overlay.querySelector("[data-ifood-overlay-mensagem]").textContent = texto;
    };
    const esconderOverlay = () => {
      if (overlay) overlay.hidden = true;
      document.body.classList.remove("ifood-bloqueada");
      pausas.removeAttribute("aria-busy");
    };
    const otimista = (form) => {
      const lista = pausas.querySelector("[data-pausas-lista]");
      if (!lista) return null;
      lista.hidden = false;
      const vazio = pausas.querySelector("[data-pausas-vazio]");
      if (vazio) vazio.hidden = true;
      const linha = document.createElement("li");
      linha.className = "ifood-lista-item ifood-pausa-otimista";
      linha.dataset.pausaOtimista = "";
      linha.dataset.estado = "enviando";
      const info = document.createElement("span");
      const titulo = document.createElement("strong");
      titulo.textContent = form.elements.namedItem("descricao")?.value.trim() || "Nova pausa";
      const periodo = document.createElement("small");
      const inicio = form.elements.namedItem("inicio")?.value.replace("T", " ") || "";
      const fim = form.elements.namedItem("fim")?.value.replace("T", " ") || "";
      periodo.textContent = "De " + inicio + " até " + fim + " (horário da loja)";
      info.append(titulo, periodo);
      const estado = document.createElement("span");
      estado.className = "ifood-pausa-carregando";
      estado.setAttribute("role", "status");
      const icone = overlay?.querySelector("svg")?.cloneNode(true);
      if (icone) estado.append(icone);
      const texto = document.createElement("span");
      texto.textContent = "Aguardando iFood";
      estado.append(texto);
      linha.append(info, estado);
      lista.prepend(linha);
      return linha;
    };
    const sinalizar = (card, situacao, mensagem) => {
      if (!card) return;
      card.dataset.estado = situacao;
      const status = card.querySelector(".ifood-pausa-carregando");
      if (!status) return;
      status.querySelector("span")?.replaceChildren(document.createTextNode(mensagem));
    };
    const confirmar = async (id, card) => {
      const endereco = pausas.dataset.pausasConsulta;
      if (!endereco || !id) return "indefinida";
      for (let tentativa = 0; tentativa < 5; tentativa += 1) {
        if (tentativa) await aguardar(2000);
        try {
          const url = new URL(endereco, window.location.href);
          url.searchParams.set("id", id);
          const resposta = await fetch(url, {
            credentials: "same-origin",
            headers: { Accept: "application/json" },
            cache: "no-store",
            signal: AbortSignal.timeout(6500),
          });
          if (!resposta.ok) continue;
          const resultado = await resposta.json();
          if (resultado.estado === "fechada") return "fechada";
          if (resultado.estado === "agendada") return "agendada";
          if (resultado.estado === "registrada") {
            sinalizar(card, "registrada", "Registrada; aguardando fechamento");
            atualizarOverlay("Pausa cadastrada. Aguardando o iFood confirmar que a loja está fechada.");
          } else if (resultado.estado === "aguardando") {
            atualizarOverlay("O iFood recebeu a solicitação. Consultando a lista de pausas...");
          }
        } catch {
          atualizarOverlay("A comunicação está demorando. Verificando a disponibilidade...");
        }
      }
      return "pendente";
    };

    pausas.addEventListener("submit", async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.matches("[data-pausa-form]")) return;
      // O modulo confirmar.js solicita a confirmacao de exclusao primeiro.
      if (form.dataset.confirm && form.dataset.confirmado !== "1") return;
      event.preventDefault();
      if (enviando) return;
      const criar = form.matches(".ifood-form-pausa");
      if (criar && !form.checkValidity()) {
        form.reportValidity();
        return;
      }
      enviando = true;
      const botao = form.querySelector("[type=submit]");
      if (botao) botao.disabled = true;
      const card = criar ? otimista(form) : null;
      mostrarOverlay(criar
        ? "Criando a pausa e aguardando o iFood responder."
        : "Removendo a pausa. Aguardando a resposta do iFood.");
      let atualizou = false;
      try {
        const resposta = await fetch(form.action, {
          method: "POST",
          body: new FormData(form),
          credentials: "same-origin",
          headers: { Accept: "application/json", "X-Requested-With": "fetch" },
          signal: AbortSignal.timeout(30000),
        });
        const dados = await resposta.json().catch(() => ({}));
        if (typeof dados.html !== "string") throw new Error(dados.mensagem || "Resposta inesperada do servidor.");
        pausas.innerHTML = dados.html;
        const consulta = pausas.dataset.pausasConsulta;
        if (consulta) pausas.dataset.pausasConsulta = consulta;
        if (!dados.ok) {
          aviso(dados.mensagem || "O iFood recusou a operação. Confira os campos.", true);
          return;
        }
        document.dispatchEvent(new CustomEvent("ifood:loja-mudou"));
        if (criar && dados.pausa_id) {
          const visivel = [...pausas.querySelectorAll("[data-pausa-id]")]
            .find((linha) => linha.dataset.pausaId === dados.pausa_id);
          let pendente = null;
          if (!visivel) {
            pendente = otimista(form);
            if (pendente) sinalizar(pendente, "registrada", "Cadastrada; verificando iFood");
          }
          atualizarOverlay("Pausa cadastrada. Consultando o status operacional do iFood.");
          const estado = await confirmar(dados.pausa_id, pendente);
          if (estado === "fechada" || estado === "agendada") {
            atualizarOverlay(estado === "fechada"
              ? "Loja fechada, confirmada pelo iFood. Atualizando o painel."
              : "Pausa agendada e confirmada pelo iFood. Atualizando o painel.");
            window.location.reload();
            atualizou = true;
            return;
          }
          sinalizar(pendente, "registrada", "Registrada; fechamento não confirmado");
          aviso("O iFood recebeu a pausa, mas ainda não confirmou o fechamento da loja. Consulte a disponibilidade antes de reenviar.", true);
        } else if (criar) {
          aviso("Pausa solicitada. Não foi possível consultar o identificador; confira a lista antes de tentar novamente.", true);
        }
      } catch (erro) {
        sinalizar(card, "indefinida", "Confirmação indisponível");
        aviso(erro.name === "TimeoutError" || erro.name === "AbortError"
          ? "A resposta demorou. Confira a lista de pausas antes de tentar novamente para evitar duplicidade."
          : "Não foi possível confirmar a operação no iFood. Atualize a lista antes de reenviar.", true);
      } finally {
        if (!atualizou) {
          esconderOverlay();
          if (botao?.isConnected) botao.disabled = false;
          enviando = false;
        }
      }
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
