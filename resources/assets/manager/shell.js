// The frame around every page: the top bar, the table list on the side, and render(), which fills the main area for
// the current route.

import { api } from './api.js';
import { BOOT, EMBED, ENGINE, PG, SPONSOR, STORAGE_PREFIX, tellVito } from './boot.js';
import { formatCompact } from './lib/format.js';
import { sameTable, tableLabel } from './lib/sql.js';
import { storage } from './lib/storage.js';
import { renderOverview } from './pages/overview.js';
import { renderMonitor } from './pages/processes.js';
import { renderSql } from './pages/sql-console.js';
import { renderTable } from './pages/table.js';
import { go, openTable, parseHash } from './router.js';
import { state } from './state.js';
import { createTableDialog } from './structure/create-table.js';
import { btn, fill, h, iconBtn, input, put, select, spinner } from './ui/dom.js';
import { icon } from './ui/icons.js';
import { closePopover } from './ui/popovers.js';

export const app = h('div', { class: 'app' });
export const sideEl = h('aside', { class: 'side', 'aria-label': 'Tables' });
export const mainEl = h('main', { class: 'main' });
// On narrow screens the table list slides over the page; a tap on the page closes it.
mainEl.addEventListener('pointerdown', () => app.classList.remove('side-open'));

/** The bar at the top: back to Databases, the database switch, the engine and Sponsor. */
function topBar() {
  const dbSelect = select(
    BOOT.databases.map((d) => [String(d.id), d.name + (d.ready ? '' : ' (not ready)')]),
    BOOT.database ? String(BOOT.database.id) : '',
    {
      class: 'select sm',
      style: 'width:auto;max-width:240px',
      'aria-label': 'Database',
      onchange: (e) => {
        const d = BOOT.databases.find((x) => String(x.id) === e.target.value);
        if (d) location.href = d.url;
      },
    },
  );
  // the server's own version once the overview has it ("8.4.3", without a "-log" or "-MariaDB" suffix)
  const version = state.overview?.version ? String(state.overview.version).replace(/-.*$/, '') : BOOT.engine.version;
  return h(
    'header',
    { class: 'top' },
    iconBtn('menu', 'Tables', () => app.classList.toggle('side-open'), 'ghost side-toggle'),
    h(
      'a',
      {
        class: 'btn ghost sm',
        href: BOOT.back,
        target: '_top',
        title: 'Back to the Databases page',
        onclick: (e) => {
          if (!EMBED) return;
          e.preventDefault();
          tellVito({ type: 'close' });
        },
      },
      icon('back'),
      h('span', { class: 'hide-sm' }, 'Databases'),
    ),
    // inside Vito its header already names the server
    h(
      'div',
      { class: 'crumbs' },
      EMBED ? null : h('span', { class: 'brand hide-sm' }, icon('database'), BOOT.server.name),
      EMBED ? null : h('span', { class: 'sep hide-sm' }, '/'),
      dbSelect,
    ),
    h('span', { class: 'spacer' }),
    h('span', { class: 'engine-badge hide-sm', title: state.overview?.version_comment || '' }, ENGINE + ' ' + version),
    h(
      'a',
      {
        class: 'btn sm sponsor',
        href: SPONSOR,
        target: '_blank',
        rel: 'noopener',
        title: 'Sponsor the Database Manager on GitHub',
      },
      icon('heart'),
      h('span', { class: 'hide-sm' }, 'Sponsor'),
    ),
  );
}

