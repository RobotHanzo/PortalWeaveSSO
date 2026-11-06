(() => {
  'use strict';
  const popups = new Map();
  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[data-portalweave-sso-popup]');
    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const origin = new URL(link.href, location.href).origin;
    const popup = window.open(link.href, 'portalweave-sso-login', 'popup,width=560,height=740');
    if (!popup) return;
    event.preventDefault();
    popups.set(popup, origin);
    popup.focus();
  });
  window.addEventListener('message', (event) => {
    if (!popups.has(event.source) || popups.get(event.source) !== event.origin) return;
    if (!event.data || event.data.type !== 'portalweave-sso-complete' || typeof event.data.redirect !== 'string') return;
    const destination = new URL(event.data.redirect, location.href);
    if (!['https:', 'http:'].includes(destination.protocol) || destination.username || destination.password) return;
    popups.delete(event.source);
    location.assign(destination.href);
  });
})();
