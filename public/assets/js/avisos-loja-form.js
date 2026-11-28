/* Formulario de aviso para as lojas (painel da plataforma): mostra os campos do botao e atualiza a previa. */
(() => {
  "use strict";
  const form = document.querySelector("[data-aviso-form]");
  if (!form) return;

  const destino = form.querySelector("[data-aviso-destino]");
  const comBotao = form.querySelector("[data-aviso-com-botao]");
  const externo = form.querySelector("[data-aviso-externo]");
  const titulo = form.querySelector("[data-aviso-titulo]");
  const texto = form.querySelector("[data-aviso-texto]");
  const botao = form.querySelector("[data-aviso-botao]");
  const arquivo = form.querySelector("[data-aviso-arquivo]");
  const remover = form.querySelector("[data-aviso-remover]");
  const previa = {
    titulo: document.querySelector("[data-previa-titulo]"),
    texto: document.querySelector("[data-previa-texto]"),
    botao: document.querySelector("[data-previa-botao]"),
    imagem: document.querySelector("[data-previa-imagem]"),
  };
  const imagemOriginal = previa.imagem ? previa.imagem.getAttribute("src") : null;
  let urlLocal = null;

  const atualizar = () => {
    const temBotao = destino.value !== "";
    comBotao.hidden = !temBotao;
    externo.hidden = destino.value !== "externo";
    previa.titulo.textContent = titulo.value.trim() || "Título do aviso";
    previa.texto.textContent = texto.value.trim();
    previa.texto.hidden = texto.value.trim() === "";
    previa.botao.textContent = botao.value.trim() || "Texto do botão";
    previa.botao.hidden = !temBotao;
  };

  const imagem = () => {
    if (!previa.imagem) return;
    if (urlLocal) URL.revokeObjectURL(urlLocal);
    urlLocal = null;
    const escolhido = arquivo && arquivo.files && arquivo.files[0];
    if (escolhido) {
      urlLocal = URL.createObjectURL(escolhido);
      previa.imagem.src = urlLocal;
      previa.imagem.hidden = false;
    } else if (imagemOriginal && !(remover && remover.checked)) {
      previa.imagem.src = imagemOriginal;
      previa.imagem.hidden = false;
    } else {
      previa.imagem.hidden = true;
    }
  };

  [destino, titulo, texto, botao].forEach((campo) => campo.addEventListener("input", atualizar));
  destino.addEventListener("change", atualizar);
  if (arquivo) arquivo.addEventListener("change", imagem);
  if (remover) remover.addEventListener("change", imagem);
  atualizar();
})();
