// The Rows tab: the grid with sorting, searching, paging, column widths and selection.

import { api } from '../api.js';
import { STORAGE_PREFIX } from '../boot.js';
import { debounce, formatNumber, plural } from '../lib/format.js';
import { sameTable, tableKey, tableLabel } from '../lib/sql.js';
import { storage } from '../lib/storage.js';
import { tableInfo } from '../pages/table.js';
import { redrawSideBar } from '../shell.js';
import { shortType } from '../structure/types.js';
import { btn, check, fill, h, iconBtn, input, put, select, spinner } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmBox } from '../ui/modals.js';
import { closePopover, popover } from '../ui/popovers.js';
import { fail, toast } from '../ui/toasts.js';
import { NUMERIC_CATEGORIES, cellView } from '../values.js';
import { exportDialog } from './export.js';
import { FILTER_OP_LABELS, filterPopover, opTakesNoValue } from './filters.js';
import { importDialog } from './import.js';
import { inlineEdit, inlineEditable } from './inline-edit.js';
import { rowEditor } from './row-editor.js';

/** Loads the current page of rows into st.data. */
export async function loadRows(st) {
  const seq = ++st.seq;
  st.loading = true;
  st.error = null;
  st.onchange && st.onchange();
  try {
    const data = await api('rows', {
      table: st.table,
      page: st.page,
      per: st.per,
      sort: st.sort,
      filters: st.filters,
      search: st.search,
    });
    if (seq !== st.seq) return;
    st.data = data;
    st.selected.clear();
    const pages = data.total !== null ? Math.max(1, Math.ceil(data.total / st.per)) : null;
    if (pages !== null && st.page > pages && !data.approx) {
      st.page = pages;
      return loadRows(st);
    }
  } catch (e) {
    if (seq !== st.seq) return;
    st.error = e;
  } finally {
    if (seq === st.seq) {
      st.loading = false;
      st.onchange && st.onchange();
    }
  }
}

