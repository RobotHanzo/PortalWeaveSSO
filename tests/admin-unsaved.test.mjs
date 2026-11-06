import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../assets/admin-unsaved.js', import.meta.url), 'utf8');

function fixture({ readOnly = false } = {}) {
  class DraftEvent {
    constructor(type, options = {}) { this.type = type; this.defaultPrevented = false; Object.assign(this, options); }
    preventDefault() { this.defaultPrevented = true; }
  }
  class Element {
    constructor() {
      this.listeners = new Map(); this.children = []; this.attributes = new Map();
      this.classes = new Set(); this.textContent = ''; this.disabled = false;
      this.classList = { toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name) };
    }
    addEventListener(type, handler) {
      this.listeners.set(type, [...this.listeners.get(type) || [], handler]);
    }
    dispatchEvent(event) {
      event.target ||= this;
      for (const handler of this.listeners.get(event.type) || []) handler(event);
      return !event.defaultPrevented;
    }
    append(...elements) { this.children.push(...elements); }
    setAttribute(name, value) { this.attributes.set(name, value); }
    contains(element) { return this === element || this.children.some(child => child.contains(element)); }
    matches(selector) { return selector === ':disabled' ? this.disabled : false; }
    getClientRects() { return [{}]; }
    focus() { document.activeElement = this; }
    setCustomValidity(message) { this.validation = message; }
  }
  function control(name, value, type = 'text') {
    return Object.assign(new Element(), { name, value, defaultValue: value, type, checked: false, defaultChecked: false });
  }
  const text = control('settings[button][text]', 'Continue with {app}');
  const checkbox = control('settings[button][shadow]', '1', 'checkbox');
  const secret = control('settings[apps][sample][client_secret]', '', 'password');
  const json = control('settings[apps][sample][mapping]', '{"email":"email"}', 'textarea');
  const navigation = control('portalweave_sso_provider_section', 'connection', 'hidden');
  const preview = control('', 'sample');
  const submit = Object.assign(new Element(), { type: 'submit', disabled: readOnly });
  const form = new Element();
  form.elements = [text, checkbox, secret, json, navigation, preview, submit];
  form.querySelector = () => submit;
  form.reset = () => {
    for (const field of form.elements) { field.value = field.defaultValue; field.checked = field.defaultChecked; }
    form.dispatchEvent(new DraftEvent('reset'));
  };
  let submissions = 0;
  let valid = true;
  form.requestSubmit = button => {
    assert.equal(button, submit, 'the floating action must use the original form submitter');
    if (!valid) return;
    const event = new DraftEvent('submit', { target: form, submitter: button });
    form.dispatchEvent(event);
    root.dispatchEvent(event);
    if (!event.defaultPrevented) submissions++;
  };
  const root = new Element();
  root.dataset = { unsavedTitle: 'Unsaved settings', unsavedMessage: 'You have unsaved changes.', unsavedRevert: 'Revert all changes', unsavedSave: 'Save changes' };
  root.querySelectorAll = () => [form];
  const document = { readyState: 'complete', activeElement: text, createElement: () => new Element(), querySelectorAll: () => [root] };
  const window = new Element();
  window.setTimeout = callback => callback();
  vm.runInNewContext(source, { document, window, Event: DraftEvent });
  const banner = root.children[0];
  const message = banner?.children[0];
  const [revert, save] = banner?.children[1].children || [];
  return {
    root, form, text, checkbox, secret, json, navigation, preview, banner, message, revert, save, window, document,
    emit: (element, type) => element.dispatchEvent(new DraftEvent(type)),
    submit: options => root.dispatchEvent(new DraftEvent('submit', { target: form, ...options })),
    leave: () => { const event = new DraftEvent('beforeunload'); window.dispatchEvent(event); return event; },
    visible: () => banner?.classes.has('is-visible') || false,
    submissions: () => submissions,
    setValid: value => { valid = value; },
  };
}

test('The reminder tracks real settings, ignores navigation and previews, and clears when values match', () => {
  const page = fixture();
  assert.equal(page.visible(), false);
  page.navigation.value = 'mapping';
  page.preview.value = 'another sample';
  page.emit(page.form, 'change');
  assert.equal(page.visible(), false);
  page.text.value = 'Sign in';
  page.emit(page.form, 'input');
  assert.equal(page.visible(), true);
  assert.equal(page.banner.inert, false);
  assert.equal(page.message.textContent, 'You have unsaved changes.');
  assert.equal(page.leave().defaultPrevented, true);
  page.text.value = page.text.defaultValue;
  page.emit(page.form, 'input');
  assert.equal(page.visible(), false);
  assert.equal(page.banner.inert, true);
  assert.equal(page.leave().defaultPrevented, false);
});

test('Revert restores every settings section and opaque draft secret, then notifies enhanced editors', () => {
  const page = fixture();
  let restored;
  page.form.addEventListener('portalweave-sso:reverted', () => {
    restored = [page.text.value, page.checkbox.checked, page.secret.value, page.json.value];
  });
  page.text.value = 'Changed';
  page.checkbox.checked = true;
  page.secret.value = 'opaque+<>&\\draft';
  page.json.value = '{"email":"custom.mail"}';
  page.emit(page.form, 'input');
  page.document.activeElement = page.revert;
  page.emit(page.revert, 'click');
  assert.deepEqual(restored, ['Continue with {app}', false, '', '{"email":"email"}']);
  assert.equal(page.visible(), false);
  assert.equal(page.document.activeElement, page.text);
  assert.equal(page.leave().defaultPrevented, false);
});

test('Save retains invalid drafts and uses the original validated form submission', () => {
  const page = fixture();
  page.checkbox.checked = true;
  page.emit(page.form, 'change');
  page.setValid(false);
  page.emit(page.save, 'click');
  assert.equal(page.submissions(), 0);
  assert.equal(page.visible(), true);
  page.setValid(true);
  page.emit(page.save, 'click');
  assert.equal(page.submissions(), 1);
  assert.equal(page.visible(), false);
  assert.equal(page.leave().defaultPrevented, false);
  page.emit(page.window, 'pageshow');
  assert.equal(page.visible(), true, 'returning from history must retain the unsaved reminder');
});

test('Prevented submissions and connection tests keep the draft reminder active', () => {
  const page = fixture();
  page.secret.value = 'draft';
  page.emit(page.form, 'input');
  page.submit({ defaultPrevented: true });
  assert.equal(page.visible(), true);
  page.submit({ submitter: { matches: selector => selector === '[data-setup-test]' } });
  assert.equal(page.visible(), true);
  assert.equal(page.leave().defaultPrevented, true);
});

test('Guided editors may validate their complete workflow before the floating Save action submits', () => {
  const page = fixture();
  let requests = 0;
  page.form.addEventListener('portalweave-sso:save-requested', event => { event.preventDefault(); requests++; });
  page.emit(page.save, 'click');
  assert.equal(requests, 1);
  assert.equal(page.submissions(), 0);
});

test('Read-only forms do not receive a reminder or leave warning', () => {
  const page = fixture({ readOnly: true });
  assert.equal(page.banner, undefined);
  page.text.value = 'attempted change';
  assert.equal(page.leave().defaultPrevented, false);
});
