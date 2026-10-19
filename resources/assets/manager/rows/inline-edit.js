// Editing a single cell in the grid (double-click a cell).

import { api } from '../api.js';
import { fill, h, select, spinner } from '../ui/dom.js';
import { fail, toast } from '../ui/toasts.js';
import { boolOut, cellView, isBinary, isTrue, isTruncated } from '../values.js';
import { loadRows } from './grid.js';

/**
 * Whether a cell can be edited in the grid. Generated columns, binary, JSON, multi-line and cut-short values need the
 * row editor.
 */
export function inlineEditable(c, v) {
  if (c.generated || isBinary(v) || isTruncated(v) || ['bin', 'json', 'geo', 'set'].includes(c.cat)) return false;
  return typeof v === 'string' ? !/[\r\n]/.test(v) : true;
}

/** Turns cell td (row ri on the page, column c, value v) into an input. Enter or leaving it saves, Escape cancels. */
export function inlineEdit(st, td, ri, c, v) {
  const d = st.data;
  let ctl;
  if (c.cat === 'bool')
    ctl = select(
      [
        ['', '—'],
        [boolOut(true), 'true'],
        [boolOut(false), 'false'],
      ],
      v === null ? '' : boolOut(isTrue(v)),
    );
  else if (c.cat === 'enum' && c.options) ctl = select(['', ...c.options], v ?? '');
  else ctl = h('input', { type: 'text', value: v ?? '', spellcheck: false, dir: 'auto' });
  td.classList.add('editing');
  fill(td, ctl);
  ctl.focus();
  if (ctl.select) ctl.select();
  let done = false;
  const finish = async (save) => {
    if (done) return;
    done = true;
    const nv = ctl.value;
    if (!save || nv === (v ?? '') || (v === null && nv === '')) {
      td.classList.remove('editing');
      fill(td, cellView(v, c));
      return;
    }
    fill(td, spinner());
    try {
      await api('update', { table: st.table, key: d.keys[ri], values: { [c.name]: nv } });
      toast(`${c.name} updated`);
      await loadRows(st);
    } catch (e) {
      fail(e);
      td.classList.remove('editing');
      fill(td, cellView(v, c));
    }
  };
  ctl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      finish(true);
    } else if (e.key === 'Escape') {
      e.stopPropagation();
      finish(false);
    }
  });
  ctl.addEventListener('blur', () => finish(true));
  ctl.addEventListener('click', (e) => e.stopPropagation());
}