/** Draws the Rows tab into body and keeps it up to date (st.onchange). */
export function renderRows(st, body) {
  const t = st.table;
  const search = input({
    class: 'input sm',
    placeholder: 'Search all columns',
    value: st.search,
    'aria-label': 'Search rows',
  });
  const runSearch = () => {
    if (search.value === st.search) return;
    st.search = search.value;
    st.page = 1;
    loadRows(st);
  };
  search.addEventListener('input', debounce(runSearch, 450));
  search.addEventListener('keydown', (e) => e.key === 'Enter' && runSearch());
  const filterBtn = btn('Filter', (e) => filterPopover(e.currentTarget, st), 'sm', 'filter');
  const colsBtn = btn('Columns', (e) => columnsPopover(e.currentTarget, st), 'sm', 'columns');
  const refreshBtn = iconBtn('refresh', 'Reload', () => loadRows(st), 'sm');
  const delBtn = btn('', () => deleteSelected(st), 'danger sm hidden', 'trash');
  const importBtn = btn('Import', () => importDialog(st), 'sm', 'upload');
  const exportBtn = btn('Export', () => exportDialog(st), 'sm', 'download');
  const toolbar = h(
    'div',
    { class: 'toolbar' },
    h('div', { class: 'input-icon' }, icon('search'), search),
    filterBtn,
    colsBtn,
    refreshBtn,
    delBtn,
    h('span', { class: 'grow' }),
    importBtn,
    exportBtn,
  );
  const chips = h('div', { class: 'chips' });
  const notice = h('div');
  const wrap = h('div', { class: 'grid-wrap' });
  const foot = h('div', { class: 'grid-foot' });
  const styleEl = h('style');
  put(body, toolbar, chips, notice, wrap, foot, styleEl);

  function draw() {
    const d = st.data;
    const editable = d ? ['table', 'partitioned'].includes(d.type) && d.key.mode !== 'none' : false;
    importBtn.classList.toggle('hidden', !!d && !['table', 'partitioned'].includes(d.type));
    // chips
    fill(
      chips,
      ...st.filters.map((f, i) =>
        h(
          'span',
          { class: 'chip' },
          h(
            'span',
            null,
            h('b', null, f.col),
            ' ',
            FILTER_OP_LABELS[f.op] || f.op,
            opTakesNoValue(f.op) ? '' : ' ',
            opTakesNoValue(f.op)
              ? ''
              : h('span', { class: 'mono' }, f.op === 'between' ? `${f.value} and ${f.value2}` : f.value),
          ),
          h(
            'button',
            {
              type: 'button',
              title: 'Remove filter',
              onclick: () => {
                st.filters.splice(i, 1);
                st.page = 1;
                loadRows(st);
              },
            },
            icon('x'),
          ),
        ),
      ),
    );
    if (st.filters.length > 1)
      put(
        chips,
        h(
          'button',
          {
            type: 'button',
            class: 'btn ghost sm',
            onclick: () => {
              st.filters = [];
              st.page = 1;
              loadRows(st);
            },
          },
          'Clear filters',
        ),
      );
    filterBtn.querySelector('span').textContent = st.filters.length ? `Filter · ${st.filters.length}` : 'Filter';
    const hiddenCount = d ? d.columns.filter((c) => st.hidden.has(c.name)).length : 0;
    colsBtn.querySelector('span').textContent = hiddenCount ? `Columns · ${hiddenCount} hidden` : 'Columns';
    delBtn.classList.toggle('hidden', !st.selected.size);
    fill(delBtn, icon('trash'), h('span', null, `Delete ${st.selected.size}`));

    fill(notice);
    if (d && d.key.mode === 'hash')
      put(
        notice,
        h(
          'div',
          { class: 'alert warn' },
          icon('alert'),
          h(
            'div',
            { class: 'a-body' },
            'This table has no primary or unique key. Rows are matched by their full content, so a change applies to one of any identical rows. Add a primary key for reliable editing.',
          ),
        ),
      );
    if (d && d.key.mode === 'ctid')
      put(
        notice,
        h(
          'div',
          { class: 'alert warn' },
          icon('alert'),
          h(
            'div',
            { class: 'a-body' },
            'This table has no primary or unique key. Rows are matched by their physical location (ctid), which changes when a row is updated — reload before editing again. Add a primary key for reliable editing.',
          ),
        ),
      );
    if (d && !['table', 'partitioned'].includes(d.type))
      put(
        notice,
        h(
          'div',
          { class: 'alert info' },
          icon('info'),
          h('div', { class: 'a-body' }, `This is a ${d.type}: its rows are read-only here.`),
        ),
      );
    if (d && d.count_error)
      put(
        notice,
        h(
          'div',
          { class: 'alert warn' },
          icon('alert'),
          h('div', { class: 'a-body' }, 'Counting the rows failed: ' + d.count_error),
        ),
      );

    fill(wrap);
    if (st.loading) put(wrap, h('div', { class: 'loading-bar' }));
    if (st.error) {
      put(
        wrap,
        h(
          'div',
          { class: 'card-body' },
          h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, st.error.message)),
        ),
      );
      fill(foot);
      return;
    }
    if (!d) {
      put(wrap, h('div', { class: 'empty' }, spinner()));
      return;
    }
    const cols = d.columns.map((c, i) => ({ c, i })).filter((x) => !st.hidden.has(x.c.name));
    const keyCols = new Set(['pk', 'unique'].includes(d.key.mode) ? d.key.cols : []);
    const allBox = h('input', {
      type: 'checkbox',
      'aria-label': 'Select all rows on this page',
      checked: d.rows.length > 0 && st.selected.size === d.rows.length,
      indeterminate: st.selected.size > 0 && st.selected.size < d.rows.length,
      disabled: !editable,
      onchange: (e) => {
        st.selected = e.target.checked ? new Set(d.rows.map((_, i) => i)) : new Set();
        draw();
      },
    });
    const sortOf = (name) => st.sort.findIndex((s) => s[0] === name);
    const thead = h(
      'thead',
      null,
      h(
        'tr',
        null,
        h('th', { class: 'c-check' }, allBox),
        h('th', { class: 'c-open' }),
        cols.map(({ c }) => {
          const si = sortOf(c.name);
          const th = h(
            'th',
            { title: `${c.name} · ${c.type}${c.comment ? ' — ' + c.comment : ''}` },
            h(
              'div',
              { class: 'th', onclick: (e) => toggleSort(st, c.name, e.shiftKey) },
              keyCols.has(c.name) ? icon('key') : null,
              h('span', { class: 't' }, c.name),
              h('span', { class: 'type' }, shortType(c)),
              si >= 0
                ? h(
                    'span',
                    { class: 'sort' },
                    icon(st.sort[si][1] === 'desc' ? 'dn' : 'up'),
                    st.sort.length > 1 ? String(si + 1) : '',
                  )
                : null,
            ),
            h('span', {
              class: 'rs',
              onmousedown: (e) => startResize(e, st, c.name, th, draw),
              onclick: (e) => e.stopPropagation(),
            }),
          );
          return th;
        }),
      ),
    );
    const tbody = h('tbody');
    d.rows.forEach((row, ri) => {
      const tr = h('tr', { class: st.selected.has(ri) ? 'sel' : '' });
      const box = h('input', {
        type: 'checkbox',
        'aria-label': 'Select row',
        checked: st.selected.has(ri),
        disabled: !editable,
        onchange: (e) => {
          e.target.checked ? st.selected.add(ri) : st.selected.delete(ri);
          draw();
        },
      });
      put(
        tr,
        h('td', { class: 'c-check' }, box),
        h(
          'td',
          { class: 'c-open' },
          h(
            'button',
            {
              type: 'button',
              class: 'open-row',
              title: editable ? 'Open and edit the row' : 'Open the row',
              'aria-label': 'Open the row',
              onclick: () => rowEditor(t, ri),
            },
            icon('open'),
          ),
        ),
      );
      for (const { c, i } of cols) {
        const v = row[i];
        const td = h(
          'td',
          {
            class:
              (NUMERIC_CATEGORIES.has(c.cat) && typeof v === 'string' ? 'num' : '') +
              (['json', 'bin', 'geo', 'vector', 'array'].includes(c.cat) ? ' mono' : ''),
          },
          cellView(v, c),
        );
        if (typeof v === 'string' && v.length > 40) td.title = v.length > 1000 ? v.slice(0, 1000) + '…' : v;
        // double-click edits a short value in place; anything else opens the row
        td.addEventListener('dblclick', (e) => {
          if (td.classList.contains('editing')) return;
          e.preventDefault();
          window.getSelection().removeAllRanges();
          if (editable && inlineEditable(c, v)) inlineEdit(st, td, ri, c, v);
          else rowEditor(t, ri);
        });
        put(tr, td);
      }
      put(tbody, tr);
    });
    const table = h('table', { class: 'grid' }, thead, tbody);
    put(wrap, table);
    if (!d.rows.length) {
      put(
        wrap,
        h(
          'div',
          { class: 'empty' },
          icon(st.filters.length || st.search ? 'search' : 'table'),
          st.filters.length || st.search ? 'No rows match the search and filters.' : 'This table is empty.',
          !st.filters.length && !st.search && editable
            ? btn('Add the first row', () => rowEditor(t, null), 'primary sm', 'plus')
            : null,
        ),
      );
    }
    // widths of resized columns
    styleEl.textContent = cols
      .map(({ c }, k) =>
        st.widths[c.name]
          ? `table.grid tr > :nth-child(${k + 3}){min-width:${st.widths[c.name]}px;max-width:${st.widths[c.name]}px;width:${st.widths[c.name]}px}`
          : '',
      )
      .join('\n');

    // footer
    const from = d.rows.length ? (d.page - 1) * d.per + 1 : 0;
    const to = (d.page - 1) * d.per + d.rows.length;
    const pages = d.total !== null ? Math.max(1, Math.ceil(d.total / d.per)) : null;
    const pageInput = input({ class: 'input sm', value: String(d.page), 'aria-label': 'Page' });
    pageInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        const p = Math.max(1, Math.min(pages ?? Infinity, parseInt(pageInput.value, 10) || 1));
        st.page = p;
        loadRows(st);
      }
    });
    const perSel = select(
      [25, 50, 100, 250, 500].map((n) => [String(n), `${n} per page`]),
      String(st.per),
      {
        class: 'select sm',
        style: 'width:auto',
        onchange: (e) => {
          st.per = parseInt(e.target.value, 10);
          storage.set(STORAGE_PREFIX + ':per', st.per);
          st.page = 1;
          loadRows(st);
        },
      },
    );
    fill(
      foot,
      h(
        'span',
        null,
        d.rows.length
          ? `${formatNumber(from)}–${formatNumber(to)} of ${d.approx ? '≈ ' : ''}${d.total === null ? '?' : formatNumber(d.total)}`
          : `${d.approx ? '≈ ' : ''}${formatNumber(d.total ?? 0)} rows`,
      ),
      st.selected.size ? h('span', null, `· ${st.selected.size} selected`) : null,
      h(
        'div',
        { class: 'pager' },
        perSel,
        iconBtn(
          'left',
          'Previous page',
          () => {
            st.page = Math.max(1, d.page - 1);
            loadRows(st);
          },
          'sm' + (d.page <= 1 ? '' : ''),
        ),
        pageInput,
        pages !== null ? h('span', null, `of ${formatNumber(pages)}`) : null,
        iconBtn(
          'right',
          'Next page',
          () => {
            st.page = d.page + 1;
            loadRows(st);
          },
          'sm',
        ),
      ),
    );
    const [prev, next] = foot.querySelectorAll('.pager .btn');
    prev.disabled = d.page <= 1;
    next.disabled = pages !== null ? d.page >= pages : d.rows.length < d.per;
  }
  st.onchange = draw;
  draw();
  if (!st.data || !sameTable(st.data.table, t)) loadRows(st);
}

