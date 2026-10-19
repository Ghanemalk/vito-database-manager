// A table's page: its header and tabs. The Rows tab is in rows/, the other tabs in structure/.

import { formatBytes, plural } from '../lib/format.js';
import { sameTable, tableLabel, tableSql } from '../lib/sql.js';
import { go, openTable } from '../router.js';
import { renderRows } from '../rows/grid.js';
import { rowEditor } from '../rows/row-editor.js';
import { mainEl } from '../shell.js';
import { state, tableState } from '../state.js';
import { renderStructureTab } from '../structure/common.js';
import { btn, fill, h, put } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { saveSqlDraft } from './sql-console.js';

/** The table's entry in the overview (type, size, estimated rows…), or null. */
export function tableInfo(t) {
  return state.overview ? state.overview.tables.find((x) => sameTable(x, t)) : null;
}

/** Draws a table's page with the given tab. */
export function renderTable(t, tab) {
  const st = tableState(t);
  const info = tableInfo(t);
  if (state.overview && !info && !st.data) {
    fill(
      mainEl,
      h(
        'div',
        { class: 'page' },
        h(
          'div',
          { class: 'empty' },
          icon('table'),
          `The table ${tableLabel(t)} does not exist (any more).`,
          btn('Back to the overview', () => go({ view: 'overview' })),
        ),
      ),
    );
    return;
  }
  const type = info?.type || st.data?.type || 'table';
  const isView = type.includes('view');
  const tabs = [
    ['rows', 'Rows', 'list'],
    ['columns', 'Columns', 'columns'],
    ['indexes', 'Indexes', 'key'],
    ['relations', 'Relations', 'link'],
    ['settings', 'Settings', 'sliders'],
  ];
  const page = h('div', { class: 'page' });
  const body = h('div', { class: 'stack' });
  const headSub = h(
    'div',
    { class: 'sub' },
    h('span', { class: 'badge' }, type),
    info && info.rows !== null && info.rows >= 0 ? h('span', null, '≈ ' + plural(info.rows, 'row')) : null,
    info && info.size ? h('span', null, formatBytes(info.size)) : null,
    info && info.engine ? h('span', null, info.engine) : null,
    info && info.comment ? h('span', null, info.comment) : null,
  );
  put(
    page,
    h(
      'div',
      { class: 'page-head' },
      h('div', { class: 'grow' }, h('h1', null, tableLabel(t)), headSub),
      h(
        'div',
        { class: 'row' },
        btn(
          'Query',
          () => {
            saveSqlDraft(`SELECT *\nFROM ${tableSql(t)}\nLIMIT 100;`);
            go({ view: 'sql' });
          },
          '',
          'terminal',
        ),
        isView ? null : btn('Add row', () => rowEditor(t, null), 'primary', 'plus'),
      ),
    ),
    h(
      'div',
      { class: 'tabs', role: 'tablist' },
      tabs.map(([id, label, ic]) =>
        h(
          'button',
          {
            type: 'button',
            role: 'tab',
            class: tab === id ? 'active' : '',
            'aria-selected': tab === id ? 'true' : 'false',
            onclick: () => openTable(t, id),
          },
          icon(ic),
          label,
        ),
      ),
    ),
    body,
  );
  fill(mainEl, page);
  page.classList.toggle('fill', tab === 'rows');
  if (tab === 'rows') renderRows(st, body);
  else renderStructureTab(st, tab, body);
}
