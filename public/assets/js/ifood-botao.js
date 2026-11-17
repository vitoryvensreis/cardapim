/**
 * Botao "Abrir loja no iFood" do topo (gerencia e modo caixa).
 * Consulta o snapshot local por JSON, sem chamar a API Merchant para cada aba aberta.
 * Estados (data-estado): carregando | aberta | divergente | pausada | fechada | erro | desconectada.
 * Divergente = Merchant confirma aberto, mas ha pausa vigente; so "pausada" e clicavel.
 */
(() => {
  "use strict";

  const botao = document.querySelector("[data-ifood-loja]");
  if (!botao) return;

  const texto = botao.querySelector("[data-ifood-loja-texto]");
  const aviso = document.querySelector("[data-ifood-loja-aviso]");
  const avisoTexto = aviso ? aviso.querySelector("[data-ifood-loja-aviso-texto]") : null;
  // Backend lê apenas o MySQL; a rotina do iFood consulta a API a cada 30s.
  const INTERVALO = 10000;
  let timer = null;
  let ocupado = false;

  const mostrar = (dados) => {
    if (!dados || typeof dados.estado !== "string") return;
    if (dados.estado === "desconectada" || dados.estado === "sessao") {
      botao.hidden = true;
      return;
    }
    botao.hidden = false;
    botao.dataset.estado = dados.estado;
    botao.disabled = dados.pode_abrir !== true;
    botao.removeAttribute("aria-busy");
    texto.textContent = dados.texto || "";
    const detalhe = dados.detalhe || "";
    botao.title = detalhe;
    botao.setAttribute("aria-label", `iFood: ${dados.texto || ""}. ${detalhe}`.trim());
  };

  /** Aviso visivel no topo da tela (verde = deu certo, vermelho = nao deu). */
  const anunciar = (mensagem, ok) => {
    if (!aviso || !avisoTexto || !mensagem) return;
    aviso.classList.toggle("painel-flash-sucesso", ok);
    aviso.classList.toggle("painel-flash-erro", !ok);
    aviso.setAttribute("role", ok ? "status" : "alert");
    avisoTexto.textContent = mensagem;
    aviso.hidden = false;
  };
  aviso?.querySelector("[data-ifood-loja-aviso-fechar]")?.addEventListener("click", () => {
    aviso.hidden = true;
  });

  const consultar = async (novo = false) => {
    if (ocupado || document.hidden) return;
    try {
      const resposta = await fetch(botao.dataset.situacao + (novo === true ? "?novo=1" : ""), { credentials: "same-origin", headers: { Accept: "application/json" } });
      if (resposta.status === 401) {
        botao.hidden = true;
        return;
      }
      mostrar(await resposta.json());
    } catch {
      mostrar({ estado: "erro", texto: "Sem resposta", detalhe: "Sem conexão com o servidor. Tentando de novo em instantes.", pode_abrir: false });
    }
  };

  const agendar = () => {
    clearInterval(timer);
    timer = setInterval(consultar, INTERVALO);
  };

  botao.addEventListener("click", async () => {
    if (botao.disabled || ocupado) return;
    ocupado = true;
    botao.disabled = true;
    botao.dataset.estado = "carregando";
    botao.setAttribute("aria-busy", "true");
    texto.textContent = "Abrindo...";
    try {
      const resposta = await fetch(botao.dataset.abrir, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json", "X-CSRF-Token": botao.dataset.csrf || "" },
      });
      const dados = await resposta.json().catch(() => ({}));
      ocupado = false;
      if (dados.situacao) mostrar(dados.situacao);
      else await consultar();
      anunciar(dados.mensagem || (resposta.ok ? "Loja aberta no iFood." : "Não foi possível abrir a loja no iFood."), resposta.ok);
    } catch {
      ocupado = false;
      mostrar({ estado: "pausada", texto: "Abrir loja", detalhe: "Não foi possível falar com o servidor. Tente de novo.", pode_abrir: true });
      anunciar("Não foi possível falar com o servidor. Tente de novo.", false);
    }
    agendar();
  });

  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) consultar(true);
  });
  document.addEventListener("cardapim:tempo-real", (event) => {
    if (event.detail?.tipo !== "loja.disponibilidade") return;
    event.preventDefault(); // A interface atualiza o botão sem exigir recarregamento.
    consultar(true);
  });

  // Pausa criada ou removida na tela Loja no iFood: o botao muda na hora e confere de novo em alguns
  // segundos (o iFood pode demorar a refletir a mudanca).
  let conferir = null;
  document.addEventListener("ifood:loja-mudou", () => {
    botao.dataset.estado = "carregando";
    consultar(true);
    clearTimeout(conferir);
    conferir = setTimeout(() => consultar(true), 8000);
    agendar();
  });

  botao.dataset.estado = "carregando";
  consultar();
  agendar();
})();
