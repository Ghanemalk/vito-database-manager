// The SQL console: the editor, running statements, results, and the history.

import { api } from '../api.js';
import { BOOT, STORAGE_PREFIX } from '../boot.js';
import { copyText, download } from '../lib/files.js';
import { debounce, formatMs, formatNumber, plural } from '../lib/format.js';
import { tableSql } from '../lib/sql.js';
import { storage } from '../lib/storage.js';
import { loadOverview, mainEl, redrawSideBar } from '../shell.js';
import { invalidateTables, state } from '../state.js';
import { btn, fill, h, iconBtn, put, select, spinner, toggle } from '../ui/dom.js';
import { highlight } from '../ui/highlight.js';
import { icon } from '../ui/icons.js';
import { closePopover, popover } from '../ui/popovers.js';
import { cellView, setToCsv } from '../values.js';

const SQL_DRAFT_KEY = STORAGE_PREFIX + ':sql';
const SQL_HISTORY_KEY = STORAGE_PREFIX + ':history';
export const saveSqlDraft = (text) => storage.set(SQL_DRAFT_KEY, text);
const SQL_OPTIONS_KEY = STORAGE_PREFIX + ':sqlopts';

/** Draws the SQL console. */
export function renderSql() {
  const opts = Object.assign(
    { readonly: false, tx: false, max_rows: 1000, timeout: 30 },
    storage.get(SQL_OPTIONS_KEY, {}),
  );
  const saveOpts = () => storage.set(SQL_OPTIONS_KEY, opts);
  const ta = h('textarea', {
    spellcheck: false,
    'aria-label': 'SQL',
    autocapitalize: 'off',
    autocomplete: 'off',
    wrap: 'off',
  });
  ta.value = storage.get(
    SQL_DRAFT_KEY,
    `SELECT *\nFROM ${state.overview && state.overview.tables[0] ? tableSql(state.overview.tables[0]) : 'my_table'}\nLIMIT 100;`,
  );
  const pre = h('pre', { 'aria-hidden': 'true' });
  const gutter = h('div', { class: 'gutter', 'aria-hidden': 'true' });
  let errLine = null;
  // the line of the last error, marked behind the text and scrolled into view
  const band = h('div', { class: 'err-band', hidden: true });
  const lineHeight = () => parseFloat(getComputedStyle(ta).lineHeight) || 20;
  const placeBand = () => {
    band.hidden = errLine === null;
    if (errLine === null) return;
    band.style.top = `${12 + (errLine - 1) * lineHeight() - ta.scrollTop}px`;
    band.style.height = `${lineHeight()}px`;
  };
  const paint = () => {
    highlight(ta.value + '\n', pre);
    const lines = ta.value.split('\n').length;
    if (gutter.childElementCount !== lines || errLine !== null) {
      fill(
        gutter,
        ...Array.from({ length: lines }, (_, i) => h('div', { class: i + 1 === errLine ? 'err' : '' }, String(i + 1))),
      );
    }
    placeBand();
  };
  const sync = () => {
    pre.scrollTop = ta.scrollTop;
    pre.scrollLeft = ta.scrollLeft;
    gutter.scrollTop = ta.scrollTop;
    placeBand();
  };
  const revealLine = (n) => {
    const top = 12 + (n - 1) * lineHeight();
    if (top < ta.scrollTop || top + lineHeight() > ta.scrollTop + ta.clientHeight)
      ta.scrollTop = Math.max(0, top - ta.clientHeight / 2);
    sync();
  };
  const persist = debounce(() => saveSqlDraft(ta.value), 400);
  ta.addEventListener('input', () => {
    if (errLine !== null) errLine = null;
    paint();
    sync();
    persist();
  });
  ta.addEventListener('scroll', sync);
  ta.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      e.preventDefault();
      run();
    } else if (e.key === 'Tab' && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();
      const s = ta.selectionStart,
        en = ta.selectionEnd;
      if (!e.shiftKey && s === en) {
        ta.setRangeText('  ', s, en, 'end');
      } else {
        const start = ta.value.lastIndexOf('\n', s - 1) + 1;
        const block = ta.value.slice(start, en);
        const changed = e.shiftKey ? block.replace(/^ {1,2}/gm, '') : block.replace(/^/gm, '  ');
        ta.setRangeText(changed, start, en, 'select');
      }
      ta.dispatchEvent(new Event('input'));
    }
  });
  const runBtn = btn('Run', () => run(), 'primary', 'play', {
    title: 'Run (Ctrl+Enter). With a selection, runs only the selection.',
  });
  const results = h('div', { class: 'results' });
  const status = h('div', { class: 'row small muted', style: 'min-height:20px' });
  const maxSel = select(
    [100, 500, 1000, 5000, 10000].map((n) => [String(n), `${formatNumber(n)} rows`]),
    String(opts.max_rows),
    {
      class: 'select sm',
      style: 'width:auto',
      title: 'Rows shown per result',
      onchange: (e) => {
        opts.max_rows = parseInt(e.target.value, 10);
        saveOpts();
      },
    },
  );
  const toSel = select(
    [5, 10, 30, 50].map((n) => [String(n), `${n} s`]),
    String(opts.timeout),
    {
      class: 'select sm',
      style: 'width:auto',
      title: 'Statement timeout',
      onchange: (e) => {
        opts.timeout = parseInt(e.target.value, 10);
        saveOpts();
      },
    },
  );
  const head = h(
    'div',
    { class: 'row', style: 'flex-wrap:wrap' },
    h('h1', { style: 'font-size:18px;margin:0 8px 0 0;font-weight:600' }, 'SQL console'),
    toggle('Read-only', opts.readonly, (v) => {
      opts.readonly = v;
      saveOpts();
    }),
    toggle('Transaction', opts.tx, (v) => {
      opts.tx = v;
      saveOpts();
    }),
    h('span', { class: 'small muted', title: 'Transaction: everything is rolled back when a statement fails' }, ''),
    maxSel,
    toSel,
    h('span', { class: 'grow' }),
    btn('History', (e) => historyPopover(e.currentTarget, ta), 'sm ghost', 'history'),
    iconBtn(
      'download',
      'Download the script',
      () => download(ta.value, `${BOOT.database.name}.sql`, 'application/sql'),
      'sm ghost',
    ),
    runBtn,
  );
  const editor = h('div', { class: 'editor' }, gutter, h('div', { class: 'area' }, band, pre, ta));
  const page = h('div', { class: 'sql-page' }, head, editor, status, results);
  fill(mainEl, page);
  paint();
  ta.focus();

  async function run() {
    const sel = ta.selectionStart !== ta.selectionEnd ? [ta.selectionStart, ta.selectionEnd] : null;
    const text = sel ? ta.value.slice(sel[0], sel[1]) : ta.value;
    if (!text.trim()) return;
    const lineOffset = sel ? ta.value.slice(0, sel[0]).split('\n').length - 1 : 0;
    errLine = null;
    paint();
    fill(status, spinner(), 'Running…');
    runBtn.disabled = true;
    const t0 = performance.now();
    try {
      const r = await api('sql', {
        sql: text,
        readonly: opts.readonly,
        tx: opts.tx,
        max_rows: opts.max_rows,
        timeout: opts.timeout,
      });
      const took = performance.now() - t0;
      pushHistory(text, !r.error);
      showResults(r, lineOffset, took);
      if (
        !opts.readonly &&
        r.results.some(
          (x) =>
            !['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'WITH', 'VALUES', 'TABLE'].includes(
              String(x.kind).toUpperCase(),
            ),
        )
      ) {
        invalidateTables();
        loadOverview().then(redrawSideBar);
      }
    } catch (e) {
      fill(status);
      if (e.info && e.info.line) {
        errLine = e.info.line + lineOffset;
        paint();
        revealLine(errLine);
      }
      fill(results, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
    } finally {
      runBtn.disabled = false;
    }
  }

  function showResults(r, lineOffset, took) {
    fill(results);
    const total = r.results.reduce((s, x) => s + (x.ms || 0), 0);
    fill(
      status,
      r.error ? icon('alert') : icon('check'),
      `${r.ran} of ${plural(r.statements, 'statement')} ran · ${formatMs(total)} in the database · ${formatMs(took)} in all`,
      r.summary ? h('span', { class: 'badge' }, 'summary only') : null,
      r.rolled_back ? h('span', { class: 'badge warn' }, 'rolled back') : null,
    );
    if (r.error) {
      const e = r.error;
      if (e.line) {
        const line = e.line + lineOffset;
        errLine = line;
        paint();
        // after the results are in place, so the editor has its final height
        requestAnimationFrame(() => revealLine(line));
      }
      const lines = [e.message];
      if (e.detail) lines.push('Detail: ' + e.detail);
      if (e.hint) lines.push('Hint: ' + e.hint);
      put(
        results,
        h(
          'div',
          { class: 'alert err' },
          icon('alert'),
          h(
            'div',
            { class: 'a-body' },
            h(
              'b',
              null,
              `Statement ${e.statement + 1}${e.line ? `, line ${e.line + lineOffset}` : ''}${e.kind === 'timeout' ? ' timed out' : ' failed'}`,
            ),
            e.sqlstate || e.code
              ? h('span', { class: 'mono small muted' }, `  ${[e.code, e.sqlstate].filter(Boolean).join(' · ')}`)
              : null,
            '\n' + lines.join('\n'),
            r.rolled_back
              ? '\nThe transaction was rolled back: no statement of this run changed anything.'
              : r.ran && !opts.readonly
                ? r.ran === 1
                  ? '\nThe statement before it ran, and its changes stay applied.'
                  : `\nThe ${r.ran} statements before it ran, and their changes stay applied.`
                : '',
          ),
        ),
      );
    }
    const shown = r.results.length > 60 ? r.results.filter((x) => x.sets.length).slice(-30) : r.results;
    if (shown.length < r.results.length)
      put(
        results,
        h(
          'div',
          { class: 'alert info' },
          icon('info'),
          h('div', { class: 'a-body' }, `${r.results.length} statements ran; showing the last result sets only.`),
        ),
      );
    for (const x of shown) {
      const meta = [];
      if (x.affected !== null && x.affected !== undefined && x.affected >= 0 && !x.sets.length)
        meta.push(`${plural(x.affected, 'row')} affected`);
      if (x.insert_id && x.insert_id !== '0' && x.insert_id !== 0) meta.push(`id ${x.insert_id}`);
      const card = h(
        'div',
        { class: 'result' },
        h(
          'div',
          { class: 'result-head' },
          h('span', { class: 'badge' }, `#${x.i + 1}`),
          h('code', { title: x.sql }, x.sql),
          h('span', null, meta.join(' · ')),
          h('span', { class: 'nowrap' }, formatMs(x.ms)),
        ),
      );
      for (const set of x.sets) put(card, resultSet(set, opts.max_rows));
      if (!x.sets.length && !meta.length) put(card, h('div', { class: 'result-msg muted' }, 'Done.'));
      if (x.warnings && x.warnings.length) {
        put(
          card,
          h(
            'div',
            { class: 'result-msg' },
            x.warnings.map((w) =>
              h(
                'div',
                { class: 'small', style: 'color:oklch(0.65 0.15 70)' },
                `${w.level || 'Note'}${w.code ? ' ' + w.code : ''}: ${w.message}`,
              ),
            ),
          ),
        );
      }
      put(results, card);
    }
  }
}

