/* Cardapim - aba Perfil: previa da logo antes de enviar e copiar horario para todos os dias. */
(() => {
  "use strict";

  const TAMANHO_MAXIMO = 2 * 1024 * 1024;
  const TIPOS = ["image/png", "image/jpeg", "image/webp"];

  const resumoErros = document.querySelector("[data-erros-form]");
  if (resumoErros) resumoErros.focus();

  /* ---------- Logo ---------- */
  const arquivo = document.querySelector("[data-logo-arquivo]");
  const previa = document.querySelector("[data-logo-previa]");
  const erro = document.querySelector("[data-logo-erro]");
  const enviar = document.querySelector("[data-logo-enviar]");
  const rotulo = document.querySelector("[data-logo-rotulo]");
  if (arquivo && previa) {
    if (enviar) enviar.hidden = true; /* Com JS, o botao aparece depois de escolher a imagem. */
    arquivo.addEventListener("change", () => {
      const escolhido = arquivo.files?.[0];
      if (erro) erro.hidden = true;
      if (!escolhido) return;
      let mensagem = "";
      if (!TIPOS.includes(escolhido.type)) mensagem = "Escolha uma imagem PNG, JPG ou WebP.";
      else if (escolhido.size > TAMANHO_MAXIMO) mensagem = "A imagem tem mais de 2 MB. Escolha uma menor.";
      if (mensagem) {
        arquivo.value = "";
        if (erro) { erro.textContent = mensagem; erro.hidden = false; }
        if (enviar) enviar.hidden = true;
        return;
      }
      const url = URL.createObjectURL(escolhido);
      const img = document.createElement("img");
      img.src = url;
      img.alt = "Prévia da nova logo";
      img.width = 104;
      img.height = 104;
      img.addEventListener("load", () => URL.revokeObjectURL(url), { once: true });
      previa.replaceChildren(img);
      if (rotulo) rotulo.textContent = "Escolher outra";
      if (enviar) { enviar.hidden = false; enviar.focus(); }
    });
  }

  /* ---------- Horarios ---------- */
  const horarios = document.querySelector("[data-horarios]");
  horarios?.addEventListener("click", async (evento) => {
    const fechar = evento.target.closest("[data-fechar-data]");
    if (fechar) {
      const formulario = horarios.closest("form");
      const mensagem = formulario.querySelector("[data-fechamento-mensagem]");
      const botoes = [...horarios.querySelectorAll("[data-fechar-data]")];
      if (botoes.some((b) => b.disabled)) return;
      const novo = fechar.dataset.fechado === "1" ? "0" : "1";
      botoes.forEach((b) => { b.disabled = true; });
      mensagem.textContent = "Salvando...";
      try {
        const resposta = await fetch(horarios.dataset.fechamentoUrl, {
          method: "POST", credentials: "same-origin", headers: { Accept: "application/json" },
          body: new URLSearchParams({ _csrf: formulario.querySelector("[name=_csrf]").value,
            data: fechar.dataset.fecharData, fechado: novo }),
        });
        if (!resposta.headers.get("content-type")?.includes("application/json")) throw new Error("Sessão expirada ou resposta indisponível. Atualize a página.");
        const info = await resposta.json();
        if (!resposta.ok || !info.ok) throw new Error(info.mensagem || "Não foi possível salvar.");
        fechar.dataset.fechado = info.fechado ? "1" : "0";
        fechar.querySelector("[data-fechamento-rotulo]").textContent = info.fechado ? "Reabrir" : "Fechar temporariamente";
        fechar.closest(".horario-fechamento").querySelector("[data-fechamento-estado]").textContent = info.fechado ? "· Fechado temporariamente" : "";
        const estado = document.querySelector("[data-situacao-perfil]");
        if (estado) {
          estado.classList.remove("etiqueta-neutra", "etiqueta-ok", "etiqueta-alerta");
          estado.classList.add(!info.horario_informado ? "etiqueta-neutra" : (info.aberto ? "etiqueta-ok" : "etiqueta-alerta"));
          estado.querySelector("[data-situacao-texto]").textContent = !info.horario_informado ? "Horário não informado" : (info.aberto ? "Aberto agora" : "Fechado agora");
        }
        mensagem.textContent = info.mensagem;
      } catch (erro) {
        mensagem.textContent = erro.message || "Falha ao salvar. Tente novamente.";
      } finally {
        botoes.forEach((b) => { b.disabled = false; });
      }
      return;
    }
    const botao = evento.target.closest("[data-copiar-dia]");
    if (!botao) return;
    const origem = botao.closest("[data-dia]");
    const campos = (dia) => ({
      aberto: dia.querySelector("[data-aberto]"),
      turno2: dia.querySelector("[data-turno2]"),
      horas: [...dia.querySelectorAll("input[type=time]")],
    });
    const de = campos(origem);
    horarios.querySelectorAll("[data-dia]").forEach((dia) => {
      if (dia === origem) return;
      const para = campos(dia);
      para.aberto.checked = de.aberto.checked;
      para.turno2.checked = de.turno2.checked;
      para.horas.forEach((input, i) => { input.value = de.horas[i].value; });
    });
    botao.textContent = "Copiado para todos os dias. Ajuste algum se precisar.";
  });
})();
