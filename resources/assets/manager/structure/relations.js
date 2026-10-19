// The Relations tab: foreign keys (both directions) and check constraints.

import { api } from '../api.js';
import { PG } from '../boot.js';
import { sameTable, tableKey, tableLabel } from '../lib/sql.js';
import { hashOf, openTable } from '../router.js';
import { state } from '../state.js';
import { btn, busy, field, fill, h, iconBtn, input, put, select, spinner } from '../ui/dom.js';
import { codeBlock } from '../ui/highlight.js';
import { icon } from '../ui/icons.js';
import { modal } from '../ui/modals.js';
import { fail, toast } from '../ui/toasts.js';
import { afterDdl, runDdl } from './common.js';
import { typeName } from './types.js';

/** The Relations tab: the table's foreign keys, the ones that point at it, and its checks. */
export function relationsTab(st, s, body) {
  const editable = ['table', 'partitioned'].includes(s.table.type);
  const ruleBadge = (r) =>
    r && r !== 'NO ACTION' && r !== 'RESTRICT'
      ? h('span', { class: 'badge' }, r)
      : h('span', { class: 'muted small' }, r || '');
  put(
    body,
    h(
      'div',
      { class: 'card' },
      h(
        'div',
        { class: 'card-head' },
        h('h3', null, 'Foreign keys'),
        editable ? btn('Add foreign key', () => fkDialog(st, s), 'primary sm', 'plus') : null,
      ),
      s.foreign_keys.length
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
                  h('th', null, 'Columns'),
                  h('th', null, 'References'),
                  h('th', null, 'On delete'),
                  h('th', null, 'On update'),
                  h('th'),
                ),
              ),
              h(
                'tbody',
                null,
                s.foreign_keys.map((f) =>
                  h(
                    'tr',
                    null,
                    h(
                      'td',
                      { class: 'mono' },
                      f.name,
                      f.valid === false
                        ? h('span', { class: 'badge warn', style: 'margin-left:6px' }, 'not validated')
                        : null,
                    ),
                    h('td', { class: 'mono' }, f.columns.join(', ')),
                    h(
                      'td',
                      null,
                      h(
                        'a',
                        {
                          href: hashOf({
                            view: 'table',
                            table: { schema: PG ? f.ref_schema : null, name: f.ref_table },
                          }),
                          class: 'mono',
                        },
                        (PG && f.ref_schema && f.ref_schema !== 'public' ? f.ref_schema + '.' : '') + f.ref_table,
                      ),
                      h('span', { class: 'mono muted' }, ` (${f.ref_columns.join(', ')})`),
                    ),
                    h('td', null, ruleBadge(f.on_delete)),
                    h('td', null, ruleBadge(f.on_update)),
                    h(
                      'td',
                      { class: 'actions' },
                      editable
                        ? iconBtn(
                            'trash',
                            'Drop foreign key',
                            () =>
                              runDdl(
                                { action: 'drop_fk', table: st.table, name: f.name },
                                {
                                  title: `Drop the foreign key ${f.name}?`,
                                  confirm: 'Drop',
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
        : h('div', { class: 'empty small' }, 'This table references no other table.'),
    ),
    h(
      'div',
      { class: 'card' },
      h('div', { class: 'card-head' }, h('h3', null, 'Referenced by')),
      s.references.length
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
                  h('th', null, 'Table'),
                  h('th', null, 'Columns'),
                  h('th', null, 'Constraint'),
                  h('th', null, 'On delete'),
                ),
              ),
              h(
                'tbody',
                null,
                s.references.map((f) =>
                  h(
                    'tr',
                    {
                      class: 'click',
                      onclick: () => openTable({ schema: PG ? f.schema : null, name: f.table }, 'relations'),
                    },
                    h(
                      'td',
                      { class: 'mono' },
                      (PG && f.schema && f.schema !== 'public' ? f.schema + '.' : '') + f.table,
                    ),
                    h('td', { class: 'mono' }, `${f.columns.join(', ')} → ${f.ref_columns.join(', ')}`),
                    h('td', { class: 'mono muted' }, f.name),
                    h('td', null, ruleBadge(f.on_delete)),
                  ),
                ),
              ),
            ),
          )
        : h('div', { class: 'empty small' }, 'No other table references this one.'),
    ),
    h(
      'div',
      { class: 'card' },
      h(
        'div',
        { class: 'card-head' },
        h('h3', null, 'Check constraints'),
        editable ? btn('Add check', () => checkDialog(st), 'sm', 'plus') : null,
      ),
      s.checks.length
        ? h(
            'table',
            { class: 'list' },
            h(
              'tbody',
              null,
              s.checks.map((c) => {
                // MariaDB stores a JSON column as LONGTEXT plus a json_valid() check named after the column
                const jsonGuard =
                  !PG &&
                  s.columns.some((col) => col.name === c.name && typeName(col) === 'json') &&
                  c.clause.replace(/\s+/g, '').toLowerCase() === `json_valid(\`${c.name}\`)`.toLowerCase();
                return h(
                  'tr',
                  null,
                  h(
                    'td',
                    { class: 'mono', style: 'width:30%' },
                    c.name,
                    jsonGuard
                      ? h(
                          'span',
                          {
                            class: 'badge',
                            style: 'margin-left:8px',
                            title: 'MariaDB adds this check to every JSON column',
                          },
                          'json column',
                        )
                      : null,
                  ),
                  h('td', { class: 'mono' }, c.clause),
                  h(
                    'td',
                    { class: 'actions' },
                    editable && !jsonGuard
                      ? iconBtn(
                          'trash',
                          'Drop check',
                          () =>
                            runDdl(
                              { action: 'drop_check', table: st.table, name: c.name },
                              { title: `Drop the check ${c.name}?`, confirm: 'Drop', danger: true },
                            ),
                          'ghost sm',
                        )
                      : null,
                  ),
                );
              }),
            ),
          )
        : h('div', { class: 'empty small' }, 'No check constraints.'),
    ),
    s.triggers.length
      ? h(
          'div',
          { class: 'card' },
          h('div', { class: 'card-head' }, h('h3', null, 'Triggers')),
          h(
            'div',
            { class: 'stack', style: 'padding:12px;gap:10px' },
            s.triggers.map((x) =>
              h(
                'details',
                null,
                h(
                  'summary',
                  { style: 'cursor:pointer' },
                  h('span', { class: 'mono' }, x.name),
                  ' ',
                  h('span', { class: 'badge' }, [x.timing, x.event].filter(Boolean).join(' ')),
                  x.enabled === false ? h('span', { class: 'badge warn', style: 'margin-left:6px' }, 'disabled') : null,
                ),
                h('div', { class: 'card', style: 'margin-top:8px' }, codeBlock(x.definition || '')),
              ),
            ),
          ),
        )
      : null,
    s.partitions && s.partitions.length
      ? h(
          'div',
          { class: 'card' },
          h(
            'div',
            { class: 'card-head' },
            h('h3', null, 'Partitions'),
            h('span', { class: 'badge' }, String(s.partitions.length)),
          ),
          h(
            'table',
            { class: 'list' },
            h(
              'tbody',
              null,
              s.partitions.map((p) =>
                h(
                  'tr',
                  { class: 'click', onclick: () => openTable({ schema: st.table.schema, name: p.name }) },
                  h('td', { class: 'mono' }, p.name),
                  h('td', { class: 'mono muted' }, p.bound || ''),
                ),
              ),
            ),
          ),
        )
      : null,
  );
}

/** The form for a new foreign key. */
async function fkDialog(st, s) {
  const tables = (state.overview?.tables || []).filter((t) => ['table', 'partitioned'].includes(t.type));
  const a = {
    name: '',
    columns: [s.columns[0]?.name],
    ref: tables.find((t) => sameTable(t, st.table)) || tables[0],
    refCols: [],
    on_delete: '',
    on_update: '',
  };
  let refColumns = [];
  const form = h('div', { class: 'stack' });
  const preview = h('div');
  const saveBtn = btn('Add foreign key', () => save(), 'primary');
  const m = modal({
    title: `Add a foreign key to ${tableLabel(st.table)}`,
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
  const payload = () => ({
    action: 'add_fk',
    table: st.table,
    name: a.name.trim(),
    columns: a.columns.filter(Boolean),
    ref_table: a.ref ? { schema: a.ref.schema ?? null, name: a.ref.name } : null,
    ref_columns: a.refCols.filter(Boolean),
    on_delete: a.on_delete,
    on_update: a.on_update,
  });
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
        toast('Foreign key added');
        m.sticky = false;
        m.close();
        afterDdl({ table: st.table });
      } catch (e) {
        fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
      }
    });
  }
  async function loadRef() {
    refColumns = [];
    if (!a.ref) return;
    try {
      const rs = sameTable(a.ref, st.table)
        ? s
        : await api('structure', { table: { schema: a.ref.schema ?? null, name: a.ref.name } });
      refColumns = rs.columns.map((c) => c.name);
      const pk = rs.key.mode === 'pk' ? rs.key.cols : [];
      a.refCols = a.columns.map((_, k) => pk[k] || refColumns[k] || refColumns[0]);
    } catch (e) {
      fail(e);
    }
  }
  const rules = [
    ['', 'Default (no action)'],
    ['cascade', 'Cascade'],
    ['set null', 'Set NULL'],
    ['restrict', 'Restrict'],
    ['set default', 'Set default'],
  ];
  function draw() {
    fill(
      form,
      field(
        'Name',
        input({ value: a.name, placeholder: 'Generated when empty', oninput: (e) => (a.name = e.target.value) }),
      ),
      field(
        'References table',
        select(
          tables.map((t) => [tableKey(t), tableLabel(t)]),
          a.ref ? tableKey(a.ref) : '',
          {
            onchange: async (e) => {
              a.ref = tables.find((t) => tableKey(t) === e.target.value);
              await loadRef();
              draw();
            },
          },
        ),
      ),
      field(
        'Columns',
        h(
          'div',
          { class: 'stack', style: 'gap:8px' },
          a.columns.map((col, k) =>
            h(
              'div',
              { class: 'row' },
              select(
                s.columns.map((c) => c.name),
                col,
                { onchange: (e) => (a.columns[k] = e.target.value) },
              ),
              h('span', { class: 'muted' }, '→'),
              select(refColumns, a.refCols[k], { onchange: (e) => (a.refCols[k] = e.target.value) }),
              a.columns.length > 1
                ? iconBtn(
                    'x',
                    'Remove',
                    () => {
                      a.columns.splice(k, 1);
                      a.refCols.splice(k, 1);
                      draw();
                    },
                    'ghost sm',
                  )
                : null,
            ),
          ),
          h(
            'div',
            null,
            btn(
              'Add a column pair',
              () => {
                a.columns.push(s.columns[0].name);
                a.refCols.push(refColumns[0]);
                draw();
              },
              'ghost sm',
              'plus',
            ),
          ),
        ),
      ),
      h(
        'div',
        { class: 'grid2' },
        field(
          'When the referenced row is deleted',
          select(rules, a.on_delete, { onchange: (e) => (a.on_delete = e.target.value) }),
        ),
        field(
          'When the referenced key changes',
          select(rules, a.on_update, { onchange: (e) => (a.on_update = e.target.value) }),
        ),
      ),
    );
  }
  put(form, h('div', { class: 'empty' }, spinner()));
  await loadRef();
  draw();
}

