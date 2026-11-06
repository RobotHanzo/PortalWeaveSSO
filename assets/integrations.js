(() => {
  'use strict';

  document.querySelectorAll('[data-integrations-editor]').forEach(editor => {
    const form = editor.closest('form');
    const navigation = editor.querySelector('[data-integration-tabs]');
    const context = form?.elements.namedItem('portalweave_sso_integration_section');
    const items = Array.from(editor.querySelectorAll('[data-integration-tab]')).map(tab => ({
      tab, key: tab.dataset.integrationTab, panel: document.getElementById(tab.hash.slice(1)),
    }));
    if (!form || !navigation || !context || !items.length
      || items.some(item => !item.panel || !editor.contains(item.panel) || item.panel.dataset.integrationPanel !== item.key)) return;

    const initial = items.find(item => item.key === editor.dataset.integrationSection) || items[0];
    let current = initial;
    const fromLocation = () => items.find(item => item.panel.id === window.location.hash.slice(1)) || initial;

    function select(item, focus = false, history = '') {
      items.forEach(candidate => {
        const active = candidate === item;
        candidate.tab.setAttribute('aria-selected', String(active));
        candidate.tab.tabIndex = active ? 0 : -1;
        candidate.tab.classList.toggle('is-active', active);
        candidate.panel.hidden = !active;
      });
      current = item;
      if (history && window.location.hash !== '#' + item.panel.id) {
        const url = new URL(window.location.href);
        url.hash = item.panel.id;
        window.history[history === 'push' ? 'pushState' : 'replaceState'](null, '', url);
      }
      if (focus) item.tab.focus();
    }

    function refreshStatus() {
      items.forEach(item => {
        const checkbox = item.panel.querySelector('input[type="checkbox"]');
        const status = item.tab.querySelector('[data-integration-status]');
        if (!checkbox || !status) return;
        status.textContent = checkbox.checked ? editor.dataset.enabledLabel : editor.dataset.disabledLabel;
        status.classList.toggle('is-enabled', checkbox.checked);
      });
    }

    navigation.setAttribute('role', 'tablist');
    navigation.setAttribute('aria-orientation', 'vertical');
    editor.classList.add('is-enhanced');
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
        let next;
        if (event.key === 'ArrowDown') next = (index + 1) % items.length;
        else if (event.key === 'ArrowUp') next = (index - 1 + items.length) % items.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = items.length - 1;
        else if (event.key === ' ') next = index;
        if (next === undefined) return;
        event.preventDefault();
        select(items[next], true, 'replace');
      });
    });
    form.addEventListener('invalid', () => {
      const invalid = Array.from(form.elements).find(control => control.willValidate && !control.validity.valid);
      const panel = invalid?.closest('[data-integration-panel]');
      const item = items.find(candidate => candidate.panel === panel);
      if (item) select(item, false, 'replace');
    }, true);
    // Keep panel navigation out of the dirty settings snapshot and the stored configuration.
    form.addEventListener('formdata', event => event.formData.set(context.name, current.key));
    if (!('FormDataEvent' in window)) form.addEventListener('submit', () => { context.value = current.key; });
    form.addEventListener('change', refreshStatus);
    form.addEventListener('reset', () => queueMicrotask(refreshStatus));
    form.addEventListener('portalweave-sso:reverted', refreshStatus);
    window.addEventListener('hashchange', () => select(fromLocation()));
    window.addEventListener('popstate', () => select(fromLocation()));
    window.addEventListener('pageshow', refreshStatus);
    select(fromLocation());
    refreshStatus();
  });
})();