/** A click on a column heading: ascending, descending, then unsorted. With Shift (multi) it adds to the sort. */
function toggleSort(st, name, multi) {
  const i = st.sort.findIndex((s) => s[0] === name);
  const cur = i >= 0 ? st.sort[i][1] : null;
  const next = cur === null ? 'asc' : cur === 'asc' ? 'desc' : null;
  if (!multi) st.sort = next ? [[name, next]] : [];
  else if (i >= 0) next ? (st.sort[i] = [name, next]) : st.sort.splice(i, 1);
  else st.sort.push([name, 'asc']);
  st.page = 1;
  loadRows(st);
}

/** Drags a column's edge to set its width. */
function startResize(e, st, name, th, redraw) {
  e.preventDefault();
  e.stopPropagation();
  const x0 = e.clientX;
  const w0 = th.getBoundingClientRect().width;
  const move = (ev) => {
    st.widths[name] = Math.max(60, Math.round(w0 + ev.clientX - x0));
    th.style.minWidth = th.style.maxWidth = th.style.width = st.widths[name] + 'px';
  };
  const up = () => {
    document.removeEventListener('mousemove', move);
    document.removeEventListener('mouseup', up);
    storage.set(STORAGE_PREFIX + ':widths:' + tableKey(st.table), st.widths);
    redraw();
  };
  document.addEventListener('mousemove', move);
  document.addEventListener('mouseup', up);
}

