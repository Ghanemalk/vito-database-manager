// Building the page: h() creates an element with its properties and children, the others build common controls.

import { icon } from './icons.js';

const DOM_PROPS = new Set([
  'value',
  'checked',
  'disabled',
  'selected',
  'indeterminate',
  'readOnly',
  'multiple',
  'type',
  'placeholder',
  'title',
  'rows',
  'spellcheck',
  'htmlFor',
  'tabIndex',
  'href',
  'target',
  'accept',
  'min',
  'max',
  'step',
  'name',
]);

/**
 * Creates an element. props are attributes, except class, style, dataset, on<event> handlers and the DOM
 * properties in DOM_PROPS. kids are nodes or text, nested arrays allowed; null, undefined and booleans are skipped,
 * so a condition can stand in the list: h('div', null, ok ? 'yes' : null).
 */
export function h(tag, props, ...kids) {
  const el = document.createElement(tag);
  add(el, kids);
  if (props) {
    for (const k of Object.keys(props)) {
      const v = props[k];
      if (v === undefined || v === null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'style') typeof v === 'string' ? (el.style.cssText = v) : Object.assign(el.style, v);
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else if (DOM_PROPS.has(k)) el[k] = v;
      else el.setAttribute(k, v === true ? '' : String(v));
    }
  }
  return el;
}

/** Appends kids as h() does. */
function add(el, kids) {
  for (const k of kids.flat(Infinity)) {
    if (k === null || k === undefined || k === false || k === true) continue;
    el.append(k instanceof Node ? k : document.createTextNode(String(k)));
  }
  return el;
}

/** append and replaceChildren that skip null and false like h() does (the DOM methods would print "null"). */
export const put = (el, ...kids) => add(el, kids);

/** Replaces an element's content. */
export function fill(el, ...kids) {
  el.replaceChildren();
  return add(el, kids);
}

/** A button: label, click handler, extra classes (primary, danger, ghost, sm), an icon name and more props. */
export const btn = (label, onclick, cls = '', ic = null, extra = {}) =>
  h(
    'button',
    { type: 'button', class: 'btn ' + cls, onclick, ...extra },
    ic ? icon(ic) : null,
    label ? h('span', null, label) : null,
  );

/** A button with only an icon; title is its tooltip and its name for screen readers. */
export const iconBtn = (ic, title, onclick, cls = 'ghost') =>
  h('button', { type: 'button', class: 'btn icon ' + cls, title, 'aria-label': title, onclick }, icon(ic));

/** A labelled form field, with an optional hint below. */
export const field = (label, control, hint) =>
  h('label', { class: 'field' }, h('span', null, label), control, hint ? h('span', { class: 'hint' }, hint) : null);

/** A text input. */
export const input = (props = {}) =>
  h('input', { class: 'input', type: 'text', spellcheck: false, autocomplete: 'off', dir: 'auto', ...props });

/** A select. options are values or [value, label] pairs. */
export const select = (options, value, props = {}) => {
  const el = h(
    'select',
    { class: 'select', ...props },
    options.map((o) => {
      const [v, label] = Array.isArray(o) ? o : [o, o];
      return h('option', { value: v }, label);
    }),
  );
  if (value !== undefined && value !== null) el.value = value;
  return el;
};

/** A checkbox with its label; onchange gets true or false. */
export const check = (label, checked, onchange, cls = 'check') => {
  const box = h('input', {
    type: 'checkbox',
    checked: !!checked,
    onchange: (e) => onchange && onchange(e.target.checked),
  });
  return h('label', { class: cls }, box, label ? h('span', null, label) : null);
};

/** A checkbox drawn as a switch. */
export const toggle = (label, checked, onchange) => check(label, checked, onchange, 'switch');

/** A small turning circle for things that load. */
export const spinner = () => h('span', { class: 'spinner' });

/** Runs fn with the button disabled and turning, so it cannot be pressed twice. */
export async function busy(button, fn) {
  if (button.disabled) return undefined;
  const old = Array.from(button.childNodes);
  button.disabled = true;
  fill(button, spinner(), ...old.filter((n) => n.nodeName === 'SPAN'));
  try {
    return await fn();
  } finally {
    button.disabled = false;
    fill(button, ...old);
  }
}
