// The database overview: sizes, counts, and lists of tables, views, routines and triggers.

import { ENGINE, PG } from '../boot.js';
import { formatBytes, formatCompact, formatNumber } from '../lib/format.js';
import { tableLabel } from '../lib/sql.js';
import { go, openTable } from '../router.js';
import { mainEl, refreshOverview } from '../shell.js';
import { state } from '../state.js';
import { createTableDialog } from '../structure/create-table.js';
import { btn, busy, fill, h, put, spinner } from '../ui/dom.js';
import { icon } from '../ui/icons.js';

/** Draws the overview page. */
export function renderOverview() {
  const page = h('div', { class: 'page' });
  fill(mainEl, page);
  if (state.overviewError) {
    put(
      page,
      h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, state.overviewError)),
      h(
        'div',
        null,
        btn('Try again', () => refreshOverview(), '', 'refresh'),
      ),
    );
    return;
  }
  if (!state.overview) {
    put(page, h('div', { class: 'empty' }, spinner(), 'Loading the database…'));
    return;
  }
  const ov = state.overview;
  const tables = ov.tables.filter((t) => !t.type.includes('view'));
  const views = ov.tables.filter((t) => t.type.includes('view'));
  const rowsTotal = tables.reduce((s, t) => s + (t.rows > 0 ? t.rows : 0), 0);
  put(
    page,
    h(
      'div',
      { class: 'page-head' },
      h(
        'div',
        { class: 'grow' },
        h('h1', null, ov.database.name),
        h(
          'div',
          { class: 'sub' },
          h('span', null, `${ENGINE} ${ov.version || ''}`),
          ov.database.charset
            ? h('span', null, `${ov.database.charset}${ov.database.collation ? ' · ' + ov.database.collation : ''}`)
            : null,
          ov.database.timezone ? h('span', null, `Time zone ${ov.database.timezone}`) : null,
        ),
      ),
      h(
        'div',
        { class: 'row' },
        btn('Refresh', (e) => busy(e.currentTarget, refreshOverview), '', 'refresh'),
        btn('SQL console', () => go({ view: 'sql' }), '', 'terminal'),
        btn('New table', () => createTableDialog(), 'primary', 'plus'),
      ),
    ),
    h(
      'div',
      { class: 'stats' },
      stat('Size', formatBytes(ov.database.size), 'data + indexes'),
      stat('Tables', formatNumber(tables.length), PG ? `${ov.schemas.length} schemas` : null),
      stat('Views', formatNumber(views.length)),
      stat('Rows', '≈ ' + formatCompact(rowsTotal), 'estimated'),
      stat(PG ? 'Functions' : 'Routines', formatNumber(ov.routines.length)),
      stat('Triggers', formatNumber(ov.triggers.length)),
    ),
  );

  if (!ov.tables.length) {
    put(
      page,
      h(
        'div',
        { class: 'card' },
        h(
          'div',
          { class: 'empty' },
          icon('table'),
          h('div', null, 'This database has no tables yet.'),
          btn('Create a table', () => createTableDialog(), 'primary', 'plus'),
        ),
      ),
    );
  } else {
    const maxSize = Math.max(1, ...ov.tables.map((t) => t.size || 0));
    put(
      page,
      h(
        'div',
        { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Tables and views')),
        h(
          'div',
          { style: 'overflow:auto' },
          h(
            'table',
            { class: 'list' },
            h(
              'thead',
              null,
              h(
                'tr',
                null,
                h('th', null, 'Name'),
                h('th', null, 'Type'),
                h('th', { class: 'num' }, 'Rows'),
                h('th', { class: 'num' }, 'Size'),
                h('th', { style: 'width:140px' }),
                PG ? null : h('th', null, 'Engine'),
                h('th', null, 'Comment'),
              ),
            ),
            h(
              'tbody',
              null,
              ov.tables.map((t) =>
                h(
                  'tr',
                  { class: 'click', onclick: () => openTable(t) },
                  h(
                    'td',
                    null,
                    h(
                      'span',
                      { class: 'row' },
                      icon(t.type.includes('view') ? 'view' : 'table'),
                      h('span', null, tableLabel(t)),
                    ),
                  ),
                  h('td', null, h('span', { class: 'badge' }, t.type)),
                  h('td', { class: 'num' }, t.rows !== null && t.rows >= 0 ? '≈ ' + formatNumber(t.rows) : '—'),
                  h('td', { class: 'num' }, formatBytes(t.size)),
                  h(
                    'td',
                    null,
                    t.size
                      ? h(
                          'div',
                          { class: 'bar' },
                          h('i', { style: `width:${Math.max(2, Math.round((t.size / maxSize) * 100))}%` }),
                        )
                      : null,
                  ),
                  PG ? null : h('td', { class: 'muted' }, t.engine || ''),
                  h(
                    'td',
                    {
                      class: 'muted',
                      style: 'max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap',
                    },
                    t.comment || '',
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  const extras = [];
  if (ov.routines.length) {
    extras.push(
      listCard(
        PG ? 'Functions and procedures' : 'Routines',
        ['Name', 'Type', PG ? 'Arguments' : null],
        ov.routines.map((x) => [routineLabel(x), x.type, PG ? x.args || '' : null]),
      ),
    );
  }
  if (ov.triggers.length)
    extras.push(
      listCard(
        'Triggers',
        ['Name', 'Table', 'When'],
        ov.triggers.map((x) => [x.name, x.table, [x.timing, x.event].filter(Boolean).join(' ')]),
      ),
    );
  if (ov.events && ov.events.length)
    extras.push(
      listCard(
        'Scheduled events',
        ['Name', 'Status', 'Every'],
        ov.events.map((x) => [x.name, x.status, x.every || x.type]),
      ),
    );
  if (ov.extensions && ov.extensions.length)
    extras.push(
      listCard(
        'Extensions',
        ['Name', 'Version'],
        ov.extensions.map((x) => [x.name, x.version]),
      ),
    );
  if (extras.length) put(page, h('div', { class: 'grid2' }, extras));
}

/** A routine's name for people, as tableLabel() does for tables. */
const routineLabel = (x) => (PG && x.schema && x.schema !== 'public' ? x.schema + '.' + x.name : x.name);

/** A tile with a label, a value and an optional line below. */
export const stat = (k, v, s) =>
  h(
    'div',
    { class: 'stat' },
    h('div', { class: 'k' }, k),
    h('div', { class: 'v', title: String(v) }, v),
    s ? h('div', { class: 's' }, s) : null,
  );

/** A card with a simple table. A null heading leaves that column out. */
function listCard(title, heads, rows) {
  const cols = heads.map((x, i) => (x === null ? null : i)).filter((x) => x !== null);
  return h(
    'div',
    { class: 'card', style: 'min-width:0' },
    h('div', { class: 'card-head' }, h('h3', null, title), h('span', { class: 'badge' }, String(rows.length))),
    h(
      'div',
      { style: 'overflow:auto;max-height:320px' },
      h(
        'table',
        { class: 'list' },
        h(
          'thead',
          null,
          h(
            'tr',
            null,
            cols.map((i) => h('th', null, heads[i])),
          ),
        ),
        h(
          'tbody',
          null,
          rows.map((r) =>
            h(
              'tr',
              null,
              cols.map((i) => h('td', { class: i === 0 ? 'mono' : 'muted' }, r[i] ?? '')),
            ),
          ),
        ),
      ),
    ),
  );
}
