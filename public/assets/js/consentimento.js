/**
 * Aviso de cookies (LGPD). Grava a escolha no cookie "cardapim_consentimento" por 12 meses:
 * "todos" carrega as fontes do Google na hora; "necessarios" nao carrega nada opcional.
 * Qualquer botao com [data-consentimento-abrir] reabre o aviso para mudar a escolha.
 */
(() => {
  'use strict';

  const COOKIE = 'cardapim_consentimento';
  const UM_ANO = 60 * 60 * 24 * 365;
  const FONTES = 'https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap';

  const aviso = document.querySelector('[data-consentimento]');
  if (!aviso) {
    return;
  }
  const feito = aviso.querySelector('[data-consentimento-feito]');
  let quemAbriu = null;

  const hoje = () => {
    const d = new Date();
    return String(d.getFullYear()) + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
  };

  const gravar = (escolha) => {
    const seguro = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${COOKIE}=${escolha}.${hoje()}; Max-Age=${UM_ANO}; Path=/; SameSite=Lax${seguro}`;
  };

  const carregarFontes = () => {
    if (document.querySelector('link[data-fontes-externas]')) {
      return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = FONTES;
    link.dataset.fontesExternas = '';
    document.head.appendChild(link);
  };

  const fechar = () => {
    aviso.hidden = true;
    if (quemAbriu && document.contains(quemAbriu)) {
      quemAbriu.focus();
    }
    quemAbriu = null;
  };

  aviso.addEventListener('click', (evento) => {
    const botao = evento.target.closest('[data-consentimento-escolha]');
    if (!botao) {
      return;
    }
    const escolha = botao.dataset.consentimentoEscolha === 'todos' ? 'todos' : 'necessarios';
    gravar(escolha);
    if (escolha === 'todos') {
      carregarFontes();
    } else if (document.querySelector('link[data-fontes-externas]')) {
      // Recusou depois de ter aceitado: as fontes saem na proxima pagina.
      feito.textContent = 'Preferência salva. As fontes externas deixam de carregar a partir da próxima página.';
      window.setTimeout(fechar, 2500);
      return;
    }
    fechar();
  });

  aviso.addEventListener('keydown', (evento) => {
    if (evento.key === 'Escape' && quemAbriu) {
      fechar();
    }
  });

  document.addEventListener('click', (evento) => {
    const abrir = evento.target.closest('[data-consentimento-abrir]');
    if (!abrir) {
      return;
    }
    evento.preventDefault();
    quemAbriu = abrir;
    feito.textContent = '';
    aviso.hidden = false;
    aviso.querySelector('[data-consentimento-escolha="necessarios"]').focus();
  });
})();
