// Importing rows from a CSV file (comma, semicolon, tab or pipe separated), with the file's columns mapped to the
// table's.

import { api } from '../api.js';
import { formatBytes, formatNumber, plural } from '../lib/format.js';
import { tableLabel } from '../lib/sql.js';
import { refreshOverview } from '../shell.js';
import { shortType } from '../structure/types.js';
import { btn, check, field, fill, h, put, select } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { modal } from '../ui/modals.js';
import { toast } from '../ui/toasts.js';
import { loadRows } from './grid.js';

/** RFC 4180 CSV. An unquoted empty field is null, a quoted one ''. */
function parseCsv(text, sep) {
  const rows = [];
  let row = [];
  let i = 0;
  const n = text.length;
  let cell = '';
  let quoted = false;
  let any = false;
  const end = () => {
    row.push(quoted || cell !== '' ? cell : null);
    cell = '';
    quoted = false;
  };
  while (i < n) {
    const ch = text[i];
    if (ch === '"' && cell === '' && !quoted) {
      quoted = true;
      any = true;
      i++;
      while (i < n) {
        if (text[i] === '"') {
          if (text[i + 1] === '"') {
            cell += '"';
            i += 2;
            continue;
          }
          i++;
          break;
        }
        cell += text[i++];
      }
      continue;
    }
    if (ch === sep) {
      end();
      any = true;
      i++;
      continue;
    }
    if (ch === '\n' || ch === '\r') {
      end();
      rows.push(row);
      row = [];
      any = false;
      i += ch === '\r' && text[i + 1] === '\n' ? 2 : 1;
      continue;
    }
    cell += ch;
    any = true;
    i++;
  }
  if (any || cell !== '' || row.length) {
    end();
    rows.push(row);
  }
  return rows.filter((r) => !(r.length === 1 && r[0] === null));
}

/** The separator the first line uses most: comma, semicolon, tab or pipe. */
function guessSep(text) {
  const line = text.slice(0, text.indexOf('\n') > 0 ? text.indexOf('\n') : 2000);
  let best = ',',
    count = -1;
  for (const s of [',', ';', '\t', '|']) {
    const k = line.split(s).length - 1;
    if (k > count) {
      best = s;
      count = k;
    }
  }
  return best;
}

