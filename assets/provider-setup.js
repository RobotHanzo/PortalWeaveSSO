(() => {
  'use strict';

  function enhance(root) {
    const form = root.querySelector('[data-setup-form]');
    const steps = Array.from(root.querySelectorAll('[data-setup-step]'));
    const navigation = root.querySelector('[data-setup-navigation]');
    const back = root.querySelector('[data-setup-back]');
    const next = root.querySelector('[data-setup-next]');
    const submit = root.querySelector('[data-setup-submit]');
    if (!form || !steps.length || !navigation || !back || !next || !submit) return;

    const progress = Array.from(root.querySelectorAll('[data-step-target]'));
    const status = root.querySelector('[data-setup-status]');
    const controls = Array.from(form.elements);
    const originalDisabled = new Map(controls.map(control => [control, control.disabled]));
    const originalRequired = new Map(controls.map(control => [control, control.required]));
    let current = 0;
    let visited = 0;
    let fields;
    try {
      fields = Array.from(form.querySelectorAll('[data-setup-field]')).map(field => ({
        element: field,
        conditions: JSON.parse(field.dataset.setupWhen || '{}'),
      }));
      if (fields.some(field => !field.conditions || typeof field.conditions !== 'object' || Array.isArray(field.conditions))) return;
    } catch {
      // Leave the complete server-rendered form available if enhancement fails.
      return;
    }

    function named(key) {
      return form.elements.namedItem('settings[provider_setup][' + key + ']');
    }

    function value(key) {
      const control = named(key);
      if (!control) return '';
      if (control.type === 'checkbox') return control.checked ? control.value : '';
      return typeof control.value === 'string' ? control.value : '';
    }

    function announce(message) {
      if (status) status.textContent = message || '';
    }

    function review() {
      root.querySelectorAll('[data-setup-review]').forEach(entry => {
        const key = entry.dataset.setupReview;
        const control = named(key);
        if (key === 'client_id' || /(?:^|_)(?:secret|password|token)$/.test(key)) {
          const label = !control || control.disabled ? entry.dataset.reviewNotRequired
            : value(key) ? entry.dataset.reviewEntered : entry.dataset.reviewEmpty;
          // Credentials are represented only by translated status labels.
          if (label !== undefined) entry.textContent = label;
        } else if (key !== 'provider' && control) {
          const label = control.selectedOptions?.[0]?.textContent;
          entry.textContent = control.disabled ? ''
            : (label || value(key)).trim() || entry.dataset.reviewDefault || '';
        }
      });
      root.querySelectorAll('[data-setup-review-field]').forEach(row => {
        const control = named(row.dataset.setupReviewField);
        row.hidden = Boolean(control?.disabled);
      });
    }

    function syncFields() {
      fields.forEach(field => {
        const visible = Object.entries(field.conditions).every(([key, expected]) => {
          const actual = value(key);
          return Array.isArray(expected) ? expected.map(String).includes(actual) : String(expected) === actual;
        });
        field.element.hidden = !visible;
        field.element.querySelectorAll('input, select, textarea, button').forEach(control => {
          control.disabled = originalDisabled.get(control) || !visible;
          if ('required' in control) {
            control.required = visible && !control.disabled && (originalRequired.get(control)
              || control.dataset.setupRequired === '1' || field.element.dataset.setupRequired === '1');
          }
        });
      });
      review();
    }

    const secretToggles = Array.from(root.querySelectorAll('[data-setup-toggle-secret]')).map(button => {
      const input = document.getElementById(button.getAttribute('aria-controls'));
      if (!input || !root.contains(input) || input.type !== 'password') return null;
      const show = button.dataset.showLabel || button.textContent;
      const hide = button.dataset.hideLabel || show;
      button.hidden = false;
      button.setAttribute('aria-pressed', 'false');
      button.addEventListener('click', () => {
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? hide : show;
        button.setAttribute('aria-pressed', String(reveal));
      });
      return { button, input, show };
    }).filter(Boolean);

    function showStep(index, focus = true) {
      current = index;
      visited = Math.max(visited, current);
      steps.forEach((step, position) => { step.hidden = position !== current; });
      progress.forEach(button => {
        const position = steps.findIndex(step => step.dataset.setupStep === button.dataset.stepTarget);
        button.disabled = position < 0 || position > visited;
        if (position === current) button.setAttribute('aria-current', 'step');
        else button.removeAttribute('aria-current');
        const item = button.closest('li');
        if (item) {
          item.classList.toggle('is-current', position === current);
          item.classList.toggle('is-visited', position >= 0 && position < visited);
          item.removeAttribute('aria-current');
        }
      });
      const final = current === steps.length - 1;
      back.hidden = current === 0;
      next.hidden = final;
      submit.hidden = !final;
      secretToggles.forEach(({ button, input, show }) => {
        input.type = 'password';
        button.textContent = show;
        button.setAttribute('aria-pressed', 'false');
      });
      review();
      if (focus) {
        const label = steps[current].getAttribute('aria-labelledby');
        const heading = label ? document.getElementById(label) : steps[current].querySelector('h2, h3');
        if (heading && root.contains(heading)) heading.focus();
        announce(steps[current].dataset.setupStepLabel || heading?.textContent || '');
      }
    }

    function validateStep(index) {
      const invalid = Array.from(steps[index].querySelectorAll('input, select, textarea'))
        .find(control => !control.disabled && control.willValidate && !control.checkValidity());
      if (!invalid) return true;
      showStep(index);
      invalid.focus();
      invalid.reportValidity();
      return false;
    }

    function goTo(index) {
      syncFields();
      if (index < 0 || index >= steps.length) return;
      if (index > current) {
        for (let position = current; position < index; position++) {
          if (!validateStep(position)) return;
        }
      }
      showStep(index);
    }

    back.addEventListener('click', () => goTo(current - 1));
    next.addEventListener('click', () => goTo(current + 1));
    progress.forEach(button => {
      button.addEventListener('click', () => {
        const index = steps.findIndex(step => step.dataset.setupStep === button.dataset.stepTarget);
        if (index <= visited) goTo(index);
      });
    });
    form.addEventListener('input', syncFields);
    form.addEventListener('change', syncFields);
    form.addEventListener('keydown', event => {
      if (event.key !== 'Enter' || event.isComposing || current === steps.length - 1
        || !event.target.matches('input:not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="checkbox"]):not([type="radio"])')) return;
      event.preventDefault();
      goTo(current + 1);
    });
    form.addEventListener('submit', event => {
      syncFields();
      if (current !== steps.length - 1) {
        event.preventDefault();
        goTo(current + 1);
        return;
      }
      for (let position = 0; position < steps.length; position++) {
        if (!validateStep(position)) {
          event.preventDefault();
          return;
        }
      }
    });

    root.querySelectorAll('[data-setup-copy]').forEach(button => {
      const target = document.getElementById(button.getAttribute('aria-controls'));
      if (!target || !root.contains(target) || !target.readOnly || typeof target.value !== 'string') return;
      button.hidden = false;
      button.addEventListener('click', async () => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input || !root.contains(input) || !input.readOnly || typeof input.value !== 'string') return;
        let copied = false;
        if (navigator.clipboard?.writeText) {
          try {
            await navigator.clipboard.writeText(input.value);
            copied = true;
          } catch { /* Older browsers can still copy the selected callback. */ }
        }
        if (!copied) {
          const focused = document.activeElement;
          input.focus();
          input.select();
          try { copied = document.execCommand('copy'); } catch { /* Keep the callback selected for manual copying. */ }
          if (copied && focused && typeof focused.focus === 'function') focused.focus();
        }
        announce(copied ? button.dataset.copySuccess || root.dataset.copySuccess
          : button.dataset.copyError || root.dataset.copyError);
      });
    });

    if (status) {
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
    }
    // Native validation would try to focus required fields in inactive steps.
    // Validate the relevant step above, then reveal any invalid final field.
    form.noValidate = true;
    syncFields();
    root.classList.add('is-enhanced');
    root.querySelectorAll('[data-setup-fallback-submit]').forEach(button => { button.hidden = true; });
    navigation.hidden = false;
    showStep(0, false);
  }

  function start() {
    document.querySelectorAll('[data-portalweave-setup]').forEach(enhance);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