/** The side list: New table, the search, the tables and views, and the links to the other pages. */
function sideBar() {
  const r = state.route;
  const head = h('div', { class: 'side-head' });
  const list = h('div', { class: 'side-list' });
  const nav = h(
    'div',
    { class: 'nav' },
    navItem('layers', 'Overview', r.view === 'overview', () => go({ view: 'overview' })),
    navItem('terminal', 'SQL console', r.view === 'sql', () => go({ view: 'sql' })),
    navItem('activity', 'Processes', r.view === 'monitor', () => go({ view: 'monitor' })),
  );
  put(
    head,
    btn(
      'New table',
      () => {
        app.classList.remove('side-open');
        createTableDialog();
      },
      'primary sm',
      'plus',
    ),
  );
  const search = input({
    class: 'input sm',
    placeholder: 'Search tables',
    value: state.sideFilter,
    'aria-label': 'Search tables',
  });
  search.addEventListener('input', () => {
    state.sideFilter = search.value;
    fillList();
  });
  put(head, h('div', { class: 'input-icon' }, icon('search'), search));
  if (PG && state.overview && state.overview.schemas) {
    const schemas = [['*', 'All schemas'], ...state.overview.schemas.map((s) => [s, s])];
    put(
      head,
      select(schemas, state.schema, {
        class: 'select sm',
        'aria-label': 'Schema',
        onchange: (e) => {
          state.schema = e.target.value;
          storage.set(STORAGE_PREFIX + ':schema', state.schema);
          fillList();
        },
      }),
    );
  }

  function fillList() {
    fill(list);
    if (state.overviewError) {
      put(list, h('div', { class: 'empty small' }, icon('alert'), state.overviewError));
      return;
    }
    if (!state.overview) {
      put(list, h('div', { class: 'empty' }, spinner()));
      return;
    }
    const term = state.sideFilter.trim().toLowerCase();
    const visible = state.overview.tables.filter(
      (t) =>
        (!PG || state.schema === '*' || t.schema === state.schema) && (!term || t.name.toLowerCase().includes(term)),
    );
    const groups = [
      ['Tables', visible.filter((t) => ['table', 'partitioned', 'foreign'].includes(t.type))],
      ['Views', visible.filter((t) => ['view', 'matview', 'system view'].includes(t.type))],
      [
        'Other',
        visible.filter((t) => !['table', 'partitioned', 'foreign', 'view', 'matview', 'system view'].includes(t.type)),
      ],
    ];
    let any = false;
    for (const [label, items] of groups) {
      if (!items.length) continue;
      any = true;
      put(list, h('div', { class: 'group' }, label, h('span', { class: 'muted' }, `· ${items.length}`)));
      for (const t of items) {
        const active = r.view === 'table' && sameTable(r.table, t);
        put(
          list,
          h(
            'button',
            {
              type: 'button',
              class: 'item' + (active ? ' active' : ''),
              title: (PG ? t.schema + '.' : '') + t.name + (t.comment ? ' — ' + t.comment : ''),
              style: t.partition_of ? 'padding-left:24px' : null,
              onclick: () => {
                app.classList.remove('side-open');
                openTable(t);
              },
            },
            icon(t.type.includes('view') ? 'view' : 'table'),
            h('span', { class: 'name' }, PG && state.schema === '*' ? tableLabel(t) : t.name),
            t.rows !== null && t.rows !== undefined && t.rows >= 0
              ? h('span', { class: 'count', title: 'Estimated rows' }, formatCompact(t.rows))
              : null,
          ),
        );
      }
    }
    if (!any) put(list, h('div', { class: 'empty small' }, term ? 'No table matches.' : 'No tables yet.'));
  }
  fillList();
  return [head, list, nav];
}

const navItem = (ic, label, active, onclick) =>
  h(
    'button',
    { type: 'button', class: 'item' + (active ? ' active' : ''), onclick },
    icon(ic),
    h('span', { class: 'name' }, label),
  );

/** Draws the top bar and the side list; the main area keeps what it shows. */
export function renderShell() {
  fill(app, topBar(), sideEl, mainEl);
  redrawSideBar();
}

/** Draws the side list again, for instance after a table's row count changed. */
export function redrawSideBar() {
  fill(sideEl, ...sideBar());
}

/** Loads the overview (the list of tables and more) into state.overview. */
export async function loadOverview() {
  try {
    state.overview = await api('overview');
    state.overviewError = null;
    if (PG && state.schema !== '*' && !state.overview.schemas.includes(state.schema))
      state.schema = state.overview.schemas.includes('public') ? 'public' : '*';
  } catch (e) {
    state.overviewError = e.message;
  }
}

/** Loads the overview again and redraws what shows it. */
export async function refreshOverview() {
  await loadOverview();
  renderShell();
  if (state.route.view === 'overview') render();
}

/** Stops the Processes page's refresh. */
export function stopMonitor() {
  if (state.monitorTimer) {
    clearInterval(state.monitorTimer);
    state.monitorTimer = null;
  }
}

/** Draws the page for the address bar: the frame and the view it names. */
export function render() {
  closePopover();
  app.classList.remove('side-open');
  state.route = parseHash();
  stopMonitor();
  renderShell();
  mainEl.scrollTop = 0;
  const r = state.route;
  if (!BOOT.database) {
    fill(
      mainEl,
      h(
        'div',
        { class: 'page' },
        h(
          'div',
          { class: 'empty' },
          icon('database'),
          'This server has no databases in Vito yet. Create one on the Databases page.',
        ),
      ),
    );
    return;
  }
  if (r.view === 'table') renderTable(r.table, r.tab);
  else if (r.view === 'sql') renderSql();
  else if (r.view === 'monitor') renderMonitor();
  else renderOverview();
  const label =
    r.view === 'table' ? tableLabel(r.table) : r.view === 'sql' ? 'SQL' : r.view === 'monitor' ? 'Processes' : null;
  document.title = [label, BOOT.database?.name, BOOT.server.name].filter(Boolean).join(' · ');
  tellVito({ type: 'state', db: BOOT.database ? BOOT.database.id : null, hash: location.hash, title: document.title });
}
