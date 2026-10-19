// The row editor drawer: editing, creating, duplicating and deleting one row.

import { api } from '../api.js';
import { tableLabel } from '../lib/sql.js';
import { tableState } from '../state.js';
import { btn, busy, fill, h, put, spinner } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmBox, modal } from '../ui/modals.js';
import { fail, toast } from '../ui/toasts.js';
import { makeField } from './fields.js';
import { bumpCount, loadRows } from './grid.js';

/**
 * ri: index of a row on the current page, or null for a new row. preset: values for a new row (duplicate).
 */
export async function rowEditor(t, ri, preset = null) {
  const st = tableState(t);
  let d = st.data;
  if (!d) {
    await loadRows(st);
    d = st.data;
    if (!d) return;
  }
  const editable = ['table', 'partitioned'].includes(d.type);
  const creating = ri === null;
  const columns = d.columns;
  const key = creating ? null : d.keys[ri];
  const canEdit = editable && (creating || (key !== null && d.key.mode !== 'none'));
  const fields = [];
  const body = h('div', { class: 'stack', style: 'gap:14px' });
  const saveBtn = btn(creating ? 'Create row' : 'Save changes', () => save(), 'primary', null, { disabled: !creating });
  const footer = [];
  if (!creating && canEdit) {
    footer.push(btn('', () => del(), 'ghost', 'trash', { title: 'Delete this row', 'aria-label': 'Delete this row' }));
    footer.push(
      btn(
        'Duplicate',
        () => {
          const values = {};
          for (const f of fields) {
            if (
              f.c.generated ||
              f.c.default.kind === 'auto' ||
              (['pk', 'unique'].includes(d.key.mode) && d.key.cols.includes(f.c.name))
            )
              continue;
            const out = f.output();
            if (out !== undefined) values[f.c.name] = out;
          }
          m.guard = null;
          m.close();
          rowEditor(t, null, values);
        },
        'ghost',
        'copy',
      ),
    );
  }
  footer.push(
    h('span', { class: 'spacer' }),
    btn(canEdit ? 'Cancel' : 'Close', () => m.close()),
  );
  if (canEdit) footer.push(saveBtn);
  const m = modal({
    title: creating ? `New row · ${tableLabel(t)}` : `Row · ${tableLabel(t)}`,
    body,
    foot: footer,
    drawer: true,
  });
  m.guard = () => !fields.some((f) => f.changed()) || window.__vdbmDiscard === true || discardAsk();
  let asking = false;
  function discardAsk() {
    if (asking) return false;
    asking = true;
    confirmBox({
      title: 'Discard your changes?',
      message: 'The changes in this row have not been saved.',
      confirm: 'Discard',
      danger: true,
    }).then((ok) => {
      asking = false;
      if (ok) {
        m.guard = null;
        m.close();
      }
    });
    return false;
  }

  let row = creating ? null : d.rows[ri];
  if (!creating) {
    put(body, h('div', { class: 'empty' }, spinner(), 'Loading the row…'));
    try {
      const full = await api('row', { table: t, key });
      row = full.row;
      if (full.ambiguous)
        body.prepend(
          h(
            'div',
            { class: 'alert warn' },
            icon('alert'),
            h('div', { class: 'a-body' }, 'Several rows match this one exactly; a change applies to only one of them.'),
          ),
        );
    } catch (e) {
      fill(body, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
      return;
    }
    body.querySelector('.empty')?.remove();
  }

  const keyCols = new Set(['pk', 'unique'].includes(d.key.mode) ? d.key.cols : []);
  for (const [i, c] of columns.entries()) {
    const f = makeField(c, creating ? undefined : row[i], {
      creating,
      readonly: !canEdit,
      isKey: keyCols.has(c.name),
      preset: preset && c.name in preset ? preset[c.name] : undefined,
      onchange: refresh,
    });
    fields.push(f);
    put(body, f.el);
  }
  function refresh() {
    if (!creating) saveBtn.disabled = !fields.some((f) => f.changed());
  }
  body.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'Enter' || e.key === 's')) {
      e.preventDefault();
      if (!saveBtn.disabled) saveBtn.click();
    }
  });

  async function save() {
    const values = {};
    for (const f of fields) {
      if (!f.changed() && !(creating && f.touched())) continue;
      const out = f.output();
      if (out !== undefined) values[f.c.name] = out;
    }
    for (const f of fields) f.validate && f.validate();
    if (fields.some((f) => f.invalid && f.invalid())) {
      toast('Fix the highlighted fields first.', 'err');
      return;
    }
    await busy(saveBtn, async () => {
      try {
        if (creating) {
          const r = await api('insert', { table: t, values });
          toast(r.insert_id && r.insert_id !== '0' ? `Row created (id ${r.insert_id})` : 'Row created');
          bumpCount(t, 1);
        } else {
          const r = await api('update', { table: t, key, values });
          toast(r.affected ? 'Row saved' : 'Saved — nothing changed in the database');
        }
        m.guard = null;
        m.close();
        await loadRows(st);
      } catch (e) {
        if (e.kind === 'stale') {
          m.guard = null;
          m.close();
          loadRows(st);
        }
        fail(e);
      }
    });
  }
  async function del() {
    const ok = await confirmBox({
      title: 'Delete this row?',
      message: 'It is removed at once. This cannot be undone.',
      confirm: 'Delete',
      danger: true,
    });
    if (!ok) return;
    try {
      await api('delete', { table: t, keys: [key] });
      toast('Row deleted');
      bumpCount(t, -1);
      m.guard = null;
      m.close();
      await loadRows(st);
    } catch (e) {
      fail(e);
    }
  }
}
