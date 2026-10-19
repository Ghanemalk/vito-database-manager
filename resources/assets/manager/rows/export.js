// Exporting rows as CSV, JSON or SQL.

import { api } from '../api.js';
import { download } from '../lib/files.js';
import { plural } from '../lib/format.js';
import { tableLabel } from '../lib/sql.js';
import { btn, busy, check, field, fill, h, input, select } from '../ui/dom.js';
import { modal } from '../ui/modals.js';
import { fail, toast } from '../ui/toasts.js';

/** The Export dialog: CSV, JSON or SQL, of every row or of the rows the search and filters leave. */
export function exportDialog(st) {
  let format = 'csv',
    scope = st.filters.length || st.search ? 'filtered' : 'all',
    structure = true;
  const limit = input({ placeholder: 'All rows', inputmode: 'numeric' });
  const opts = h('div');
  const draw = () =>
    fill(opts, format === 'sql' ? check('Include CREATE TABLE', structure, (v) => (structure = v)) : '');
  const fmt = select(
    [
      ['csv', 'CSV (Excel, Sheets)'],
      ['json', 'JSON'],
      ['sql', 'SQL INSERT statements'],
    ],
    format,
    {
      onchange: (e) => {
        format = e.target.value;
        draw();
      },
    },
  );
  const scopeSel = select(
    [
      ['filtered', 'Rows matching the search and filters'],
      ['all', 'All rows'],
    ],
    scope,
    { onchange: (e) => (scope = e.target.value), disabled: !(st.filters.length || st.search) },
  );
  draw();
  const go2 = btn(
    'Download',
    () =>
      busy(go2, async () => {
        try {
          const args = { table: st.table, format, sort: st.sort, limit: parseInt(limit.value, 10) || 0, structure };
          if (scope === 'filtered') Object.assign(args, { filters: st.filters, search: st.search });
          const r = await api('export', args);
          download(r.data, r.filename, r.mime + ';charset=utf-8');
          toast(`${plural(r.rows, 'row')} exported`);
          m.close();
        } catch (e) {
          fail(e);
        }
      }),
    'primary',
    'download',
  );
  const m = modal({
    title: `Export ${tableLabel(st.table)}`,
    body: [
      field('Format', fmt),
      field('Rows', scopeSel),
      field('Limit', limit, 'Leave empty to export every row. Very large tables are better exported with a backup.'),
      opts,
    ],
    foot: [h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), go2],
  });
}
