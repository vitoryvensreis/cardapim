/* Cardapim - aba Equipe: gerar senha facil de ditar e copiar usuario + senha. */
(() => {
  "use strict";
  // Nome da marca vem do servidor (configuracoes.nome_sistema), via <meta name="application-name">.
  const marca = () => document.querySelector('meta[name="application-name"]')?.content || "Deliveryzim";

  const PALAVRAS = ["mesa", "prato", "sol", "lua", "cafe", "pao", "suco", "milho", "uva", "caju", "mel", "sal", "chuva", "festa", "praia", "verde", "doce", "forno", "porto", "vento"];
  const aleatorio = (max) => {
    const valor = new Uint32Array(1);
    crypto.getRandomValues(valor);
    return valor[0] % max;
  };
  const gerarSenha = () => {
    const a = PALAVRAS[aleatorio(PALAVRAS.length)];
    let b = PALAVRAS[aleatorio(PALAVRAS.length)];
    while (b === a) b = PALAVRAS[aleatorio(PALAVRAS.length)];
    const numero = String(aleatorio(9000) + 1000);
    return `${a.charAt(0).toUpperCase()}${a.slice(1)}-${b}-${numero}`;
  };

  document.querySelectorAll("[data-gerar-senha]").forEach((botao) => {
    const form = botao.closest("form");
    const campo = form?.querySelector("[data-senha]");
    const copiar = form?.querySelector("[data-copiar-acesso]");
    const aviso = form?.querySelector("[data-copiado]");
    if (!campo) return;

    botao.addEventListener("click", () => {
      campo.value = gerarSenha();
      campo.type = "text";
      const alternar = campo.closest(".field-control")?.querySelector("[data-toggle-password]");
      alternar?.setAttribute("aria-pressed", "true");
      campo.dispatchEvent(new Event("input", { bubbles: true }));
      if (copiar) copiar.hidden = false;
      if (aviso) aviso.textContent = "Anote ou copie a senha: ela não aparece de novo depois de salvar.";
      campo.focus();
    });

    copiar?.addEventListener("click", async () => {
      const usuario = form.querySelector("[data-usuario]")?.value || "";
      const texto = `Acesso ao ${marca()}\nUsuário: ${usuario}\nSenha: ${campo.value}`;
      try {
        await navigator.clipboard.writeText(texto);
        if (aviso) aviso.textContent = "Copiado! Cole no WhatsApp da pessoa.";
      } catch {
        if (aviso) aviso.textContent = "Não deu para copiar. Selecione a senha e copie manualmente.";
      }
    });
  });
})();
