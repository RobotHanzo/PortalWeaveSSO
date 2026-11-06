(() => {
  'use strict';
  const presets = {
    entra: { base: 'https://login.microsoftonline.com/YOUR_TENANT_ID/v2.0', authorize_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/oauth2/v2.0/authorize', token_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/oauth2/v2.0/token', userinfo_url: 'https://graph.microsoft.com/oidc/userinfo', jwks_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/discovery/v2.0/keys', logout_url: 'https://login.microsoftonline.com/YOUR_TENANT_ID/oauth2/v2.0/logout' },
    b2c: { base: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT_ID/v2.0/', authorize_url: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT.onmicrosoft.com/YOUR_POLICY/oauth2/v2.0/authorize', token_url: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT.onmicrosoft.com/YOUR_POLICY/oauth2/v2.0/token', jwks_url: 'https://YOUR_TENANT.b2clogin.com/YOUR_TENANT.onmicrosoft.com/YOUR_POLICY/discovery/v2.0/keys' },
    cognito: { base: 'https://cognito-idp.YOUR_REGION.amazonaws.com/YOUR_POOL_ID', authorize_url: 'https://YOUR_DOMAIN.auth.YOUR_REGION.amazoncognito.com/oauth2/authorize', token_url: 'https://YOUR_DOMAIN.auth.YOUR_REGION.amazoncognito.com/oauth2/token', userinfo_url: 'https://YOUR_DOMAIN.auth.YOUR_REGION.amazoncognito.com/oauth2/userInfo', jwks_url: 'https://cognito-idp.YOUR_REGION.amazonaws.com/YOUR_POOL_ID/.well-known/jwks.json' },
    keycloak: { base: 'https://YOUR_HOST/realms/YOUR_REALM', authorize_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/auth', token_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/token', userinfo_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/userinfo', jwks_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/certs', logout_url: 'https://YOUR_HOST/realms/YOUR_REALM/protocol/openid-connect/logout' },
    okta: { base: 'https://YOUR_ORG.okta.com/oauth2/default', authorize_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/authorize', token_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/token', userinfo_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/userinfo', jwks_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/keys', logout_url: 'https://YOUR_ORG.okta.com/oauth2/default/v1/logout' },
    salesforce: { base: 'https://login.salesforce.com', authorize_url: 'https://login.salesforce.com/services/oauth2/authorize', token_url: 'https://login.salesforce.com/services/oauth2/token', userinfo_url: 'https://login.salesforce.com/services/oauth2/userinfo', jwks_url: 'https://login.salesforce.com/id/keys' },
    google: { base: 'https://accounts.google.com', authorize_url: 'https://accounts.google.com/o/oauth2/v2/auth', token_url: 'https://oauth2.googleapis.com/token', userinfo_url: 'https://openidconnect.googleapis.com/v1/userinfo', jwks_url: 'https://www.googleapis.com/oauth2/v3/certs' },
    auth0: { base: 'https://YOUR_TENANT.auth0.com/', authorize_url: 'https://YOUR_TENANT.auth0.com/authorize', token_url: 'https://YOUR_TENANT.auth0.com/oauth/token', userinfo_url: 'https://YOUR_TENANT.auth0.com/userinfo', jwks_url: 'https://YOUR_TENANT.auth0.com/.well-known/jwks.json', logout_url: 'https://YOUR_TENANT.auth0.com/oidc/logout' },
    onelogin: { base: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2', authorize_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/auth', token_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/token', userinfo_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/me', jwks_url: 'https://YOUR_SUBDOMAIN.onelogin.com/oidc/2/certs' },
    discord: { protocol: 'oauth2', scope: 'identify email', authorize_url: 'https://discord.com/oauth2/authorize', token_url: 'https://discord.com/api/oauth2/token', userinfo_url: 'https://discord.com/api/users/@me', subject_attribute: 'id', mapping: { username: 'username', email: 'email', first_name: '', last_name: '', display_name: 'global_name', groups: 'groups' }, verified_attribute: 'verified' },
    classlink: { protocol: 'oauth2', scope: 'profile', authorize_url: 'https://launchpad.classlink.com/oauth2/v2/auth', token_url: 'https://launchpad.classlink.com/oauth2/v2/token', userinfo_url: 'https://nodeapi.classlink.com/v2/my/info', subject_attribute: 'SourcedId' },
    authentik: { base: 'https://YOUR_HOST/application/o/YOUR_APPLICATION/', authorize_url: 'https://YOUR_HOST/application/o/authorize/', token_url: 'https://YOUR_HOST/application/o/token/', userinfo_url: 'https://YOUR_HOST/application/o/userinfo/', jwks_url: 'https://YOUR_HOST/application/o/YOUR_APPLICATION/jwks/', logout_url: 'https://YOUR_HOST/application/o/YOUR_APPLICATION/end-session/' }
  };
  presets.office365 = presets.entra;
  document.querySelectorAll('.portalweave-sso-preset').forEach(select => {
    select.addEventListener('change', () => {
      const preset = presets[select.value];
      if (!preset) return;
      const provider = select.closest('.portalweave-sso-provider');
      if (!provider) return;
      const defaults = { protocol: 'oidc', scope: 'openid profile email', issuer: preset.base || '', ...preset };
      Object.entries(defaults).forEach(([key, value]) => {
        if (key === 'base') return;
        const field = provider.querySelector(`[name$="[${key}]"]`);
        if (!field) return;
        // Changing providers is explicit; credentials and existing endpoint values are preserved.
        if (!field.value || key === 'protocol' || key === 'scope' || key === 'subject_attribute' || key === 'verified_attribute' || key === 'mapping') {
          field.value = typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
          field.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    });
  });

  function enhanceProviderEditor(editor) {
    const form = editor.closest('form');
    const navigation = editor.querySelector('[data-provider-tabs]');
    const section = form?.elements.namedItem('portalweave_sso_provider_section');
    const items = Array.from(editor.querySelectorAll('[data-provider-tab]')).map(tab => ({
      tab, key: tab.dataset.providerTab, panel: document.getElementById(tab.hash.slice(1)),
    }));
    if (!form || !navigation || !section || !items.length
      || items.some(item => !item.panel || !editor.contains(item.panel) || item.panel.dataset.providerPanel !== item.key)) return;
    const initial = items.find(item => item.key === editor.dataset.providerSection) || items[0];
    const fromLocation = () => items.find(item => item.panel.id === window.location.hash.slice(1)) || initial;
    let current = initial;

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
        let next;
        const direction = window.getComputedStyle(navigation).direction === 'rtl' ? -1 : 1;
        if (event.key === 'ArrowRight') next = (index + direction + items.length) % items.length;
        else if (event.key === 'ArrowLeft') next = (index - direction + items.length) % items.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = items.length - 1;
        else if (event.key === ' ') next = index;
        if (next === undefined) return;
        event.preventDefault();
        select(items[next], true, 'replace');
      });
    });
    // Native validation must reveal the first invalid field before focusing it.
    form.addEventListener('invalid', () => {
      const invalid = Array.from(form.elements).find(control => control.willValidate && !control.validity.valid);
      const panel = invalid?.closest('[data-provider-panel]');
      const item = items.find(candidate => candidate.panel === panel);
      if (item) select(item, false, 'replace');
    }, true);
    // Navigation alone must not dirty the settings form's original field values.
    form.addEventListener('formdata', event => {
      event.formData.set(section.name, current.key);
    });
    if (!('FormDataEvent' in window)) {
      form.addEventListener('submit', () => { section.value = current.key; });
    }
    window.addEventListener('hashchange', () => select(fromLocation()));
    window.addEventListener('popstate', () => select(fromLocation()));
    select(fromLocation());
  }

  document.querySelectorAll('.portalweave-sso-admin').forEach(root => {
    root.querySelectorAll('[data-provider-editor]').forEach(enhanceProviderEditor);
    const forms = new Map();
    root.querySelectorAll('form[data-pending-label][data-pending-message]').forEach(form => {
      const status = document.createElement('p');
      status.className = 'portalweave-sso-action-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.setAttribute('aria-atomic', 'true');
      const actions = form.querySelector('.portalweave-sso-save, [data-setup-navigation]');
      if (actions) actions.after(status);
      else form.append(status);
      forms.set(form, { status, pending: false, buttons: [] });
    });

    // Bubble after the wizard's form listener has validated every required step.
    root.addEventListener('submit', event => {
      const form = event.target;
      const state = forms.get(form);
      if (!state) return;
      if (state.pending) {
        event.preventDefault();
        return;
      }
      // Draft connection tests post to a new tab and leave this Save form reusable.
      if (form.matches('[data-setup-form]') && event.submitter?.matches('[data-setup-test]')) return;
      if (event.defaultPrevented) return;
      state.pending = true;
      state.buttons = Array.from(form.querySelectorAll('button[type="submit"]')).map(button => ({
        button, label: button.textContent,
        busy: button.getAttribute('aria-busy'), disabled: button.getAttribute('aria-disabled'),
      }));
      state.buttons.forEach(({ button }) => {
        // Keep submitters and all settings/credential fields in the POST payload.
        button.setAttribute('aria-busy', 'true');
        button.setAttribute('aria-disabled', 'true');
        button.textContent = form.dataset.pendingLabel;
      });
      state.status.textContent = form.dataset.pendingMessage;
    });

    window.addEventListener('pageshow', () => {
      forms.forEach(state => {
        state.pending = false;
        state.status.textContent = '';
        state.buttons.forEach(({ button, label, busy, disabled }) => {
          button.textContent = label;
          if (busy === null) button.removeAttribute('aria-busy');
          else button.setAttribute('aria-busy', busy);
          if (disabled === null) button.removeAttribute('aria-disabled');
          else button.setAttribute('aria-disabled', disabled);
        });
        state.buttons = [];
      });
    });
  });
})();
