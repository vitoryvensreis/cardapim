/*
 * Clientes > Popup do cardapio (carregado com defer): mostra so os campos do tipo escolhido (imagem ou texto) e
 * atualiza a previa enquanto o gerente digita ou escolhe a imagem (a imagem aparece antes de salvar).
 */
(() => {
  "use strict";

  const raiz = document.querySelector("[data-popup-gerencia]");
  if (!raiz) return;
  const $ = (sel) => raiz.querySelector(sel);
  const $$ = (sel) => Array.from(raiz.querySelectorAll(sel));

  const tipoAtual = () => ($('input[name="tipo"]:checked') || {}).value || "imagem";

  const desenharTipo = () => {
    const tipo = tipoAtual();
    $$("[data-popup-parte]").forEach((el) => { el.hidden = el.dataset.popupParte !== tipo; });
    $$("[data-previa-parte]").forEach((el) => { el.hidden = el.dataset.previaParte !== tipo; });
  };

  const desenharTexto = () => {
    const titulo = $("[data-popup-titulo]");
    const texto = $("[data-popup-texto]");
    $("[data-previa-titulo]").textContent = titulo && titulo.value.trim() !== "" ? titulo.value.trim() : "Título do popup";
    $("[data-previa-texto]").textContent = texto && texto.value.trim() !== "" ? texto.value.trim() : "Sua mensagem aparece aqui.";
  };

  let urlTemporaria = "";
  const arquivo = $("[data-popup-arquivo]");
  if (arquivo) {
    arquivo.addEventListener("change", () => {
      const f = arquivo.files && arquivo.files[0];
      const nome = $("[data-popup-arquivo-nome]");
      if (!f) { nome.hidden = true; return; }
      if (urlTemporaria) URL.revokeObjectURL(urlTemporaria);
      urlTemporaria = URL.createObjectURL(f);
      const img = $("[data-previa-imagem]");
      img.src = urlTemporaria;
      img.hidden = false;
      $("[data-previa-vazia]").hidden = true;
      const grande = f.size > 5 * 1024 * 1024;
      nome.textContent = grande ? `${f.name}: maior que 5 MB, escolha uma imagem menor.` : `Escolhida: ${f.name}. Ela é salva quando você tocar em "Salvar popup".`;
      nome.classList.toggle("is-erro", grande);
      nome.hidden = false;
    });
  }

  $$("[data-popup-tipo]").forEach((r) => r.addEventListener("change", desenharTipo));
  raiz.addEventListener("input", (ev) => {
    if (ev.target.matches("[data-popup-titulo], [data-popup-texto]")) desenharTexto();
  });
  desenharTipo();
})();
