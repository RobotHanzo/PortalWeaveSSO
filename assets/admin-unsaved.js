(() => {
  'use strict';

  function enhance(root) {
    root.querySelectorAll('form.portalweave-sso-settings-form').forEach(form => {
      const submit = form.querySelector('[data-setup-submit], .portalweave-sso-save button[type="submit"]');
      if (!submit || submit.matches(':disabled')) return;
      // Settings are authoritative. Preview, navigation and unnamed GUI controls
      // must never turn an unchanged settings form into an unsaved draft.
      const controls = () => Array.from(form.elements).filter(control => control.name?.startsWith('settings[')
        && !['button', 'submit', 'reset', 'fieldset'].includes(control.type));
      const value = control => control.type === 'checkbox' || control.type === 'radio' ? control.checked
        : control.multiple ? Array.from(control.options).filter(option => option.selected).map(option => option.value)
          : control.value;
      const initial = new Map(controls().map(control => [control, value(control)]));
      const snapshot = () => JSON.stringify(controls().map(control => [control.name, value(control)]));
      const original = snapshot();
      let dirty = false;
      let submitting = false;

      const banner = document.createElement('div');
      banner.className = 'portalweave-sso-unsaved';
      banner.setAttribute('role', 'region');
      banner.setAttribute('aria-label', root.dataset.unsavedTitle);
      banner.setAttribute('aria-hidden', 'true');
      banner.inert = true;
      const message = document.createElement('p');
      message.setAttribute('role', 'status');
      message.setAttribute('aria-live', 'polite');
      message.setAttribute('aria-atomic', 'true');
      const actions = document.createElement('div');
      actions.className = 'portalweave-sso-unsaved-actions';
      const revert = document.createElement('button');
      revert.type = 'button';
      revert.className = 'button portalweave-sso-unsaved-revert';
      revert.textContent = root.dataset.unsavedRevert;
      const save = document.createElement('button');
      save.type = 'button';
      save.className = 'button button-primary';
      save.textContent = root.dataset.unsavedSave;
      actions.append(revert, save);
      banner.append(message, actions);
      root.append(banner);

      function refresh() {
        dirty = snapshot() !== original;
        const visible = dirty && !submitting;
        if (!visible && banner.contains(document.activeElement)) {
          // Keep focus on the page when Revert removes the floating actions.
          const target = controls().find(control => !control.matches(':disabled') && control.getClientRects().length);
          if (target) target.focus();
        }
        banner.classList.toggle('is-visible', visible);
        banner.setAttribute('aria-hidden', String(!visible));
        banner.inert = !visible;
        message.textContent = visible ? root.dataset.unsavedMessage : '';
        root.classList.toggle('has-unsaved-changes', visible);
      }

      form.addEventListener('input', refresh);
      form.addEventListener('change', refresh);
      revert.addEventListener('click', () => {
        if (submitting) return;
        form.reset();
        initial.forEach((saved, control) => {
          if (control.type === 'checkbox' || control.type === 'radio') control.checked = saved;
          else if (control.multiple) Array.from(control.options).forEach(option => { option.selected = saved.includes(option.value); });
          else control.value = saved;
          control.setCustomValidity?.('');
        });
        // Mapping editors and media previews rebuild from their restored,
        // authoritative fields. This event contains no configuration or secrets.
        form.dispatchEvent(new Event('portalweave-sso:reverted', { bubbles: true }));
        refresh();
      });
      save.addEventListener('click', () => {
        if (submitting) return;
        // Guided setup validates/reveals all steps before its final submission.
        const request = new Event('portalweave-sso:save-requested', { cancelable: true });
        if (form.dispatchEvent(request)) form.requestSubmit(submit);
      });
      // Run after form validation and the shared pending-state listener.
      root.addEventListener('submit', event => {
        if (event.target !== form || event.defaultPrevented
          || event.submitter?.matches('[data-setup-test]')) return;
        submitting = true;
        refresh();
      });
      window.addEventListener('beforeunload', event => {
        if (!submitting && snapshot() !== original) {
          event.preventDefault();
          event.returnValue = '';
        }
      });
      window.addEventListener('pageshow', () => { submitting = false; refresh(); });
      refresh();
    });
  }

  function start() {
    // Other local editors finish their initial enhancement before the baseline.
    window.setTimeout(() => document.querySelectorAll('.portalweave-sso-admin').forEach(enhance), 0);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