/** The Import dialog: reads the file, maps its columns to the table's, and inserts the rows in batches. */
export async function importDialog(st) {
  if (!st.data) await loadRows(st);
  const d = st.data;
  if (!d) return;
  const cols = d.columns.filter((c) => !c.generated);
  let text = null,
    rows = [],
    header = true,
    sep = ',',
    nullEmpty = true,
    mode = 'insert',
    mapping = [];
  const body = h('div', { class: 'stack' });
  const runBtn = btn('Import', () => run(), 'primary', 'upload', { disabled: true });
  const m = modal({
    title: `Import into ${tableLabel(st.table)}`,
    body,
    foot: [h('span', { class: 'spacer' }), btn('Close', () => m.close()), runBtn],
    size: 'wide',
    sticky: true,
  });
  const file = h('input', {
    type: 'file',
    accept: '.csv,.tsv,.txt,text/csv,text/plain',
    class: 'hidden',
    onchange: () => file.files[0] && readFile(file.files[0]),
  });
  const drop = h(
    'div',
    { class: 'card', style: 'border-style:dashed;cursor:pointer', onclick: () => file.click() },
    h(
      'div',
      { class: 'empty' },
      icon('upload'),
      h('div', null, 'Drop a CSV file here, or click to choose one'),
      h(
        'div',
        { class: 'small' },
        'The first row can hold the column names. Exports from this manager import as they are.',
      ),
    ),
  );
  drop.addEventListener('dragover', (e) => {
    e.preventDefault();
    drop.style.borderColor = 'var(--primary)';
  });
  drop.addEventListener('dragleave', () => (drop.style.borderColor = ''));
  drop.addEventListener('drop', (e) => {
    e.preventDefault();
    drop.style.borderColor = '';
    e.dataTransfer.files[0] && readFile(e.dataTransfer.files[0]);
  });
  const setup = h('div', { class: 'stack' });
  const progress = h('div');
  put(body, drop, file, setup, progress);

  async function readFile(f) {
    if (f.size > 200 * 1024 * 1024) {
      toast('Choose a file of at most 200 MB.', 'err');
      return;
    }
    text = (await f.text()).replace(/^﻿/, '');
    sep = guessSep(text);
    drop.querySelector('.empty > div').textContent = `${f.name} · ${formatBytes(f.size)}`;
    parse();
  }
  function parse() {
    rows = parseCsv(text, sep);
    const width = Math.max(0, ...rows.slice(0, 50).map((r) => r.length));
    const names = header && rows.length ? rows[0] : [];
    mapping = Array.from({ length: width }, (_, k) => {
      const n = String(names[k] ?? '')
        .trim()
        .toLowerCase();
      const hit = cols.find((c) => c.name.toLowerCase() === n) || (!header ? cols[k] : null);
      return hit ? hit.name : '';
    });
    drawSetup();
  }
  function drawSetup() {
    const data = header ? rows.slice(1) : rows;
    const sample = data.slice(0, 3);
    const names = header && rows.length ? rows[0] : [];
    const keyOk = ['pk', 'unique'].includes(d.key.mode);
    fill(
      setup,
      h(
        'div',
        { class: 'grid3' },
        field(
          'Separator',
          select(
            [
              [',', 'Comma ,'],
              [';', 'Semicolon ;'],
              ['\t', 'Tab'],
              ['|', 'Pipe |'],
            ],
            sep,
            {
              onchange: (e) => {
                sep = e.target.value;
                parse();
              },
            },
          ),
        ),
        field(
          'Rows that already exist',
          select(
            [['insert', 'Stop with an error'], ['ignore', 'Skip them'], ...(keyOk ? [['upsert', 'Update them']] : [])],
            mode,
            { onchange: (e) => (mode = e.target.value) },
          ),
          keyOk ? 'Matched by the primary or unique key.' : 'Updating needs a primary or unique key.',
        ),
        h(
          'div',
          { class: 'stack', style: 'gap:8px;padding-top:24px' },
          check('First row holds column names', header, (v) => {
            header = v;
            parse();
          }),
          check('Empty unquoted cells are NULL', nullEmpty, (v) => (nullEmpty = v)),
        ),
      ),
      h(
        'div',
        { class: 'small muted' },
        `${plural(data.length, 'row')} to import. Choose the table column for each file column.`,
      ),
      h(
        'div',
        { class: 'grid-wrap', style: 'max-height:340px' },
        h(
          'table',
          { class: 'list' },
          h(
            'thead',
            null,
            h('tr', null, h('th', null, 'File column'), h('th', null, 'Imports into'), h('th', null, 'Sample values')),
          ),
          h(
            'tbody',
            null,
            mapping.map((target, k) =>
              h(
                'tr',
                null,
                h('td', { class: 'mono' }, header ? String(names[k] ?? `#${k + 1}`) : `#${k + 1}`),
                h(
                  'td',
                  { style: 'width:240px' },
                  select([['', '— skip —'], ...cols.map((c) => [c.name, `${c.name} · ${shortType(c)}`])], target, {
                    class: 'select sm',
                    onchange: (e) => {
                      mapping[k] = e.target.value;
                      validate();
                    },
                  }),
                ),
                h(
                  'td',
                  {
                    class: 'muted mono',
                    style: 'max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap',
                  },
                  sample
                    .map((r) => (r[k] === null || r[k] === undefined ? 'NULL' : JSON.stringify(r[k])))
                    .join('  ·  '),
                ),
              ),
            ),
          ),
        ),
      ),
    );
    validate();
  }
  function validate() {
    const used = mapping.filter(Boolean);
    const dup = used.find((n, i) => used.indexOf(n) !== i);
    runBtn.disabled = !used.length || !!dup || !(header ? rows.length - 1 : rows.length);
    fill(
      progress,
      dup
        ? h(
            'div',
            { class: 'alert err' },
            icon('alert'),
            h('div', { class: 'a-body' }, `${dup} is chosen for more than one file column.`),
          )
        : '',
    );
  }
  async function run() {
    const data = header ? rows.slice(1) : rows;
    const targets = mapping.map((name, k) => [name, k]).filter(([name]) => name);
    const columns = targets.map(([name]) => name);
    const total = data.length;
    let done = 0,
      affected = 0,
      warnings = 0;
    const bar = h('i', { style: 'width:0%' });
    const label = h('span', { class: 'small muted' });
    fill(
      progress,
      h('div', { class: 'stack', style: 'gap:6px' }, h('div', { class: 'bar', style: 'height:8px' }, bar), label),
    );
    runBtn.disabled = true;
    m.sticky = true;
    try {
      let at = 0;
      while (at < total) {
        const batch = [];
        let size = 0;
        while (at < total && batch.length < 2000 && size < 2500000) {
          const r = data[at++];
          const vals = targets.map(([, k]) => {
            const v = r[k] === undefined ? null : r[k];
            return v === null ? (nullEmpty ? null : '') : v;
          });
          size += JSON.stringify(vals).length;
          batch.push(vals);
        }
        const res = await api('insert_many', { table: st.table, columns, rows: batch, mode });
        affected += res.affected || 0;
        warnings += (res.warnings || []).length;
        done += batch.length;
        bar.style.width = `${Math.round((done / total) * 100)}%`;
        label.textContent = `${formatNumber(done)} of ${formatNumber(total)} rows sent · ${formatNumber(affected)} affected`;
      }
      label.textContent = `Done: ${formatNumber(total)} rows sent, ${formatNumber(affected)} affected${warnings ? `, ${warnings} warnings` : ''}.`;
      toast(`Import finished: ${formatNumber(affected)} rows affected`);
      loadRows(st);
      refreshOverview();
    } catch (e) {
      put(
        progress,
        h(
          'div',
          { class: 'alert err' },
          icon('alert'),
          h(
            'div',
            { class: 'a-body' },
            `Stopped after ${formatNumber(done)} rows (each batch of up to 2000 rows is all or nothing). ${e.message}`,
          ),
        ),
      );
      loadRows(st);
    } finally {
      m.sticky = false;
      runBtn.disabled = false;
    }
  }
}