/** A result set as a grid, with copy and download. */
export function resultSet(set, limit) {
  const box = h('div');
  const tools = h(
    'div',
    { class: 'result-head', style: 'border-top:1px solid var(--border)' },
    h(
      'span',
      null,
      set.more
        ? `First ${formatNumber(set.count)} rows — there are more${limit ? ' (raise the row limit or add LIMIT)' : ''}`
        : plural(set.count, 'row'),
    ),
    h('span', { class: 'grow' }),
    iconBtn('copy', 'Copy as tab-separated text', () => copyText(setToCsv(set.columns, set.rows, '\t')), 'ghost sm'),
    iconBtn(
      'download',
      'Download as CSV',
      () => download('﻿' + setToCsv(set.columns, set.rows), 'result.csv', 'text/csv;charset=utf-8'),
      'ghost sm',
    ),
  );
  if (!set.columns.length) return box;
  const wrap = h('div', { class: 'grid-wrap' });
  const tbody = h('tbody');
  const table = h(
    'table',
    { class: 'grid' },
    h(
      'thead',
      null,
      h(
        'tr',
        null,
        set.columns.map((c) =>
          h('th', null, h('div', { class: 'th', style: 'cursor:default' }, h('span', { class: 't' }, c))),
        ),
      ),
    ),
    tbody,
  );
  let drawn = 0;
  const more = () => {
    const end = Math.min(set.rows.length, drawn + 300);
    for (; drawn < end; drawn++) {
      put(
        tbody,
        h(
          'tr',
          null,
          set.rows[drawn].map((v) => {
            const td = h('td', { class: typeof v === 'string' && /^-?\d+(\.\d+)?$/.test(v) ? 'num' : '' }, cellView(v));
            if (typeof v === 'string' && v.length > 40) td.title = v.length > 1000 ? v.slice(0, 1000) + '…' : v;
            return td;
          }),
        ),
      );
    }
  };
  more();
  wrap.addEventListener('scroll', () => {
    if (drawn < set.rows.length && wrap.scrollTop + wrap.clientHeight > wrap.scrollHeight - 200) more();
  });
  put(wrap, table);
  put(box, wrap, tools);
  return box;
}

