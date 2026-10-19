// What the structure tabs (Columns, Indexes, Relations, Settings) share: loading the structure and running a change.

import { api } from '../api.js';
import { sameTable } from '../lib/sql.js';
import { refreshOverview, render } from '../shell.js';
import { invalidateTables, state, tableState } from '../state.js';
import { fill, h, spinner } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmBox } from '../ui/modals.js';
import { fail } from '../ui/toasts.js';
import { columnsTab } from './columns.js';
import { indexesTab } from './indexes.js';
import { relationsTab } from './relations.js';
import { settingsTab } from './settings.js';

/** Loads the table's structure into st.structure, unless it is loaded already. */
async function loadStructure(st, force = false) {
  if (st.structure && !force) return st.structure;
  st.structure = await api('structure', { table: st.table });
  return st.structure;
}

/** Draws one of the structure tabs once the structure has loaded. */
export function renderStructureTab(st, tab, body) {
  fill(body, h('div', { class: 'empty' }, spinner(), 'Loading the structure…'));
  loadStructure(st)
    .then((s) => {
      if (state.route.view !== 'table' || !sameTable(state.route.table, st.table) || state.route.tab !== tab) return;
      fill(body);
      if (tab === 'columns') columnsTab(st, s, body);
      else if (tab === 'indexes') indexesTab(st, s, body);
      else if (tab === 'relations') relationsTab(st, s, body);
      else settingsTab(st, s, body);
    })
    .catch((e) =>
      fill(body, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message))),
    );
}

/** Runs one structure change: an SQL preview to confirm, then the change. */
export async function runDdl(
  args,
  { title, message, confirm = 'Apply', danger = false, typed = null, skipPreview = false } = {},
) {
  let sql;
  try {
    sql = (await api('ddl', { ...args, dry: true })).sql;
  } catch (e) {
    fail(e);
    return null;
  }
  if (!skipPreview) {
    const ok = await confirmBox({ title, message, sql, confirm, danger, typed });
    if (!ok) return null;
  }
  try {
    const r = await api('ddl', args);
    afterDdl(args);
    return r;
  } catch (e) {
    fail(e);
    return null;
  }
}

/** After a structure change: everything that showed the table loads again. */
export function afterDdl(args) {
  const t = args.table && typeof args.table === 'object' ? args.table : null;
  if (t) {
    const st = tableState(t);
    st.structure = null;
    st.data = null;
  }
  invalidateTables();
  refreshOverview().then(() => state.route.view === 'table' && render());
}
