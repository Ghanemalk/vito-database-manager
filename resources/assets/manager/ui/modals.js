// Dialogs and side drawers, and the confirmation dialog that shows the SQL about to run.

import { btn, field, h, iconBtn, input, put } from './dom.js';
import { codeBlock } from './highlight.js';

export const layers = [];

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && layers.length) {
    const top = layers[layers.length - 1];
    if (!top.sticky) {
      e.preventDefault();
      top.close();
    }
  }
});

/**
 * Opens a dialog, or a drawer from the side. Escape or a click outside closes it unless it is sticky; a guard()
 * set on the returned layer can refuse (unsaved changes). Returns the layer: close(), body, foot, head.
 */
export function modal({ title, body, foot, size = '', sticky = false, onClose, drawer = false }) {
  const box = h('div', { class: drawer ? 'drawer' : 'modal ' + size, role: 'dialog', 'aria-modal': 'true' });
  const overlay = h('div', { class: 'overlay' }, box);
  const layer = {
    el: box,
    sticky: false,
    close() {
      if (layer.closed) return;
      if (layer.guard && !layer.guard()) return;
      layer.closed = true;
      overlay.remove();
      layers.splice(layers.indexOf(layer), 1);
      onClose && onClose();
    },
  };
  const head = h(
    'div',
    { class: drawer ? 'drawer-head' : 'modal-head' },
    h('h2', null, title),
    iconBtn('x', 'Close', () => layer.close()),
  );
  const bodyEl = h('div', { class: drawer ? 'drawer-body' : 'modal-body' }, body);
  const footEl = h('div', { class: drawer ? 'drawer-foot' : 'modal-foot' }, foot || []);
  put(box, head, bodyEl, footEl);
  if (!foot || (Array.isArray(foot) && !foot.length)) footEl.classList.add('hidden');
  overlay.addEventListener('mousedown', (e) => {
    if (e.target === overlay && !sticky) layer.close();
  });
  layer.sticky = sticky;
  layer.body = bodyEl;
  layer.foot = footEl;
  layer.head = head;
  layers.push(layer);
  put(document.body, overlay);
  requestAnimationFrame(() => {
    const f = box.querySelector('[autofocus], input:not([type=checkbox]):not([disabled]), textarea, select');
    f && f.focus();
  });
  return layer;
}

/**
 * Asks before a change, showing the SQL that will run when there is some. typed: a name the user must type first,
 * for changes that cannot be undone. Resolves to true or false.
 */
export function confirmBox({ title, message, sql, confirm = 'Confirm', danger = false, typed = null, extra = null }) {
  return new Promise((resolve) => {
    let done = false;
    const typedInput = typed ? input({ placeholder: typed }) : null;
    const ok = btn(
      confirm,
      () => {
        done = true;
        m.close();
        resolve(true);
      },
      danger ? 'danger' : 'primary',
    );
    if (typedInput) {
      ok.disabled = true;
      typedInput.addEventListener('input', () => (ok.disabled = typedInput.value !== typed));
      typedInput.addEventListener('keydown', (e) => e.key === 'Enter' && !ok.disabled && ok.click());
    }
    const m = modal({
      title,
      body: [
        message ? h('p', { class: 'lead' }, message) : null,
        extra,
        sql ? h('div', { class: 'card' }, codeBlock(Array.isArray(sql) ? sql.join(';\n') + ';' : sql)) : null,
        typedInput
          ? field(h('span', null, 'Type ', h('b', { class: 'mono' }, typed), ' to confirm'), typedInput)
          : null,
      ],
      foot: [h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), ok],
      onClose: () => !done && resolve(false),
    });
    if (!typedInput) requestAnimationFrame(() => ok.focus());
  });
}
