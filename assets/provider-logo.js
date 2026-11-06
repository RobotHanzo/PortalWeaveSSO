(() => {
  'use strict';
  function safeUrl(raw) {
    if (typeof raw !== 'string' || !raw || raw.length > 2048 || !raw.startsWith('https://') || /[\u0000-\u0020\u007f\\|#]/.test(raw)) return '';
    const authority = raw.slice(8).split(/[/?]/, 1)[0];
    if (authority.includes('@')) return '';
    try {
      const url = new URL(raw);
      return url.protocol === 'https:' && url.hostname && !url.username && !url.password && !url.hash ? raw : '';
    } catch { return ''; }
  }
  if (typeof module === 'object' && module.exports) module.exports = { safeUrl };
  if (typeof document === 'undefined') return;

  document.querySelectorAll('[data-provider-logo]').forEach(editor => {
    const input = editor.querySelector('input[type="url"]');
    const image = editor.querySelector('[data-logo-image]');
    const preview = editor.querySelector('[data-logo-preview]');
    const status = editor.querySelector('[data-logo-status]');
    const media = editor.querySelector('[data-logo-media]');
    const remove = editor.querySelector('[data-logo-remove]');
    if (!input || !image || !preview || !status || !remove) return;
    let wanted = '';

    function render() {
      wanted = safeUrl(input.value);
      input.setCustomValidity(input.value && !wanted ? editor.dataset.logoInvalid : '');
      remove.disabled = !input.value || input.matches(':disabled');
      if (media) media.disabled = input.matches(':disabled');
      preview.hidden = !wanted;
      image.hidden = !wanted;
      if (wanted) {
        if (image.getAttribute('src') !== wanted) image.src = wanted;
      } else image.removeAttribute('src');
    }

    function change(url, message) {
      input.value = url;
      render();
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      status.textContent = message;
    }

    input.addEventListener('input', () => { status.textContent = ''; render(); });
    input.addEventListener('change', render);
    remove.hidden = false;
    remove.addEventListener('click', () => {
      if (remove.matches(':disabled')) return;
      change('', editor.dataset.logoRemoved);
      input.focus();
    });
    if (media && window.wp?.media) {
      media.hidden = false;
      let frame;
      media.addEventListener('click', () => {
        if (media.matches(':disabled')) return;
        if (!frame) {
          frame = window.wp.media({ title: media.dataset.mediaTitle, button: { text: media.dataset.mediaButton }, library: { type: 'image' }, multiple: false });
          frame.on('select', () => {
            const attachment = frame.state().get('selection').first()?.toJSON();
            const url = safeUrl(attachment?.url);
            if (!url || (typeof attachment?.mime === 'string' && !attachment.mime.startsWith('image/'))) {
              status.textContent = editor.dataset.logoInvalid;
              return;
            }
            change(url, editor.dataset.logoSelected);
          });
        }
        frame.open();
      });
    }
    image.addEventListener('error', () => {
      if (!wanted) return;
      image.hidden = true;
      status.textContent = editor.dataset.logoError;
    });
    image.addEventListener('load', () => { image.hidden = !wanted; });
    function reset() {
      queueMicrotask(() => { status.textContent = ''; render(); });
    }
    input.form?.addEventListener('reset', reset);
    input.form?.addEventListener('portalweave-sso:reverted', reset);
    render();
  });
})();
