// The Indexes tab and the index form.

import { api } from '../api.js';
import { PG } from '../boot.js';
import { formatBytes, formatNumber, plural } from '../lib/format.js';
import { tableLabel } from '../lib/sql.js';
import { btn, busy, field, fill, h, iconBtn, input, put, select, toggle } from '../ui/dom.js';
import { codeBlock } from '../ui/highlight.js';
import { icon } from '../ui/icons.js';
import { modal } from '../ui/modals.js';
import { toast } from '../ui/toasts.js';
import { afterDdl, runDdl } from './common.js';

/** The Indexes tab. */
export function indexesTab(st, s, body) {
  const editable = ['table', 'partitioned', 'matview'].includes(s.table.type);
  put(
    body,
    h(
      'div',
      { class: 'card' },
      h(
        'div',
        { class: 'card-head' },
        h('h3', null, plural(s.indexes.length, 'index', 'indexes')),
        editable ? btn('Add index', () => indexDialog(st, s), 'primary sm', 'plus') : null,
      ),
      s.indexes.length
        ? h(
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
                  h('th', null, 'Kind'),
                  h('th', null, 'Columns'),
                  PG ? h('th', { class: 'num' }, 'Size') : h('th', { class: 'num' }, 'Cardinality'),
                  h('th'),
                ),
              ),
              h(
                'tbody',
                null,
                s.indexes.map((ix) =>
                  h(
                    'tr',
                    null,
                    h(
                      'td',
                      { class: 'mono' },
                      ix.name,
                      ix.visible === false
                        ? h('span', { class: 'badge warn', style: 'margin-left:6px' }, PG ? 'invalid' : 'invisible')
                        : null,
                    ),
                    h(
                      'td',
                      null,
                      h(
                        'span',
                        { class: 'badge' + (ix.primary ? ' pk' : ix.unique ? ' uni' : '') },
                        ix.primary ? 'PRIMARY' : ix.unique ? 'UNIQUE' : String(ix.type || 'INDEX').toUpperCase(),
                      ),
                      PG && ix.type && (ix.primary || ix.unique)
                        ? h('span', { class: 'small muted', style: 'margin-left:6px' }, ix.type)
                        : null,
                    ),
                    h(
                      'td',
                      { class: 'mono small' },
                      ix.columns
                        .map(
                          (x) =>
                            (x.expr ? `(${x.expr})` : x.name) +
                            (x.length ? `(${x.length})` : '') +
                            (!PG && x.order === 'desc' ? ' DESC' : ''),
                        )
                        .join(', '),
                      ix.predicate ? h('div', { class: 'muted' }, 'WHERE ' + ix.predicate) : null,
                    ),
                    h(
                      'td',
                      { class: 'num muted' },
                      PG ? formatBytes(ix.size) : formatNumber(ix.columns[ix.columns.length - 1]?.cardinality),
                    ),
                    h(
                      'td',
                      { class: 'actions' },
                      editable
                        ? iconBtn(
                            'trash',
                            'Drop index',
                            () =>
                              runDdl(
                                { action: 'drop_index', table: st.table, name: ix.name },
                                {
                                  title: `Drop the index ${ix.name}?`,
                                  message: ix.primary
                                    ? 'The table loses its primary key; rows can then only be matched by content.'
                                    : 'Queries that use it may become slower.',
                                  confirm: 'Drop index',
                                  danger: true,
                                },
                              ),
                            'ghost sm',
                          )
                        : null,
                    ),
                  ),
                ),
              ),
            ),
          )
        : h('div', { class: 'empty' }, 'No indexes.'),
    ),
  );
}