/** The popover that shows and hides columns. */
function columnsPopover(anchor, st) {
  const d = st.data;
  if (!d) return;
  const save = () => {
    storage.set(STORAGE_PREFIX + ':hidden:' + tableKey(st.table), [...st.hidden]);
    st.onchange && st.onchange();
  };
  const boxes = d.columns.map((c) =>
    check(c.name, !st.hidden.has(c.name), (on) => {
      on ? st.hidden.delete(c.name) : st.hidden.add(c.name);
      save();
    }),
  );
  popover(
    anchor,
    h(
      'div',
      { class: 'stack', style: 'gap:6px' },
      h(
        'div',
        { class: 'row' },
        h('span', { class: 'small muted grow' }, 'Shown columns'),
        h(
          'button',
          {
            type: 'button',
            class: 'btn ghost sm',
            onclick: () => {
              st.hidden.clear();
              save();
              closePopover();
            },
          },
          'Show all',
        ),
      ),
      boxes,
    ),
    { pad: true, width: 260 },
  );
}

/** Deletes the ticked rows, after asking. */
async function deleteSelected(st) {
  const d = st.data;
  const keys = [...st.selected].map((i) => d.keys[i]).filter(Boolean);
  if (!keys.length) return;
  const ok = await confirmBox({
    title: `Delete ${plural(keys.length, 'row')}?`,
    message: `The selected ${keys.length === 1 ? 'row is' : 'rows are'} deleted from ${tableLabel(st.table)} at once. This cannot be undone.`,
    confirm: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    const r = await api('delete', { table: st.table, keys });
    toast(
      r.deleted === r.requested
        ? `${plural(r.deleted, 'row')} deleted`
        : `${r.deleted} of ${r.requested} rows deleted — the others had already changed.`,
      r.deleted === r.requested ? 'ok' : 'err',
    );
    await loadRows(st);
    bumpCount(st.table, -r.deleted);
  } catch (e) {
    fail(e);
  }
}

/** Adjusts the row count shown in the side list after rows were added or deleted. */
export function bumpCount(t, delta) {
  const info = tableInfo(t);
  if (info && info.rows !== null && info.rows >= 0) info.rows = Math.max(0, info.rows + delta);
  redrawSideBar();
}
