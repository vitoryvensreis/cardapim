/* Tags institucionais: Consent Mode v2, escopos explicitos e recusa persistente. */
(() => {
  'use strict';
  const origem = document.currentScript;
  if (!origem) return;
  let tags;
  try { tags = JSON.parse(origem.dataset.rastreamento); } catch { return; }
  if (!tags || typeof tags !== 'object') return;

  const campanha = new Set(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'fbclid']);
  const urlSegura = (u) => [...u.searchParams].every(([k, v]) =>
    campanha.has(k) || (k === '_dbg' && /^(1|true)$/.test(v)) ||
    (k === 'gtm_debug' && /^(x|\d{1,20})$/.test(v)) ||
    (k === 'gtm_preview' && /^env-\d{1,10}$/.test(v)));
  const url = new URL(window.location.href);
  if (!urlSegura(url) || (url.pathname !== '/' && url.pathname !== '/catalogo')) return;
  // Nao expor caminhos privados, consultas de busca, convites ou tokens no referrer.
  if (document.referrer) {
    try {
      const anterior = new URL(document.referrer);
      const publicas = ['/', '/catalogo', '/cookies', '/privacidade', '/termos-de-uso', '/exclusao-de-dados', '/tratamento-de-dados'];
      if (anterior.origin === url.origin && (!publicas.includes(anterior.pathname) || !urlSegura(anterior))) return;
    } catch { return; }
  }
  if (window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone) return;

  const gtm = typeof tags.gtm === 'string' && /^GTM-[A-Z0-9]{4,20}$/.test(tags.gtm) ? tags.gtm : null;
  const ga4 = !gtm && typeof tags.ga4 === 'string' && /^G-[A-Z0-9]{4,20}$/.test(tags.ga4) ? tags.ga4 : null;
  const meta = !gtm && typeof tags.meta === 'string' && /^[0-9]{5,25}$/.test(tags.meta) ? tags.meta : null;
  const avancado = tags.google_advanced === true && Boolean(gtm || ga4);
  const escolha = () => {
    const valor = document.cookie.split(';').map(v => v.trim()).find(v => /^cardapim_consentimento=(todos|necessarios)\.v2\.\d{8}$/.test(v));
    return valor ? valor.split('=')[1].split('.')[0] : null;
  };
  const permissoes = (estado) => ({
    analytics_storage: estado, ad_storage: estado, ad_user_data: estado, ad_personalization: estado
  });

  let googleIniciado = false;
  let metaIniciado = false;
  let modoRestrito = false;
  let recarregando = false;
  let anterior = escolha();

  const carregar = (src) => {
    const script = document.createElement('script');
    script.async = true;
    script.src = src;
    script.dataset.rastreamentoExterno = '';
    document.head.appendChild(script);
  };

  // Ordem importante: consentimento inicial e atualizacao antes de qualquer biblioteca.
  const iniciarGoogle = (aceito) => {
    if (googleIniciado || (!gtm && !ga4)) return;
    googleIniciado = true;
    modoRestrito = !aceito;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('consent', 'default', permissoes('denied'));
    window.gtag('set', 'ads_data_redaction', true);
    window.gtag('set', 'url_passthrough', false);
    if (aceito) window.gtag('consent', 'update', permissoes('granted'));
    if (gtm) {
      if (!aceito) {
        // So a biblioteca do container: lista restrita a variavel oficial de debug,
        // sem tags, HTML personalizado ou templates de terceiros antes do aceite.
        window.dataLayer.push({
          'gtm.allowlist': ['dbg'],
          'gtm.blocklist': ['customScripts', 'customPixels', 'nonGoogleScripts', 'nonGooglePixels', 'nonGoogleIframes', 'sandboxedScripts']
        });
      }
      window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
      carregar('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(gtm));
      return;
    }
    window.gtag('js', new Date());
    window.gtag('config', ga4, { allow_google_signals: false, allow_ad_personalization_signals: false });
    carregar('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga4));
  };

  const iniciarMeta = () => {
    if (!meta || metaIniciado) return;
    metaIniciado = true;
    if (!window.fbq) {
      const fbq = function () { if (fbq.callMethod) fbq.callMethod.apply(fbq, arguments); else fbq.queue.push(arguments); };
      fbq.push = fbq;
      fbq.loaded = true;
      fbq.version = '2.0';
      fbq.queue = [];
      window.fbq = fbq;
      window._fbq = fbq;
    }
    window.fbq('consent', 'grant');
    window.fbq('set', 'autoConfig', false, meta);
    window.fbq('init', meta);
    window.fbq('track', 'PageView');
    carregar('https://connect.facebook.net/en_US/fbevents.js');
  };

  const limparCookies = () => {
    // Apenas cookies de marketing conhecidos, no dominio atual. Nunca sessao ou sacola.
    const nomes = document.cookie.split(';').map(v => v.trim().split('=')[0])
      .filter(n => /^(_ga(?:_[A-Za-z0-9_]+)?|_gid|_gat(?:_[A-Za-z0-9_]+)?|_gcl_[A-Za-z0-9_]+|_fbp|_fbc)$/.test(n));
    for (const nome of nomes) {
      document.cookie = nome + '=; Max-Age=0; Path=/; SameSite=Lax';
      document.cookie = nome + '=; Max-Age=0; Path=/; Domain=' + url.hostname + '; SameSite=Lax';
    }
  };

  const recarregar = () => {
    if (recarregando) return;
    recarregando = true;
    window.location.reload();
  };

  const sincronizar = () => {
    if (recarregando) return;
    const atual = escolha();
    if (atual === 'todos') {
      // Um GTM pre-carregado tem allowlist restrita; recarregar para aplicar
      // o aceite sem reexecutar o mesmo container no documento atual.
      if (gtm && googleIniciado && modoRestrito) { recarregar(); return; }
      if (googleIniciado && modoRestrito) {
        window.gtag('consent', 'update', permissoes('granted'));
        modoRestrito = false;
      } else {
        iniciarGoogle(true);
      }
      iniciarMeta();
    } else if (atual === 'necessarios' || anterior === 'todos') {
      if (googleIniciado) {
        if (ga4) window['ga-disable-' + ga4] = true;
        window.gtag('consent', 'update', permissoes('denied'));
      }
      if (metaIniciado && typeof window.fbq === 'function') window.fbq('consent', 'revoke');
      if (atual === 'necessarios' || anterior === 'todos') limparCookies();
      if (googleIniciado || metaIniciado) { recarregar(); return; }
    } else if (avancado) {
      iniciarGoogle(false);
    }
    anterior = atual;
  };

  document.addEventListener('cardapim:consentimento', sincronizar);
  window.addEventListener('pageshow', sincronizar);
  window.addEventListener('focus', sincronizar);
  window.addEventListener('storage', (e) => { if (e.key === 'cardapim:consentimento') sincronizar(); });
  sincronizar();
})();
