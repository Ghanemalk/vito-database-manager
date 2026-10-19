// Popovers and menus anchored to a button. One is open at a time.

import { h, put } from './dom.js';
import { icon } from './icons.js';

let openPopover = null;

/** Opens a popover under anchor (above it when there is no room). A click elsewhere or Escape closes it. */
export function popover(anchor, content, { pad = false, align = 'start', width } = {}) {
  closePopover();
  const el = h('div', { class: 'popover' + (pad ? ' pad' : '') }, content);
  if (width) el.style.width = width + 'px';
  put(document.body, el);
  const r = anchor.getBoundingClientRect();
  const w = el.offsetWidth,
    hgt = el.offsetHeight;
  let left = align === 'end' ? r.right - w : r.left;
  left = Math.max(8, Math.min(left, innerWidth - w - 8));
  let top = r.bottom + 4;
  if (top + hgt > innerHeight - 8 && r.top - hgt - 4 > 8) top = r.top - hgt - 4;
  el.style.left = left + 'px';
  el.style.top = Math.max(8, top) + 'px';
  const away = (e) => {
    if (!el.contains(e.target) && !anchor.contains(e.target)) closePopover();
  };
  const esc = (e) => {
    if (e.key === 'Escape') {
      e.stopPropagation();
      closePopover();
    }
  };
  setTimeout(() => document.addEventListener('mousedown', away), 0);
  document.addEventListener('keydown', esc, true);
  openPopover = {
    el,
    close() {
      el.remove();
      document.removeEventListener('mousedown', away);
      document.removeEventListener('keydown', esc, true);
    },
  };
  requestAnimationFrame(() => {
    const f = el.querySelector('input:not([type=checkbox]), select, textarea');
    f && f.focus();
  });
  return openPopover;
}

/** Closes the open popover, if any. */
export function closePopover() {
  if (openPopover) {
    openPopover.close();
    openPopover = null;
  }
}

/** A popover menu. items: { label, icon, hint, danger, disabled, hidden, onClick } or { sep: true } for a line. */
export function menu(anchor, items, opts) {
  const list = items
    .filter((x) => x && !x.hidden)
    .map((x) =>
      x.sep
        ? h('hr')
        : h(
            'button',
            {
              type: 'button',
              class: 'mi' + (x.danger ? ' danger' : ''),
              disabled: x.disabled,
              onclick: () => {
                closePopover();
                x.onClick();
              },
            },
            x.icon ? icon(x.icon) : null,
            x.label,
            x.hint ? h('span', { class: 'k' }, x.hint) : null,
          ),
    );
  return popover(anchor, list, { align: 'end', ...opts });
}
