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

  /* ---------- Interrupcoes iFood: bloquear ate confirmar registro, periodo e status ---------- */
  const pausas = document.querySelector("[data-pausas]");
  if (pausas) {
    const overlay = document.querySelector("[data-ifood-overlay]");
    const urlEstado = pausas.dataset.pausasStatusUrl;
    const suave = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    let ocupado = false;

    const mostrarOverlay = (ligado, titulo = "Aguardando iFood", detalhe = "Verificando a solicitação e o status da loja.") => {
      if (!overlay) return;
      if (ligado) {
        const rotulo = overlay.querySelector("strong");
        const explicacao = overlay.querySelector("p");
        if (rotulo) rotulo.textContent = titulo;
        if (explicacao) explicacao.textContent = detalhe;
      }
      overlay.hidden = !ligado;
      document.body.classList.toggle("ifood-aguardando-ativo", ligado);
    };
    const mostrarCard = () => {
      pausas.scrollIntoView({ behavior: suave ? "smooth" : "auto", block: "start" });
      pausas.querySelector("[data-pausas-aviso]")?.focus({ preventScroll: true });
    };
    const aviso = (mensagem, sucesso = false) => {
      pausas.querySelector("[data-pausas-aviso]")?.remove();
      const box = document.createElement("div");
      box.className = "notice " + (sucesso ? "notice-success" : "notice-warning") + " ifood-pausa-aviso";
      box.setAttribute("role", sucesso ? "status" : "alert");
      box.tabIndex = -1;
      box.dataset.pausasAviso = "";
      const p = document.createElement("p");
      p.textContent = mensagem;
      box.append(p);
      pausas.querySelector(".bloco-cabecalho")?.after(box);
    };
    const iconeCarregando = () => {
      const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      svg.setAttribute("class", "icon");
      svg.setAttribute("aria-hidden", "true");
      const href = overlay?.querySelector("svg use")?.getAttribute("href");
      if (href) {
        const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
        use.setAttribute("href", href);
        svg.append(use);
      }
      return svg;
    };
    const etiquetaPendente = (texto) => {
      const span = document.createElement("span");
      span.className = "ifood-estado-pendente";
      span.append(iconeCarregando(), document.createTextNode(texto));
      return span;
    };
    const acrescentarPendente = (descricao, periodo, texto, id = "", acao = "criar") => {
      let item = id ? [...pausas.querySelectorAll("[data-ifood-pausa-id]")].find(
        (li) => li.dataset.ifoodPausaId === id
      ) : null;
      if (item) {
        item.querySelector(".ifood-estado-pendente")?.remove();
        item.append(etiquetaPendente(texto));
        item.querySelector("button[type=submit]")?.setAttribute("disabled", "disabled");
        return item;
      }
      if (acao === "remover") return null;
      item = document.createElement("li");
      item.className = "ifood-lista-item ifood-pausa-temporaria";
      item.dataset.ifoodPendente = "";
      const conteudo = document.createElement("span");
      const titulo = document.createElement("strong");
      titulo.textContent = descricao || "Nova pausa";
      const horas = document.createElement("small");
      horas.textContent = periodo;
      conteudo.append(titulo, horas);
      item.append(conteudo, etiquetaPendente(texto));
      let lista = pausas.querySelector(".ifood-lista");
      if (!lista) {
        lista = document.createElement("ul");
        lista.className = "ifood-lista";
        pausas.querySelector(".bloco-cabecalho")?.after(lista);
      }
      lista.prepend(item);
      return item;
    };
    const atualizarStatus = (html) => {
      if (typeof html !== "string") return;
      const destino = document.querySelector("[data-ifood-status]");
      if (!destino) return;
      const temp = document.createElement("div");
      temp.innerHTML = html;
      const novo = temp.querySelector("[data-ifood-status]");
      if (novo) destino.replaceWith(novo);
    };
    const consultar = async (id, acao, inicio = "", fim = "") => {
      const url = new URL(urlEstado, window.location.href);
      url.searchParams.set("pausa", id);
      url.searchParams.set("acao", acao);
      if (acao === "criar") {
        url.searchParams.set("inicio", inicio);
        url.searchParams.set("fim", fim);
      }
      // Cada consulta tem seu proprio limite, sem cancelar ou repetir o POST.
      const controlador = new AbortController();
      const limite = window.setTimeout(() => controlador.abort(), 12000);
      try {
        const resposta = await fetch(url.toString(), {
          credentials: "same-origin", cache: "no-store",
          headers: { Accept: "application/json" }, signal: controlador.signal,
        });
        const dados = await resposta.json().catch(() => ({}));
        if (!resposta.ok || !dados.ok) throw new Error(dados.mensagem || "Não foi possível consultar a situação da loja.");
        return dados;
      } finally {
        window.clearTimeout(limite);
      }
    };
    const aguardar = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    const acompanhar = async (id, acao, descricao, periodo, inicio = "", fim = "") => {
      const intervalos = [1500, 3500, 6000, 9000, 12000, 15000];
      let ultimo = null;
      for (let tentativa = 0; tentativa < intervalos.length; tentativa += 1) {
        mostrarOverlay(true, "Confirmando no iFood",
          "Verificando o registro da interrupção, os horários e a disponibilidade da loja (" + (tentativa + 1) + "/" + intervalos.length + ").");
        await aguardar(intervalos[tentativa]);
        try {
          const dados = await consultar(id, acao, inicio, fim);
          ultimo = dados;
          if (typeof dados.html === "string") pausas.innerHTML = dados.html;
          atualizarStatus(dados.html_status);
          document.dispatchEvent(new CustomEvent("ifood:loja-mudou"));

          if (!dados.registro_confirmado) {
            acrescentarPendente(descricao, periodo, acao === "criar" ? "Aguardando registro no iFood" : "Aguardando remoção no iFood", id, acao);
            continue;
          }
          if (!dados.situacao_consultada) {
            acrescentarPendente(descricao, periodo, "Registro confirmado; verificando a disponibilidade", id, acao);
            continue;
          }
          if (acao === "remover") {
            aviso(dados.disponivel === true
              ? "Remoção confirmada no iFood. A disponibilidade da loja também foi consultada: aberta."
              : "Remoção confirmada no iFood. A disponibilidade foi consultada, mas a loja ainda está indisponível por outra condição.", true);
            return;
          }
          if (!dados.periodo_confirmado) {
            acrescentarPendente(descricao, periodo, "Conferindo os horários devolvidos pelo iFood", id);
            continue;
          }
          // Interrupcao futura e agendamento correto: nao exigir fechar a loja antes do inicio.
          if (!dados.vigente) {
            aviso("Pausa agendada e horários confirmados no iFood. A disponibilidade foi verificada; a loja não precisa fechar antes do início da pausa.", true);
            return;
          }
          if (dados.disponivel === false) {
            aviso("Pausa registrada com horários corretos e fechamento confirmado pelo iFood.", true);
            return;
          }
          acrescentarPendente(descricao, periodo, "Agendamento confirmado; aguardando fechamento operacional", id);
        } catch {
          // Falha na consulta nao prova falha na criacao. Nunca reenviar automaticamente.
          acrescentarPendente(descricao, periodo, "Aguardando nova consulta ao iFood", id, acao);
        }
      }

      if (ultimo?.registro_confirmado && ultimo.periodo_confirmado && ultimo.situacao_consultada) {
        aviso("O iFood confirmou o agendamento e os horários, mas a operação ainda não confirmou o fechamento. Confira a disponibilidade antes de considerar a loja pausada.");
      } else if (ultimo?.registro_confirmado && !ultimo.periodo_confirmado && acao === "criar") {
        aviso("O iFood registrou a pausa, mas os horários devolvidos não coincidem com os enviados. Confira o agendamento no Portal do Parceiro.");
      } else {
        aviso("Não foi possível confirmar o registro e a disponibilidade no prazo de verificação. A solicitação pode ter sido aceita. Consulte o Portal do Parceiro antes de tentar novamente.");
      }
    };

    pausas.addEventListener("submit", async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.matches("[data-pausa-form]")) return;
      // Remocao pede confirmacao antes (confirmar.js).
      if (form.dataset.confirm && form.dataset.confirmado !== "1") return;
      event.preventDefault();
      if (ocupado) return;
      if (form.matches(".ifood-form-pausa") && !form.reportValidity()) return;

      ocupado = true;
      const botao = form.querySelector("[type=submit]");
      if (!botao) { ocupado = false; return; }
      const rotulo = botao.querySelector("span");
      const original = rotulo ? rotulo.textContent : "";
      const criando = form.matches(".ifood-form-pausa");
      const descricao = criando ? (form.querySelector("[name=descricao]")?.value || "").trim() : "Pausa";
      const periodo = criando
        ? "De " + (form.querySelector("[name=inicio]")?.value || "").replace("T", " ") + " até " + (form.querySelector("[name=fim]")?.value || "").replace("T", " ")
        : "";
      const dadosForm = new FormData(form);
      botao.disabled = true;
      botao.setAttribute("aria-busy", "true");
      if (rotulo && botao.dataset.submitLabel) rotulo.textContent = botao.dataset.submitLabel;
      if (criando) acrescentarPendente(descricao, periodo, "Aguardando resposta do iFood");
      mostrarOverlay(true, "Enviando ao iFood", "Enviando a solicitação. Em seguida, vamos confirmar o registro e a disponibilidade.");
      const controlador = new AbortController();
      const limite = window.setTimeout(() => controlador.abort(), 25000);
      try {
        const resposta = await fetch(form.action, {
          method: "POST", body: dadosForm, credentials: "same-origin",
          headers: { Accept: "application/json", "X-Requested-With": "fetch" },
          signal: controlador.signal,
        });
        const dados = await resposta.json().catch(() => ({}));
        if (typeof dados.html !== "string") throw new Error(dados.mensagem || "Resposta inesperada do servidor.");
        pausas.innerHTML = dados.html;
        if (!resposta.ok || !dados.ok) {
          mostrarCard();
          return;
        }
        document.dispatchEvent(new CustomEvent("ifood:loja-mudou"));
        const id = dados.id_pausa;
        if (typeof id === "string" && id !== "" && urlEstado) {
          const acao = dados.acao === "remover" ? "remover" : "criar";
          if (acao === "criar") acrescentarPendente(descricao, periodo, "Aguardando confirmação do iFood", id);
          await acompanhar(id, acao, descricao, periodo,
            criando ? (dadosForm.get("inicio") || "").toString() : "",
            criando ? (dadosForm.get("fim") || "").toString() : "");
        } else {
          aviso("Solicitação enviada, mas não foi possível acompanhar o identificador. Confira as pausas e o status antes de tentar novamente.");
        }
      } catch (erro) {
        botao.disabled = false;
        botao.removeAttribute("aria-busy");
        if (rotulo) rotulo.textContent = original;
        delete form.dataset.confirmado;
        aviso(erro.name === "AbortError"
          ? "O iFood demorou a responder. A solicitação pode ter sido registrada; atualize e confira as pausas antes de enviar novamente."
          : erro.message || "Não foi possível confirmar a resposta do iFood. Confira a lista antes de repetir.");
      } finally {
        window.clearTimeout(limite);
        mostrarOverlay(false);
        ocupado = false;
        mostrarCard();
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
