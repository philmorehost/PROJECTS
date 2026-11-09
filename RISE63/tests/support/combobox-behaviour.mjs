/**
 * Parish picker behaviour — the parts that only exist in a browser.
 *
 * Two rules matter and neither can be checked from the rendered HTML:
 *
 *   1. a member whose church is not listed must be able to file it anyway, and
 *   2. picking a parish from the list and typing a name must never both be submitted, because
 *      whichever field the browser sends last would silently win.
 *
 * Loads the real public/assets/app.js in a stubbed DOM. No dependency beyond node:vm.
 *
 *   node tests/support/combobox-behaviour.mjs
 */
import { readFile } from 'node:fs/promises';
import { createContext, runInContext } from 'node:vm';

const source = await readFile(new URL('../../public/assets/app.js', import.meta.url), 'utf8');

const failures = [];
let checks = 0;
function check(name, condition, detail = '') {
  checks++;
  if (condition) {
    console.log(`PASS ${name}`);
  } else {
    failures.push(name);
    console.log(`FAIL ${name}${detail ? ' :: ' + String(detail).slice(0, 300) : ''}`);
  }
}

/** Minimal element: enough for app.js's listeners, attributes and children. */
function element(tag, attrs = {}) {
  const node = {
    tagName: tag.toUpperCase(),
    id: attrs.id || '',
    hidden: Boolean(attrs.hidden),
    open: false,
    value: attrs.value || '',
    textContent: '',
    dataset: attrs.dataset || {},
    attributes: {},
    children: [],
    listeners: {},
    classList: {
      list: new Set((attrs.className || '').split(' ').filter(Boolean)),
      add(c) { this.list.add(c); },
      contains(c) { return this.list.has(c); },
      remove(c) { this.list.delete(c); },
    },
    setAttribute(k, v) { this.attributes[k] = v; },
    getAttribute(k) { return this.attributes[k]; },
    removeAttribute(k) { delete this.attributes[k]; },
    addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
    dispatchEvent(event) { node.dispatch(event.type, event); return true; },
    dispatch(type, event = {}) { (this.listeners[type] || []).forEach((fn) => fn(Object.assign({ type, preventDefault() {}, target: node }, event))); },
    appendChild(child) { this.children.push(child); child.parentNode = node; return child; },
    contains(other) {
      if (other === node) return true;
      return node.children.some((c) => c.contains(other));
    },
    querySelector(selector) {
      if (selector === 'strong' || selector === 'small') {
        return node.children.find((c) => c.tagName === selector.toUpperCase()) || null;
      }
      if (selector === 'input[role="combobox"]') return node.comboInput || null;
      if (selector === 'input[type="hidden"]') return node.comboHidden || null;
      if (selector === '.combo-list') return node.comboList || null;
      return null;
    },
    querySelectorAll(selector) {
      if (selector === '.combo-option') {
        return (node.comboList ? node.comboList.children : []).filter((c) => c.classList.contains('combo-option'));
      }
      return [];
    },
    scrollIntoView() {},
    focus() { node.focused = true; },
    set innerHTML(v) {
      if (v === '') { node.children.length = 0; }
    },
    get innerHTML() { return ''; },
  };
  // app.js assigns className directly ("li.className = 'combo-option'"), so the class list has to
  // follow it or the option is created but never found again.
  let className = attrs.className || '';
  Object.defineProperty(node, 'className', {
    set(value) {
      className = value;
      node.classList.list = new Set(String(value).split(' ').filter(Boolean));
    },
    get() { return className; },
  });
  node.className = className;

  Object.defineProperty(node, 'innerHTML', {
    set(v) {
      node._html = v;
      if (v === '') node.children.length = 0;
      // app.js writes '<strong></strong><small></small>' and then fills them by textContent.
      if (/<strong><\/strong><small><\/small>/.test(v)) {
        node.children.length = 0;
        node.appendChild(element('strong'));
        node.appendChild(element('small'));
      }
    },
    get() { return node._html || ''; },
  });
  return node;
}

const CANDIDATES = [
  { id: 5, parish: 'LIVING WATER PARISH', area: 'HOPE AREA', zone: 'GRACE ZONE', label: 'LIVING WATER PARISH · HOPE AREA · GRACE ZONE' },
];

