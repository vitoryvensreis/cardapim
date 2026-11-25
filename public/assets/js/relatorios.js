/* Aprimoramento progressivo: sem JS o formulário continua funcional. */
(() => {
  const form = document.querySelector('.rel-filtros');
  if (!form) return;
  const periodo = form.querySelector('[name="periodo"]');
  const datas = form.querySelector('.rel-datas');
  if (!periodo || !datas) return;
  const atualizar = () => { datas.hidden = periodo.value !== 'personalizado'; };
  periodo.addEventListener('change', atualizar);
  atualizar();
})();
