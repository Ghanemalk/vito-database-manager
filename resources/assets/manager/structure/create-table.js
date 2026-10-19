// The New table dialog.

import { api } from '../api.js';
import { MARIA, PG } from '../boot.js';
import { openTable } from '../router.js';
import { refreshOverview } from '../shell.js';
import { state } from '../state.js';
import { btn, busy, field, fill, h, iconBtn, input, select, toggle } from '../ui/dom.js';
import { codeBlock } from '../ui/highlight.js';
import { icon } from '../ui/icons.js';
import { modal } from '../ui/modals.js';
import { toast } from '../ui/toasts.js';
import { typeList } from './types.js';

/** The New table dialog. */
export function createTableDialog() {
  const schemas = PG && state.overview ? state.overview.schemas : [];
  const t = {
    name: '',
    schema: PG ? (state.schema && state.schema !== '*' ? state.schema : 'public') : null,
    comment: '',
    engine: '',
    collation: '',
  };
  const idCol = PG
    ? {
        name: 'id',
        type: 'bigint',
        nullable: false,
        pk: true,
        identity: 'by_default',
        auto: false,
        def: 'none',
        value: '',
        comment: '',
      }
    : {
        name: 'id',
        type: 'bigint unsigned',
        nullable: false,
        pk: true,
        identity: '',
        auto: true,
        def: 'none',
        value: '',
        comment: '',
      };
  const cols = [
    idCol,
    {
      name: '',
      type: PG ? 'text' : 'varchar(255)',
      nullable: true,
      pk: false,
      identity: '',
      auto: false,
      def: 'none',
      value: '',
      comment: '',
    },
  ];
  let timestamps = true;
  const form = h('div', { class: 'stack' });
  const preview = h('div');
  const saveBtn = btn('Create table', () => save(), 'primary');
  const m = modal({
    title: 'New table',
    body: [form, preview],
    foot: [
      btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'),
      h('span', { class: 'spacer' }),
      btn('Cancel', () => m.close()),
      saveBtn,
    ],
    size: 'wide',
    sticky: true,
  });

  function payload() {
    const columns = cols
      .filter((c) => c.name.trim())
      .map((c) => {
        const spec = { name: c.name.trim(), type: c.type.trim(), nullable: c.nullable && !c.pk };
        if (!PG && c.auto) spec.auto_increment = true;
        if (PG && c.identity) spec.identity = c.identity;
        if (c.def === 'null') spec.default = { kind: 'null' };
        if (c.def === 'literal' || c.def === 'expr') spec.default = { kind: c.def, value: c.value };
        if (c.comment) spec.comment = c.comment;
        return spec;
      });
    if (timestamps) {
      const ts = PG ? 'timestamptz' : 'timestamp';
      const now = PG ? 'now()' : 'CURRENT_TIMESTAMP';
      if (!columns.some((c) => c.name === 'created_at'))
        columns.push({ name: 'created_at', type: ts, nullable: false, default: { kind: 'expr', value: now } });
      if (!columns.some((c) => c.name === 'updated_at'))
        columns.push({
          name: 'updated_at',
          type: ts,
          nullable: false,
          default: { kind: 'expr', value: now },
          ...(PG ? {} : { on_update: true }),
        });
    }
    const a = {
      action: 'create_table',
      table: PG ? { schema: t.schema, name: t.name.trim() } : { schema: null, name: t.name.trim() },
      columns,
      primary: cols.filter((c) => c.pk && c.name.trim()).map((c) => c.name.trim()),
    };
    if (t.comment) a.comment = t.comment;
    if (!PG && t.engine) a.engine = t.engine;
    if (!PG && t.collation) a.collation = t.collation;
    return a;
  }
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
      const a = payload();
      try {
        await api('ddl', a);
        toast(`Table ${a.table.name} created`);
        m.sticky = false;
        m.close();
        await refreshOverview();
        openTable(a.table);
      } catch (e) {
        fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
      }
    });
  }
  function draw() {
    const rows = cols.map((c, k) => {
      const def = select(
        [
          ['none', '—'],
          ['null', 'NULL'],
          ['literal', 'Value'],
          ['expr', 'Expression'],
        ],
        c.def,
        {
          class: 'select sm',
          onchange: (e) => {
            c.def = e.target.value;
            draw();
          },
        },
      );
      return h(
        'tr',
        null,
        h(
          'td',
          null,
          input({
            class: 'input sm mono',
            value: c.name,
            placeholder: 'name',
            style: 'min-width:120px',
            oninput: (e) => (c.name = e.target.value),
          }),
        ),
        h(
          'td',
          null,
          input({
            class: 'input sm mono',
            value: c.type,
            list: typeList(),
            style: 'min-width:150px',
            oninput: (e) => (c.type = e.target.value),
          }),
        ),
        h(
          'td',
          { style: 'text-align:center' },
          h('input', {
            type: 'checkbox',
            checked: c.pk,
            title: 'Primary key',
            onchange: (e) => {
              c.pk = e.target.checked;
              draw();
            },
          }),
        ),
        h(
          'td',
          { style: 'text-align:center' },
          h('input', {
            type: 'checkbox',
            checked: c.nullable && !c.pk,
            disabled: c.pk,
            title: 'Allow NULL',
            onchange: (e) => (c.nullable = e.target.checked),
          }),
        ),
        h(
          'td',
          null,
          PG
            ? select(
                [
                  ['', '—'],
                  ['by_default', 'by default'],
                  ['always', 'always'],
                ],
                c.identity,
                { class: 'select sm', title: 'Identity', onchange: (e) => (c.identity = e.target.value) },
              )
            : h('input', {
                type: 'checkbox',
                checked: c.auto,
                title: 'Auto increment',
                onchange: (e) => (c.auto = e.target.checked),
              }),
        ),
        h(
          'td',
          null,
          h(
            'div',
            { class: 'row', style: 'gap:4px' },
            def,
            c.def === 'literal' || c.def === 'expr'
              ? input({
                  class: 'input sm' + (c.def === 'expr' ? ' mono' : ''),
                  value: c.value,
                  list: c.def === 'expr' ? 'vdbm-funcs' : null,
                  style: 'width:140px',
                  oninput: (e) => (c.value = e.target.value),
                })
              : null,
          ),
        ),
        h(
          'td',
          null,
          input({
            class: 'input sm',
            value: c.comment,
            placeholder: 'comment',
            style: 'min-width:120px',
            oninput: (e) => (c.comment = e.target.value),
          }),
        ),
        h(
          'td',
          null,
          iconBtn(
            'x',
            'Remove column',
            () => {
              cols.splice(k, 1);
              draw();
            },
            'ghost sm',
          ),
        ),
      );
    });
    fill(
      form,
      h(
        'div',
        { class: PG ? 'grid3' : 'grid2' },
        PG
          ? field(
              'Schema',
              select(schemas.length ? schemas : ['public'], t.schema, { onchange: (e) => (t.schema = e.target.value) }),
            )
          : null,
        field(
          'Table name',
          input({
            value: t.name,
            placeholder: 'e.g. orders',
            oninput: (e) => (t.name = e.target.value),
            autofocus: true,
          }),
        ),
        field('Comment', input({ value: t.comment, oninput: (e) => (t.comment = e.target.value) })),
      ),
      !PG
        ? h(
            'div',
            { class: 'grid2' },
            field(
              'Engine',
              select(
                [['', 'Server default (InnoDB)'], 'InnoDB', 'MyISAM', ...(MARIA ? ['Aria'] : []), 'MEMORY', 'ARCHIVE'],
                t.engine,
                { onchange: (e) => (t.engine = e.target.value) },
              ),
            ),
            field(
              'Collation',
              input({
                class: 'input mono',
                value: t.collation,
                placeholder: 'Database default',
                oninput: (e) => (t.collation = e.target.value),
              }),
            ),
          )
        : null,
      h(
        'div',
        { class: 'grid-wrap', style: 'max-height:none' },
        h(
          'table',
          { class: 'list' },
          h(
            'thead',
            null,
            h(
              'tr',
              null,
              h('th', null, 'Column'),
              h('th', null, 'Type'),
              h('th', null, 'Key'),
              h('th', null, 'Null'),
              h('th', null, PG ? 'Identity' : 'Auto'),
              h('th', null, 'Default'),
              h('th', null, 'Comment'),
              h('th'),
            ),
          ),
          h('tbody', null, rows),
        ),
      ),
      h(
        'div',
        { class: 'row', style: 'flex-wrap:wrap' },
        btn(
          'Add column',
          () => {
            cols.push({
              name: '',
              type: PG ? 'text' : 'varchar(255)',
              nullable: true,
              pk: false,
              identity: '',
              auto: false,
              def: 'none',
              value: '',
              comment: '',
            });
            draw();
            form.querySelectorAll('tbody tr:last-child input')[0]?.focus();
          },
          'sm',
          'plus',
        ),
        h('span', { class: 'grow' }),
        toggle('Add created_at and updated_at', timestamps, (v) => (timestamps = v)),
      ),
    );
  }
  draw();
}