/** Remembers a script that ran, most recent first, without repeats. */
function pushHistory(sql, ok) {
  const list = storage.get(SQL_HISTORY_KEY, []).filter((x) => x.sql !== sql);
  list.unshift({ sql: sql.length > 20000 ? sql.slice(0, 20000) : sql, at: Date.now(), ok });
  storage.set(SQL_HISTORY_KEY, list.slice(0, 50));
}

/** The History list; choosing an entry puts it in the editor (ta). */
function historyPopover(anchor, ta) {
  const list = storage.get(SQL_HISTORY_KEY, []);
  popover(
    anchor,
    list.length
      ? [
          h(
            'div',
            { class: 'row', style: 'padding:4px 8px' },
            h('span', { class: 'small muted grow' }, 'Recent queries (kept in this browser)'),
            h(
              'button',
              {
                type: 'button',
                class: 'btn ghost sm',
                onclick: () => {
                  storage.set(SQL_HISTORY_KEY, []);
                  closePopover();
                },
              },
              'Clear',
            ),
          ),
          ...list.map((x) =>
            h(
              'button',
              {
                type: 'button',
                class: 'history-item',
                title: x.sql.slice(0, 2000),
                onclick: () => {
                  ta.value = x.sql;
                  ta.dispatchEvent(new Event('input'));
                  closePopover();
                  ta.focus();
                },
              },
              h('code', null, x.sql.replace(/\s+/g, ' ').slice(0, 200)),
              h('span', { class: 'small muted' }, `${new Date(x.at).toLocaleString()}${x.ok ? '' : ' · failed'}`),
            ),
          ),
        ]
      : h('div', { class: 'small muted', style: 'padding:12px' }, 'Queries you run appear here.'),
    { align: 'end', width: 460 },
  );
}