/** The form for a new check constraint. */
function checkDialog(st) {
  const name = input({ placeholder: 'Generated when empty' });
  const clause = input({ class: 'input mono', placeholder: 'price >= 0' });
  const preview = h('div');
  const payload = () => ({ action: 'add_check', table: st.table, name: name.value.trim(), clause: clause.value });
  const showError = (e) =>
    fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
  async function showSql() {
    try {
      fill(
        preview,
        h('div', { class: 'card' }, codeBlock((await api('ddl', { ...payload(), dry: true })).sql.join(';\n') + ';')),
      );
    } catch (e) {
      showError(e);
    }
  }
  const save = btn(
    'Add check',
    () =>
      busy(save, async () => {
        try {
          await api('ddl', payload());
          toast('Check added');
          m.close();
          afterDdl({ table: st.table });
        } catch (e) {
          showError(e);
        }
      }),
    'primary',
  );
  const m = modal({
    title: `Add a check constraint to ${tableLabel(st.table)}`,
    body: [
      field('Name', name),
      field('Condition every row must meet', clause, 'One SQL expression. Existing rows must already meet it.'),
      preview,
    ],
    foot: [
      btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'),
      h('span', { class: 'spacer' }),
      btn('Cancel', () => m.close()),
      save,
    ],
  });
}
