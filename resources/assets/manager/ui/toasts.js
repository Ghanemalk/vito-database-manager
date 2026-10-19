// Short messages in the corner of the page.

import { ApiError } from '../api.js';
import { h, put } from './dom.js';
import { icon } from './icons.js';

export const toastBox = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' });

/** Shows a message for a few seconds; kind 'err' stays longer. */
export function toast(message, kind = 'ok', ms) {
  const el = h(
    'div',
    { class: 'toast ' + kind },
    icon(kind === 'err' ? 'alert' : 'check'),
    h('div', { class: 't-body' }, message),
    h('button', { type: 'button', 'aria-label': 'Dismiss', onclick: () => el.remove() }, icon('x')),
  );
  put(toastBox, el);
  setTimeout(() => el.remove(), ms ?? (kind === 'err' ? 9000 : 3500));
}

/** Shows an error as a toast. */
export const fail = (e) => toast(e instanceof ApiError || e instanceof Error ? e.message : String(e), 'err');
