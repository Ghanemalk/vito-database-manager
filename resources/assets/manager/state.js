// What the app holds while it runs: the overview, the current route, and one state object per table visited.

import { PG, STORAGE_PREFIX } from './boot.js';
import { tableKey } from './lib/sql.js';
import { storage } from './lib/storage.js';

export const state = {
  // the API's answer to 'overview' (the database, its tables, schemas, routines and triggers), or why it failed
  overview: null,
  overviewError: null,
  // PostgreSQL: the schema the side list shows, '*' for all
  schema: PG ? storage.get(STORAGE_PREFIX + ':schema', 'public') : null,
  sideFilter: '', // the text in the side list's search box
  route: { view: 'overview' }, // what the address bar shows, see router.js
  tables: new Map(), // per-table state, kept while switching tables
  monitorTimer: null, // the Processes page's refresh timer
};

/**
 * The state of one table (t is { schema, name }), made on the first visit and kept while switching tables. The code
 * that works on a table calls it st.
 *
 *   table      { schema, name }
 *   page, per  the page of rows shown and the rows per page
 *   sort       [[column, 'asc' | 'desc'], …], the first one sorts first
 *   filters    [{ col, op, value, value2 }, …], see rows/filters.js
 *   search     the text in the search box, matched against every column
 *   hidden     the columns hidden in the grid
 *   widths     the column widths set by dragging, in pixels
 *   data       the API's last answer to 'rows' (columns, rows, total, key…); null until loaded or after a change
 *   structure  the API's answer to 'structure' (columns, indexes, keys…); null until loaded or after a change
 *   selected   the rows ticked in the grid
 *   seq        counts requests for rows, so an answer that arrives after a newer request is dropped
 *   loading, error
 *   onchange   set by the Rows tab: redraws it when loading starts or ends
 *
 * per, hidden and widths are remembered in the browser.
 */
export function tableState(t) {
  const k = tableKey(t);
  if (!state.tables.has(k)) {
    state.tables.set(k, {
      table: { schema: t.schema ?? null, name: t.name },
      page: 1,
      per: storage.get(STORAGE_PREFIX + ':per', 50),
      sort: [],
      filters: [],
      search: '',
      hidden: new Set(storage.get(STORAGE_PREFIX + ':hidden:' + k, [])),
      widths: storage.get(STORAGE_PREFIX + ':widths:' + k, {}),
      data: null,
      structure: null,
      selected: new Set(),
      seq: 0,
      loading: false,
      error: null,
    });
  }
  return state.tables.get(k);
}

/** After a change to the database: every table loads its rows and structure again when next shown. */
export function invalidateTables() {
  for (const st of state.tables.values()) {
    st.data = null;
    st.structure = null;
  }
}