/** The form for a new index. */
function indexDialog(st, s) {
  const kinds = PG
    ? [
        ['index', 'Index'],
        ['unique', 'Unique'],
        ['primary', 'Primary key'],
      ]
    : [
        ['index', 'Index'],
        ['unique', 'Unique'],
        ['fulltext', 'Full text'],
        ['spatial', 'Spatial'],
        ['primary', 'Primary key'],
      ];
  const a = {
    action: 'add_index',
    table: st.table,
    kind: 'index',
    name: '',
    columns: [{ name: s.columns[0]?.name || '', order: 'asc', length: '' }],
    method: 'btree',
    where: '',
    concurrently: false,
  };
  const form = h('div', { class: 'stack' });
  const preview = h('div');
  const saveBtn = btn('Add index', () => save(), 'primary');
  const m = modal({
    title: `Add an index to ${tableLabel(st.table)}`,
    body: [form, preview],
    foot: [
      btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'),
      h('span', { class: 'spacer' }),
      btn('Cancel', () => m.close()),
      saveBtn,
    ],
    size: 'mid',
    sticky: true,
  });
  const payload = () => {
    const p = {
      action: 'add_index',
      table: st.table,
      kind: a.kind,
      columns: a.columns
        .filter((x) => x.name)
        .map((x) => ({ name: x.name, order: x.order, ...(x.length ? { length: parseInt(x.length, 10) } : {}) })),
    };
    if (a.name.trim() && a.kind !== 'primary') p.name = a.name.trim();
    if (PG && a.kind !== 'primary')
      Object.assign(p, { method: a.method, where: a.where.trim(), concurrently: a.concurrently });
    return p;
  };
  async function showSql() {
    try {
      fill(
        preview,
        h('div', { class: 'card' }, codeBlock((await api('ddl', { ...payload(), dry: true })).sql.join(';\n') + ';')),
      );
    } catch (e) {
      fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
    }
  }
  async function save() {
    await busy(saveBtn, async () => {
      try {
        await api('ddl', payload());
        toast('Index added');
        m.sticky = false;
        m.close();
        afterDdl({ table: st.table });
      } catch (e) {
        fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
      }
    });
  }
  function draw() {
    const colRows = a.columns.map((x, k) =>
      h(
        'div',
        { class: 'row' },
        select(
          s.columns.map((c) => c.name),
          x.name,
          { onchange: (e) => (x.name = e.target.value) },
        ),
        select(
          [
            ['asc', 'ASC'],
            ['desc', 'DESC'],
          ],
          x.order,
          { style: 'width:110px', onchange: (e) => (x.order = e.target.value) },
        ),
        PG
          ? null
          : input({
              value: x.length,
              placeholder: 'Prefix',
              style: 'width:90px',
              inputmode: 'numeric',
              title: 'Index only the first N characters',
              oninput: (e) => (x.length = e.target.value),
            }),
        iconBtn(
          'x',
          'Remove',
          () => {
            a.columns.splice(k, 1);
            if (!a.columns.length) a.columns.push({ name: s.columns[0]?.name || '', order: 'asc', length: '' });
            draw();
          },
          'ghost sm',
        ),
      ),
    );
    fill(
      form,
      h(
        'div',
        { class: 'grid2' },
        field(
          'Kind',
          select(kinds, a.kind, {
            onchange: (e) => {
              a.kind = e.target.value;
              draw();
            },
          }),
        ),
        a.kind === 'primary'
          ? h('div')
          : field(
              'Name',
              input({ value: a.name, placeholder: 'Generated when empty', oninput: (e) => (a.name = e.target.value) }),
            ),
      ),
      field(
        'Columns',
        h(
          'div',
          { class: 'stack', style: 'gap:8px' },
          colRows,
          h(
            'div',
            null,
            btn(
              'Add a column',
              () => {
                a.columns.push({
                  name: s.columns.find((c) => !a.columns.some((x) => x.name === c.name))?.name || s.columns[0].name,
                  order: 'asc',
                  length: '',
                });
                draw();
              },
              'ghost sm',
              'plus',
            ),
          ),
        ),
      ),
      PG && a.kind !== 'primary'
        ? h(
            'div',
            { class: 'grid2' },
            field(
              'Method',
              select(['btree', 'hash', 'gin', 'gist', 'spgist', 'brin'], a.method, {
                onchange: (e) => (a.method = e.target.value),
              }),
              'gin for jsonb, arrays and full-text search.',
            ),
            field(
              'Only rows where (partial index)',
              input({
                class: 'input mono',
                value: a.where,
                placeholder: 'deleted_at IS NULL',
                oninput: (e) => (a.where = e.target.value),
              }),
            ),
          )
        : null,
      PG && a.kind !== 'primary'
        ? toggle('Build concurrently (no write lock; slower)', a.concurrently, (v) => (a.concurrently = v))
        : null,
    );
  }
  draw();
}
