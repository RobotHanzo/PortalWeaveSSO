(() => {
  'use strict';

  function enhance(editor) {
    const form = editor.closest('form');
    const navigation = editor.querySelector('[data-appearance-tabs]');
    const section = form?.elements.namedItem('portalweave_sso_appearance_section');
    const items = Array.from(editor.querySelectorAll('[data-appearance-tab]')).map(tab => ({
      tab, key: tab.dataset.appearanceTab, panel: document.getElementById(tab.hash.slice(1)),
    }));
    const provider = editor.querySelector('[data-appearance-provider]');
    const preview = editor.querySelector('[data-appearance-preview-root]');
    const button = editor.querySelector('[data-appearance-preview-button]');
    const image = editor.querySelector('[data-appearance-preview-icon]');
    const mark = editor.querySelector('[data-appearance-preview-mark]');
    const label = editor.querySelector('[data-appearance-preview-label]');
    const above = editor.querySelector('[data-appearance-preview-above]');
    const note = editor.querySelector('[data-appearance-note]');
    const status = editor.querySelector('[data-appearance-status]');
    if (!form || !navigation || !section || !items.length || !provider || !preview || !button || !image || !mark || !label || !above || !note
      || items.some(item => !item.panel || !editor.contains(item.panel))) return;
    const controls = new Map(Array.from(form.elements).filter(control => control.name && control.type !== 'hidden')
      .map(control => [control.name, control]));
    const field = (group, key) => controls.get('settings[' + group + '][' + key + ']');
    const value = (group, key) => {
      const control = field(group, key);
      return control?.type === 'checkbox' ? control.checked : control?.value ?? '';
    };
    const initial = items.find(item => item.key === editor.dataset.appearanceSection) || items[0];
    let current = initial;
    let wantedIcon = '';
    let announcement;
    const iconSource = field('wp_login_button', 'icon_source');
    const iconUrl = field('wp_login_button', 'icon_url');
    const customIcon = editor.querySelector('[data-appearance-custom-icon]');
    const remove = editor.querySelector('[data-appearance-remove]');
    const media = editor.querySelector('[data-appearance-media]');
    const presets = {
      light: ['#ffffff', '#1d2327', '#c3c4c7', '#f0f6fc', '#135e96'],
      blue: ['#2271b1', '#ffffff', '#135e96', '#135e96', '#ffffff'],
      dark: ['#1d2327', '#ffffff', '#1d2327', '#2c3338', '#ffffff'],
    };
    const colors = ['background', 'text_color', 'border_color', 'hover_background', 'hover_text_color'];

    function announce(message) {
      if (!status) return;
      window.clearTimeout(announcement);
      status.textContent = '';
      announcement = window.setTimeout(() => { status.textContent = message || ''; }, 0);
    }

    function safeUrl(raw) {
      if (typeof raw !== 'string' || !raw || raw.length > 2048 || /[\u0000-\u0020\u007f\\|#]/.test(raw) || !raw.startsWith('https://')) return '';
      const authority = raw.slice(8).split(/[/?]/, 1)[0];
      if (authority.includes('@')) return '';
      try {
        const url = new URL(raw);
        return url.protocol === 'https:' && !url.username && !url.password && !url.hash ? raw : '';
      } catch { return ''; }
    }

    function color(raw) {
      return /^#[a-f\d]{3}(?:[a-f\d]{3})?$/i.test(raw) ? raw.toLowerCase() : '';
    }

    function pixels(group, key, min, max) {
      const number = Number(value(group, key));
      const fallback = Number(field(group, key)?.defaultValue) || min;
      return Math.max(min, Math.min(max, Number.isInteger(number) ? number : fallback)) + 'px';
    }

    function render() {
      const selected = provider.selectedOptions[0];
      if (!selected) return;
      const name = selected.dataset.providerName || selected.textContent;
      const wordpress = current.key === 'wordpress';
      const custom = wordpress && Boolean(value('wp_login_button', 'enabled'));
      const styled = !wordpress || custom || Boolean(value('button', 'admin_style'));
      const theme = custom ? 'custom' : value('button', 'theme');
      const shape = custom ? 'round-edge' : value('button', 'shape').replaceAll('_', '-');
      preview.className = 'portalweave-sso-login' + (custom ? ' portalweave-sso-login-screen' : '');
      button.className = styled ? 'portalweave-sso-button portalweave-sso-theme-' + theme + ' portalweave-sso-shape-' + shape
        : 'portalweave-sso-preview-unstyled';
      button.classList.toggle('portalweave-sso-shadow', styled && Boolean(value(custom ? 'wp_login_button' : 'button', 'shadow')));
      button.classList.toggle('portalweave-sso-transform', styled && !custom && Boolean(value('button', 'transform')));
      button.classList.toggle('portalweave-sso-icon-right', custom && value('wp_login_button', 'icon_position') === 'right');
      preview.style.setProperty('--portalweave-sso-size', pixels('button', 'size', 10, 48));
      preview.style.setProperty('--portalweave-sso-radius', pixels('button', 'radius', 0, 100));
      preview.style.setProperty('--portalweave-sso-spacing', pixels('button', 'spacing', 0, 100));
      const variables = { background: 'background', text_color: 'color', border_color: 'border', hover_background: 'hover-background', hover_text_color: 'hover-color' };
      Object.entries(variables).forEach(([key, variable]) => {
        const valid = color(value('wp_login_button', key));
        if (valid) preview.style.setProperty('--portalweave-sso-wp-' + variable, valid);
      });
      for (const [key, variable, min, max] of [['radius', 'radius', 0, 50], ['font_size', 'size', 10, 32], ['padding_y', 'padding-y', 8, 24], ['padding_x', 'padding-x', 8, 40], ['icon_size', 'icon-size', 12, 48]]) {
        preview.style.setProperty('--portalweave-sso-wp-' + variable, pixels('wp_login_button', key, min, max));
      }
      preview.style.setProperty('--portalweave-sso-wp-width', value('wp_login_button', 'full_width') ? '100%' : 'fit-content');
      const sharedLabel = selected.dataset.providerText || value('button', 'text').replaceAll('{app}', name);
      label.textContent = custom ? (value('wp_login_button', 'text') ? value('wp_login_button', 'text').replaceAll('{app}', name)
        : sharedLabel || note.dataset.defaultTemplate.replaceAll('{app}', name)) : sharedLabel;
      above.textContent = value('button', 'above_text');
      above.hidden = !above.textContent;
      const source = value('wp_login_button', 'icon_source');
      wantedIcon = safeUrl(custom ? (source === 'custom' ? value('wp_login_button', 'icon_url')
        : source === 'provider' ? selected.dataset.providerIcon : '') : selected.dataset.sharedIcon);
      image.hidden = !wantedIcon;
      if (wantedIcon) {
        if (image.getAttribute('src') !== wantedIcon) image.src = wantedIcon;
      } else image.removeAttribute('src');
      mark.textContent = Array.from(name)[0]?.toLocaleUpperCase() || '';
      mark.hidden = custom || Boolean(wantedIcon);
      note.textContent = !wordpress ? note.dataset.noteShared : custom ? note.dataset.noteCustom : styled ? note.dataset.noteInherit : note.dataset.noteUnstyled;
    }

    function sync() {
      const custom = iconSource?.value === 'custom';
      if (customIcon) customIcon.hidden = !custom;
      if (iconUrl) {
        // An empty inactive value still posts an explicit removal instead of restoring the stored URL.
        iconUrl.disabled = !custom && iconUrl.value !== '';
        iconUrl.required = custom && Boolean(value('wp_login_button', 'enabled'));
        iconUrl.setCustomValidity(custom && iconUrl.value && !safeUrl(iconUrl.value) ? editor.dataset.iconInvalid : '');
      }
      if (remove) remove.disabled = !iconUrl?.value || Boolean(iconUrl?.matches(':disabled'));
      if (media) media.disabled = !custom || Boolean(iconUrl?.matches(':disabled'));
      editor.querySelectorAll('[data-appearance-swatch]').forEach(swatch => {
        const valid = color(value('wp_login_button', swatch.dataset.appearanceSwatch));
        if (valid) swatch.value = valid.length === 4 ? '#' + [...valid.slice(1)].map(character => character + character).join('') : valid;
      });
      render();
    }

    function select(item, focus = false, history = '') {
      items.forEach(candidate => {
        const active = candidate === item;
        candidate.tab.setAttribute('aria-selected', String(active));
        candidate.tab.tabIndex = active ? 0 : -1;
        candidate.tab.classList.toggle('is-active', active);
        candidate.panel.hidden = !active;
      });
      current = item;
      if (history) {
        const url = new URL(window.location.href);
        url.hash = item.panel.id;
        url.searchParams.set('appearance_section', item.key);
        window.history[history === 'push' ? 'pushState' : 'replaceState'](null, '', url);
      }
      if (focus) item.tab.focus();
      render();
    }

    const fromLocation = () => items.find(item => item.panel.id === window.location.hash.slice(1))
      || items.find(item => item.key === new URL(window.location.href).searchParams.get('appearance_section')) || initial;
    navigation.setAttribute('role', 'tablist');
    items.forEach((item, index) => {
      item.tab.setAttribute('role', 'tab');
      item.tab.setAttribute('aria-controls', item.panel.id);
      item.panel.setAttribute('role', 'tabpanel');
      item.panel.setAttribute('aria-labelledby', item.tab.id);
      item.panel.tabIndex = 0;
      item.tab.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        select(item, false, 'push');
      });
      item.tab.addEventListener('keydown', event => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const direction = window.getComputedStyle(navigation).direction === 'rtl' ? -1 : 1;
        let target;
        if (event.key === 'ArrowRight') target = (index + direction + items.length) % items.length;
        else if (event.key === 'ArrowLeft') target = (index - direction + items.length) % items.length;
        else if (event.key === 'Home') target = 0;
        else if (event.key === 'End') target = items.length - 1;
        else if (event.key === ' ') target = index;
        if (target === undefined) return;
        event.preventDefault();
        select(items[target], true, 'replace');
      });
    });
    form.addEventListener('formdata', event => { event.formData.set(section.name, current.key); });
    if (!('FormDataEvent' in window)) form.addEventListener('submit', () => { section.value = current.key; });
    form.addEventListener('invalid', () => {
      const invalid = Array.from(form.elements).find(control => control.willValidate && !control.validity.valid);
      const panel = invalid?.closest('[data-appearance-panel]');
      const item = items.find(candidate => candidate.panel === panel);
      if (item) select(item, false, 'replace');
    }, true);
    editor.addEventListener('input', sync);
    editor.addEventListener('change', sync);
    form.addEventListener('portalweave-sso:reverted', () => { sync(); announce(''); });
    editor.querySelectorAll('[data-appearance-swatch]').forEach(swatch => {
      swatch.hidden = false;
      swatch.addEventListener('input', () => {
        field('wp_login_button', swatch.dataset.appearanceSwatch).value = swatch.value;
        field('wp_login_button', swatch.dataset.appearanceSwatch).dispatchEvent(new Event('input', { bubbles: true }));
        sync();
      });
    });
    editor.querySelectorAll('[data-appearance-preset]').forEach(preset => {
      preset.hidden = false;
      preset.addEventListener('click', () => {
        if (preset.matches(':disabled') || !presets[preset.dataset.appearancePreset]) return;
        colors.forEach((key, index) => { field('wp_login_button', key).value = presets[preset.dataset.appearancePreset][index]; });
        field('wp_login_button', 'enabled').checked = true;
        field('wp_login_button', 'enabled').dispatchEvent(new Event('change', { bubbles: true }));
        sync();
        announce(preset.dataset.presetMessage);
      });
    });
    if (remove && iconUrl) {
      remove.hidden = false;
      remove.addEventListener('click', () => {
        if (remove.matches(':disabled')) return;
        iconUrl.value = '';
        iconUrl.dispatchEvent(new Event('input', { bubbles: true }));
        sync();
        announce(remove.dataset.removeMessage);
      });
    }
    if (media && iconUrl && window.wp?.media) {
      media.hidden = false;
      let frame;
      media.addEventListener('click', () => {
        if (media.matches(':disabled')) return;
        if (!frame) {
          frame = window.wp.media({ title: media.dataset.mediaTitle, button: { text: media.dataset.mediaButton }, library: { type: 'image' }, multiple: false });
          frame.on('select', () => {
            const attachment = frame.state().get('selection').first()?.toJSON();
            const url = safeUrl(attachment?.url);
            if (!url) { announce(editor.dataset.iconInvalid); return; }
            iconUrl.value = url;
            iconUrl.dispatchEvent(new Event('input', { bubbles: true }));
            sync();
            announce(media.dataset.mediaMessage);
          });
        }
        frame.open();
      });
    }
    image.addEventListener('error', () => { if (wantedIcon) { image.hidden = true; announce(editor.dataset.iconError); } });
    image.addEventListener('load', () => { image.hidden = !wantedIcon; });
    window.addEventListener('hashchange', () => select(fromLocation()));
    window.addEventListener('popstate', () => select(fromLocation()));
    sync();
    select(fromLocation());
  }

  document.querySelectorAll('[data-appearance-editor]').forEach(enhance);
})();
