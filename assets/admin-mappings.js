(() => {
  'use strict';
  const standardKeys = ['username', 'email', 'first_name', 'last_name', 'display_name', 'groups'];
  const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const empty = value => (object(value) || Array.isArray(value)) && Object.keys(value).length === 0;
  const strings = value => Array.isArray(value) && value.every(item => typeof item === 'string');
  const list = value => typeof value === 'string' ? value.split(/[\s,]+/).filter(Boolean) : strings(value) ? value : null;
  const keysOnly = (value, keys) => Object.keys(value).every(key => keys.includes(key));

  // These conversions deliberately refuse structures that cannot be represented without loss.
  // JSON remains authoritative and server-side Config validation remains unchanged.
  function readRows(type, text) {
    const value = JSON.parse(text);
    if (value === null || typeof value !== 'object') throw new Error('invalid');
    if (empty(value)) return [];
    if (type === 'redirect_rules') {
      if (!Array.isArray(value)) throw new Error('unsupported');
      return value.map(rule => {
        if (!object(rule) || !keysOnly(rule, ['claim', 'equals', 'url'])
          || typeof rule.claim !== 'string' || typeof rule.url !== 'string'
          || !Object.hasOwn(rule, 'equals') || (rule.equals !== null && !['string', 'number', 'boolean'].includes(typeof rule.equals))) throw new Error('unsupported');
        return { claim: rule.claim, equals: rule.equals === null ? '' : String(rule.equals), value_type: rule.equals === null ? 'null' : typeof rule.equals, url: rule.url };
      });
    }
    if (type === 'content_rules') {
      if (!object(value) || !keysOnly(value, ['posts', 'categories'])) throw new Error('unsupported');
      return Object.entries(value).flatMap(([target, rules]) => {
        if (!object(rules) && !empty(rules)) throw new Error('unsupported');
        return Object.entries(rules).map(([id, rule]) => {
          if (!/^[1-9]\d*$/.test(id) || !object(rule) || !keysOnly(rule, ['login', 'roles', 'groups'])
            || (Object.hasOwn(rule, 'login') && ![true, false, 0, 1, '', '0', '1'].includes(rule.login))
            || list(rule.roles ?? []) === null || list(rule.groups ?? []) === null) throw new Error('unsupported');
          return { target, id, login: Boolean(rule.login) && rule.login !== '0', roles: list(rule.roles ?? []), groups: list(rule.groups ?? []).join('\n') };
        });
      });
    }
    if (!object(value)) throw new Error('unsupported');
    if (type === 'mapping' && !keysOnly(value, standardKeys)) throw new Error('unsupported');
    return Object.entries(value).map(([key, entry]) => {
      if (['role_mapping', 'domain_roles'].includes(type)) {
        if (list(entry) === null) throw new Error('unsupported');
        return { key, roles: list(entry) };
      }
      if (!['mapping', 'custom_mapping', 'domain_mapping'].includes(type) || typeof entry !== 'string') throw new Error('unsupported');
      return { key, value: entry };
    });
  }

  function writeRows(type, rows) {
    const value = type === 'redirect_rules' ? [] : Object.create(null);
    rows.forEach(row => {
      if (type === 'redirect_rules') {
        if (!row.claim.trim() || !row.url.trim()) throw new Error('required');
        let equals = row.equals;
        if (row.value_type === 'number') {
          // JSON number syntax, rather than Number('') or JavaScript-only numeric literals.
          if (!/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/.test(equals) || !Number.isFinite(Number(equals))) throw new Error('invalid_value');
          equals = Number(equals);
        } else if (row.value_type === 'boolean') {
          if (!['true', 'false'].includes(equals)) throw new Error('invalid_value');
          equals = equals === 'true';
        } else if (row.value_type === 'null') equals = null;
        value.push({ claim: row.claim, equals, url: row.url });
      } else if (type === 'content_rules') {
        if (!['posts', 'categories'].includes(row.target) || !/^[1-9]\d*$/.test(row.id)) throw new Error('required');
        if (!Object.hasOwn(value, row.target)) value[row.target] = Object.create(null);
        if (Object.hasOwn(value[row.target], row.id)) throw new Error('duplicate');
        value[row.target][row.id] = { login: row.login, roles: row.roles, groups: row.groups.split('\n').map(item => item.trim()).filter(Boolean) };
      } else {
        if (!row.key.trim()) throw new Error('required');
        if (Object.hasOwn(value, row.key)) throw new Error('duplicate');
        value[row.key] = ['role_mapping', 'domain_roles'].includes(type) ? row.roles : row.value;
      }
    });
    return JSON.stringify(value, null, 2);
  }

  // Dependency-free conversion tests also exercise the production serialization boundary.
  if (typeof module === 'object' && module.exports) module.exports = { readRows, writeRows };
  if (typeof document === 'undefined') return;

  document.querySelectorAll('[data-mapping-editor]').forEach(editor => {
    const source = editor.querySelector('textarea');
    const form = source?.form;
    if (!source || !form) return;
    const type = editor.dataset.mappingEditor;
    const config = JSON.parse(editor.dataset.mappingConfig);
    const labels = config.labels;
    const heading = editor.querySelector(`label[for="${source.id}"]`);
    const toolbar = document.createElement('div');
    toolbar.className = 'portalweave-sso-mapping-toolbar';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'button button-small';
    const visual = document.createElement('div');
    visual.id = source.id + '-visual';
    visual.dataset.mappingUi = '';
    visual.setAttribute('role', 'group');
    visual.setAttribute('aria-label', heading?.textContent || labels.visual);
    const status = document.createElement('p');
    status.className = 'portalweave-sso-mapping-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    toggle.setAttribute('aria-controls', source.id + ' ' + visual.id);
    toolbar.append(toggle);
    source.before(toolbar, visual, status);
    let mode = 'advanced';
    let sequence = 0;

    function message(error) {
      status.textContent = labels[error.message] || labels.invalid;
    }

    function setMode(next) {
      mode = next;
      source.hidden = mode === 'visual';
      visual.hidden = mode !== 'visual';
      visual.querySelectorAll('input, select, textarea, button').forEach(control => { control.disabled = mode !== 'visual'; });
      toggle.textContent = labels[mode === 'visual' ? 'advanced' : 'visual'];
      if (heading) {
        if (mode === 'visual') heading.removeAttribute('for');
        else heading.htmlFor = source.id;
      }
    }

    function field(row, key, label, value, kind = 'text', choices = {}) {
      const wrapper = document.createElement('label');
      const text = document.createElement('span');
      text.textContent = label;
      const control = document.createElement(kind === 'select' || kind === 'roles' ? 'select' : kind === 'textarea' ? 'textarea' : 'input');
      control.id = source.id + '-control-' + (++sequence);
      control.dataset.mappingKey = key;
      if (kind === 'select' || kind === 'roles') {
        control.multiple = kind === 'roles';
        if (kind === 'roles') {
          choices = { ...config.roles };
          value.forEach(role => { if (!Object.hasOwn(choices, role)) choices[role] = role; });
          control.size = Math.min(5, Math.max(2, Object.keys(choices).length));
        }
        Object.entries(choices).forEach(([optionValue, optionLabel]) => {
          const option = document.createElement('option');
          option.value = optionValue;
          option.textContent = optionLabel;
          option.selected = kind === 'roles' ? value.includes(optionValue) : value === optionValue;
          control.append(option);
        });
      } else if (kind === 'checkbox') {
        control.type = 'checkbox';
        control.checked = value;
        wrapper.className = 'portalweave-sso-mapping-check';
      } else {
        if (kind !== 'textarea') control.type = kind;
        else control.rows = 3;
        control.value = value;
        control.spellcheck = false;
      }
      wrapper.append(text, control);
      row.append(wrapper);
      return control;
    }

    function rowValues() {
      return Array.from(visual.querySelectorAll('[data-mapping-row]')).map(row => {
        const values = {};
        if (row.dataset.mappingFixed) values.key = row.dataset.mappingFixed;
        row.querySelectorAll('[data-mapping-key]').forEach(control => {
          values[control.dataset.mappingKey] = control.multiple ? Array.from(control.selectedOptions, option => option.value)
            : control.type === 'checkbox' ? control.checked : control.value;
        });
        return values;
      });
    }

    function sync() {
      visual.querySelectorAll('[data-mapping-key]').forEach(control => control.setCustomValidity(''));
      try {
        const next = writeRows(type, rowValues());
        source.value = next;
        status.textContent = '';
        source.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
      } catch (error) {
        message(error);
        const first = visual.querySelector('[data-mapping-key]');
        if (first) first.setCustomValidity(status.textContent);
        return false;
      }
    }

    function addRow(values, focus = false) {
      const row = document.createElement('div');
      row.className = 'portalweave-sso-mapping-row';
      row.dataset.mappingRow = '';
      if (type === 'mapping') {
        row.dataset.mappingFixed = values.key;
        field(row, 'value', config.standard[values.key], values.value);
      } else if (type === 'redirect_rules') {
        field(row, 'claim', labels.claim, values.claim);
        field(row, 'value_type', labels.value_type, values.value_type, 'select', Object.fromEntries(['string', 'number', 'boolean', 'null'].map(key => [key, labels[key]])));
        field(row, 'equals', labels.equals, values.equals);
        field(row, 'url', labels.url, values.url, 'url');
      } else if (type === 'content_rules') {
        field(row, 'target', labels.target, values.target, 'select', { posts: labels.posts, categories: labels.categories });
        const id = field(row, 'id', labels.id, values.id, 'number');
        id.min = '1';
        id.step = '1';
        field(row, 'login', labels.login, values.login, 'checkbox');
        field(row, 'roles', labels.roles, values.roles, 'roles');
        field(row, 'groups', labels.groups, values.groups, 'textarea');
      } else {
        const keyLabel = type === 'custom_mapping' ? labels.meta : type === 'role_mapping' ? labels.group : labels.domain;
        field(row, 'key', keyLabel, values.key);
        if (['role_mapping', 'domain_roles'].includes(type)) field(row, 'roles', labels.roles, values.roles, 'roles');
        else field(row, 'value', type === 'domain_mapping' ? labels.target_domain : labels.path, values.value);
      }
      if (type !== 'mapping') {
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'button button-secondary';
        remove.textContent = labels.remove;
        remove.addEventListener('click', () => {
          const next = row.nextElementSibling?.querySelector('input, select, textarea') || row.previousElementSibling?.querySelector('input, select, textarea') || visual.querySelector('[data-mapping-add]');
          row.remove();
          sync();
          updateEmpty();
          next?.focus();
        });
        row.append(remove);
      }
      const add = visual.querySelector('[data-mapping-add]');
      if (add) add.before(row);
      else visual.append(row);
      if (focus) row.querySelector('input, select, textarea')?.focus();
    }

    function updateEmpty() {
      const placeholder = visual.querySelector('[data-mapping-empty]');
      if (placeholder) placeholder.hidden = Boolean(visual.querySelector('[data-mapping-row]'));
    }

    function render() {
      let rows = readRows(type, source.value);
      visual.replaceChildren();
      visual.classList.toggle('portalweave-sso-mapping-standard', type === 'mapping');
      if (type === 'mapping') rows = standardKeys.map(key => rows.find(row => row.key === key) || { key, value: config.defaults[key] || '' });
      rows.forEach(values => addRow(values));
      if (type !== 'mapping') {
        const placeholder = document.createElement('p');
        placeholder.className = 'description';
        placeholder.dataset.mappingEmpty = '';
        placeholder.textContent = labels.empty;
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'button button-secondary portalweave-sso-mapping-add';
        add.dataset.mappingAdd = '';
        add.textContent = labels.add;
        add.addEventListener('click', () => {
          const values = type === 'redirect_rules' ? { claim: '', value_type: 'string', equals: '', url: '' }
            : type === 'content_rules' ? { target: 'posts', id: '', login: true, roles: [], groups: '' }
              : { key: '', value: '', roles: [] };
          addRow(values, true);
          updateEmpty();
          sync();
        });
        visual.append(placeholder, add);
        updateEmpty();
      }
      status.textContent = '';
    }

    visual.addEventListener('input', () => { if (mode === 'visual') sync(); });
    visual.addEventListener('change', () => { if (mode === 'visual') sync(); });
    toggle.addEventListener('click', () => {
      if (mode === 'visual') {
        // Source already contains every valid edit; do not normalize unchanged advanced JSON.
        if (visual.querySelector(':invalid')) { visual.querySelector(':invalid').reportValidity(); return; }
        setMode('advanced');
        source.focus();
      } else {
        try { render(); setMode('visual'); visual.querySelector('input, select, textarea, button')?.focus(); }
        catch (error) { message(error instanceof SyntaxError ? new Error('invalid') : error); }
      }
    });
    source.addEventListener('change', () => {
      if (mode !== 'visual') return;
      try { render(); setMode('visual'); } catch (error) { setMode('advanced'); message(error); }
    });
    function reset() {
      queueMicrotask(() => {
        try { render(); setMode('visual'); } catch (error) { setMode('advanced'); message(error); }
      });
    }
    form.addEventListener('reset', reset);
    form.addEventListener('portalweave-sso:reverted', reset);
    try { render(); setMode('visual'); }
    catch (error) { setMode('advanced'); message(error instanceof SyntaxError ? new Error('invalid') : error); }
  });
})();