/** A page with the registration form's picker, and a controllable search result. */
function page({ results = CANDIDATES, endpoint = '/api/index.php', capsFields = null, fail = false } = {}) {
  const combo = element('div', { id: 'parish-combo' });
  combo.dataset = { endpoint };
  const input = element('input', { id: 'parish-search', value: '' });
  const hidden = element('input', { id: 'parish-id-field', value: '' });
  const list = element('ul', { id: 'parish-list', hidden: true });
  const manual = element('details', { id: 'parish-manual' });
  const manualField = element('input', { id: 'parish_name_raw', value: '' });

  combo.comboInput = input;
  combo.comboHidden = hidden;
  combo.comboList = list;
  // app.js asks the list itself for its options once they are built.
  list.querySelectorAll = (selector) => (selector === '.combo-option'
    ? list.children.filter((c) => c.classList.contains('combo-option'))
    : []);
  combo.appendChild(input);
  combo.appendChild(hidden);
  combo.appendChild(list);

  // The server-rendered list, as the view emits it: a plain <select> with the labels as attributes.
  const pick = element('select', { id: 'parish-pick' });
  pick.options = [
    { value: '', getAttribute: () => null, textContent: 'Choose your church' },
    ...CANDIDATES.map((c) => ({
      value: String(c.id),
      getAttribute: (name) => (name === 'data-label' ? c.label : null),
      textContent: c.label,
    })),
  ];
  pick.selectedIndex = 0;

  const byId = {
    'parish-combo': combo,
    'parish-pick': pick,
    'parish-search': input,
    'parish-id-field': hidden,
    'parish-list': list,
    'parish-manual': manual,
    'parish_name_raw': manualField,
  };

  const documentListeners = {};
  const document = {
    documentElement: { dataset: {}, classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } } },
    body: { dataset: {}, classList: { add() {}, remove() {}, contains() { return false; } }, style: {} },
    cookie: '',
    readyState: 'complete',
    getElementById: (id) => byId[id] ?? null,
    querySelector: () => combo,
    querySelectorAll: (selector) => (selector === '[data-combobox]' ? [combo]
      : selector === '[data-caps]' ? (capsFields || [])
      : selector === '[data-parish-pick]' ? [pick] : []),
    addEventListener(type, fn) { (documentListeners[type] = documentListeners[type] || []).push(fn); },
    createElement: (tag) => element(tag),
    createTextNode: () => element('span'),
  };

  const window = {
    location: { href: 'https://example.test/register', reload() {} },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    matchMedia: () => ({ matches: false, addEventListener() {} }),
    addEventListener() {},
    setTimeout, clearTimeout, setInterval, clearInterval,
    AbortController, URLSearchParams,
    fetch: () => (fail
      ? Promise.reject(new Error('network down'))
      : Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ parishes: results }) })),
    document,
    navigator: { onLine: true, userAgent: 'node' },
  };

  const context = createContext({
    window, document, navigator: window.navigator, localStorage: window.localStorage,
    setTimeout, clearTimeout, setInterval, clearInterval, console,
    fetch: window.fetch, AbortController, URLSearchParams, Promise, Event: class { constructor(t) { this.type = t; } },
  });
  runInContext(source, context, { filename: 'app.js' });

  const options = () => list.children.filter((c) => c.classList.contains('combo-option'));

  return {
    input, hidden, list, manual, manualField, options, capsFields, pick,
    type(value) { input.value = value; input.dispatch('input'); },
    press(key) { input.dispatch('keydown', { key }); },
    clickOption(index) { const opt = options()[index]; opt.dispatch('mousedown'); },
  };
}

const settle = (ms = 200) => new Promise((resolve) => setTimeout(resolve, ms));

/* 1. Typing searches, and a match can be chosen. */
{
  const p = page();
  p.type('living');
  await settle();
  check('typing opens the list', p.list.hidden === false);
  check('a match is offered', p.options()[0] && p.options()[0].children[0].textContent === 'LIVING WATER PARISH');

  p.clickOption(0);
  check('choosing a match fills the search box', p.input.value === CANDIDATES[0].label);
  check('choosing a match stores the parish id', p.hidden.value === 5);
  check('choosing a match closes the list', p.list.hidden === true);
  check('choosing a match clears any typed church name', p.manualField.value === '');
}

