/* Tags institucionais. Nenhuma requisicao a provedores antes do consentimento v2. */
(() => {
  'use strict';
  const origem = document.currentScript;
  if (!origem) return;
  let tags;
  try { tags = JSON.parse(origem.dataset.rastreamento); } catch { return; }
  if (!tags || typeof tags !== 'object') return;

  // Nao enviar buscas do catalogo, convites, tokens ou outros parametros de paginas.
  const permitidos = new Set(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'fbclid']);
  const url = new URL(window.location.href);
  if ([...url.searchParams.keys()].some(k => !permitidos.has(k))) return;
  if (url.pathname !== '/' && url.pathname !== '/catalogo') return;
  if (document.referrer) {
    try {
      const anterior = new URL(document.referrer);
      if (anterior.origin === url.origin && (anterior.pathname !== '/' && anterior.pathname !== '/catalogo' || [...anterior.searchParams.keys()].some(k => !permitidos.has(k)))) return;
    } catch { return; }
  }
  // O app instalado redireciona a landing ao login.
  if (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone) return;

  let iniciado = false;
  const aceitou = () => document.cookie.split(';').some(v => /^cardapim_consentimento=todos\.v2\.\d{8}$/.test(v.trim()));
  const carregar = (src) => {
    const script = document.createElement('script');
    script.async = true;
    script.src = src;
    document.head.appendChild(script);
  };

  const iniciar = () => {
    if (iniciado || !aceitou()) return;
    iniciado = true;
    // GTM exclusivo por pagina (tambem validado no servidor).
    if (typeof tags.gtm === 'string' && /^GTM-[A-Z0-9]{4,20}$/.test(tags.gtm)) {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
      carregar('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(tags.gtm));
      return;
    }
    if (typeof tags.ga4 === 'string' && /^G-[A-Z0-9]{4,20}$/.test(tags.ga4)) {
      window.dataLayer = window.dataLayer || [];
      window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
      window.gtag('config', tags.ga4, { allow_google_signals: false, allow_ad_personalization_signals: false });
      carregar('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(tags.ga4));
    }
    if (typeof tags.meta === 'string' && /^[0-9]{5,25}$/.test(tags.meta)) {
      if (!window.fbq) {
        const fbq = function () { if (fbq.callMethod) fbq.callMethod.apply(fbq, arguments); else fbq.queue.push(arguments); };
        fbq.push = fbq;
        fbq.loaded = true;
        fbq.version = '2.0';
        fbq.queue = [];
        window.fbq = fbq;
        window._fbq = fbq;
      }
      window.fbq('set', 'autoConfig', false, tags.meta);
      window.fbq('init', tags.meta);
      window.fbq('track', 'PageView');
      carregar('https://connect.facebook.net/en_US/fbevents.js');
    }
  };

  const sincronizar = () => {
    if (aceitou()) { iniciar(); return; }
    if (!iniciado) return;
    if (typeof tags.ga4 === 'string') window['ga-disable-' + tags.ga4] = true;
    if (typeof window.fbq === 'function') window.fbq('consent', 'revoke');
    // Um script externo ja carregado nao pode ser descarregado com seguranca.
    window.location.reload();
  };
  document.addEventListener('cardapim:consentimento', sincronizar);
  window.addEventListener('pageshow', sincronizar);
  window.addEventListener('focus', sincronizar);
  window.addEventListener('storage', (e) => { if (e.key === 'cardapim:consentimento') sincronizar(); });
  iniciar();
})();