/* 2. No match: the member is offered their own church rather than a dead end. */
{
  const p = page({ results: [] });
  p.type('chapel of wonders');
  await settle();

  const options = p.options();
  check('an unmatched search still offers a row', options.length === 1);
  check('the row says the church is not listed', options[0].children[0].textContent === 'My church is not listed');
  check('the row repeats what will be filed, in capitals',
    /CHAPEL OF WONDERS/.test(options[0].children[1].textContent));

  p.clickOption(0);
  check('choosing it opens the manual field', p.manual.open === true);
  check('choosing it pre-fills the name in capitals', p.manualField.value === 'CHAPEL OF WONDERS');
  check('choosing it leaves no parish id behind', p.hidden.value === '');
  check('choosing it clears the search box so only one field carries a name', p.input.value === '');
}

/* 3. Keyboard: Enter on the manual row must take the manual path, not post "undefined". */
{
  const p = page({ results: [] });
  p.type('grace chapel');
  await settle();
  p.press('ArrowDown');
  p.press('Enter');
  check('Enter on the manual row files the typed name', p.manualField.value === 'GRACE CHAPEL');
  check('Enter on the manual row never invents a parish id', p.hidden.value === '', 'got ' + p.hidden.value);
}

/* 4. Keyboard: Enter on a real match still picks it. */
{
  const p = page();
  p.type('living');
  await settle();
  p.press('ArrowDown');
  p.press('Enter');
  check('Enter on a real match picks that parish', p.hidden.value === 5);
  check('Enter on a real match leaves the manual field empty', p.manualField.value === '');
}

/* 5. Picking a parish after typing a name resolves the conflict in favour of the list. */
{
  const p = page();
  p.manual.open = true;
  p.manualField.value = 'SOME TYPED CHURCH';
  p.type('living');
  await settle();
  p.clickOption(0);
  check('a later pick clears the earlier typed name', p.manualField.value === '');
  check('a later pick sets the parish id', p.hidden.value === 5);
}

/* 6. A one- or two-letter term must not jump the list; the server refuses those too. */
{
  const p = page({ results: [] });
  p.type('li');
  await settle();
  check('a two-letter search offers no escape hatch yet', p.options().length === 0);
  p.type('liv');
  await settle();
  check('a three-letter search starts offering it', p.options().length === 1);
}

/* 7. Capitals as you type, with the caret where the member left it. */
{
  const church = element('input', { id: 'parish_name_raw', value: '' });
  const p = page({ capsFields: [church] });

  church.value = 'chapel of wonders';
  church.selectionStart = 6;
  church.selectionEnd = 6;
  church.dispatch('input');
  check('a typed church name becomes capitals immediately', church.value === 'CHAPEL OF WONDERS');
  check('the caret is not thrown to the end of the field', church.selectionStart === 6,
    'got ' + church.selectionStart);

  church.value = 'ALREADY CAPITALS';
  church.dispatch('input');
  check('a name already in capitals is left untouched', church.value === 'ALREADY CAPITALS');

  // Autofill and paste do not always fire input.
  church.value = 'pasted lower case';
  church.dispatch('change');
  check('a pasted or autofilled value is capitalised on change', church.value === 'PASTED LOWER CASE');
}

/* 8. The server-rendered list works on its own, and mirrors into the hidden field. */
{
  const p = page();
  // The fake select has no .value of its own; mirror what a browser does on selection.
  p.pick.value = '5';
  p.pick.selectedIndex = 1;
  p.pick.dispatch('change');
  check('choosing from the rendered list sets the parish id', p.hidden.value === '5');
  check('choosing from the rendered list shows the label in the search box',
    p.input.value === CANDIDATES[0].label);

  // Picking from the list must also clear a name typed before it, or both would be submitted.
  p.manualField.value = 'SOME TYPED CHURCH';
  p.pick.selectedIndex = 1;
  p.pick.value = '5';
  p.pick.dispatch('change');
  check('choosing from the rendered list clears a typed church name', p.manualField.value === '');
}

/* 9. A search that cannot run must say so — not render an empty list, which reads as
      "your church is not listed". This is the reported symptom, reproduced. */
{
  const p = page({ fail: true });
  p.type('chapel');
  await settle();
  check('a failed search still opens the list', p.list.hidden === false);
  const first = p.list.children[0];
  check('a failed search explains itself rather than going blank',
    first && /could not be loaded/i.test(first.textContent), first && first.textContent);
  check('a failed search still offers the manual route', p.options().length === 1);
}

console.log('\n' + '─'.repeat(62));
console.log(`${checks - failures.length} passed, ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
