// The Columns tab and the column form.

import { api } from '../api.js';
import { BOOT, PG } from '../boot.js';
import { debounce, plural } from '../lib/format.js';
import { quoteIdent, tableLabel } from '../lib/sql.js';
import { btn, busy, field, fill, h, iconBtn, input, put, select, toggle } from '../ui/dom.js';
import { codeBlock } from '../ui/highlight.js';
import { icon } from '../ui/icons.js';
import { modal } from '../ui/modals.js';
import { toast } from '../ui/toasts.js';
import { NUMERIC_CATEGORIES } from '../values.js';
import { afterDdl, runDdl } from './common.js';
import { isIntType, isTextType, isTimeType, typeList, typeName } from './types.js';

/** The Columns tab. s is the structure from the API. */
export function columnsTab(st, s, body) {
  const fkCols = new Set(s.foreign_keys.flatMap((f) => f.columns));
  const uniq = new Set(
    s.indexes.filter((i) => i.unique && !i.primary && i.columns.length === 1).map((i) => i.columns[0].name),
  );
  const pk = new Set(s.key.mode === 'pk' ? s.key.cols : []);
  const editable = ['table', 'partitioned'].includes(s.table.type);
  put(
    body,
    h(
      'div',
      { class: 'card' },
      h(
        'div',
        { class: 'card-head' },
        h('h3', null, `${plural(s.columns.length, 'column')}`),
        editable ? btn('Add column', () => columnDialog(st, s, null), 'primary sm', 'plus') : null,
      ),
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
              h('th', null, 'Null'),
              h('th', null, 'Default'),
              h('th', null, 'Comment'),
              h('th'),
            ),
          ),
          h(
            'tbody',
            null,
            s.columns.map((c) =>
              h(
                'tr',
                null,
                h(
                  'td',
                  null,
                  h(
                    'div',
                    { class: 'row', style: 'gap:6px' },
                    h('b', { class: 'mono', style: 'font-weight:600' }, c.name),
                    pk.has(c.name) ? h('span', { class: 'badge pk' }, 'PK') : null,
                    uniq.has(c.name) ? h('span', { class: 'badge uni' }, 'UNIQUE') : null,
                    fkCols.has(c.name) ? h('span', { class: 'badge fk' }, 'FK') : null,
                  ),
                ),
                h(
                  'td',
                  { class: 'mono' },
                  typeName(c),
                  c.collation && !PG && c.cat !== 'json' ? h('div', { class: 'small muted' }, c.collation) : null,
                ),
                h(
                  'td',
                  null,
                  c.nullable ? h('span', { class: 'badge' }, 'NULL') : h('span', { class: 'badge solid' }, 'NOT NULL'),
                ),
                h('td', { class: 'mono small' }, defaultLabel(c)),
                h('td', { class: 'muted', style: 'max-width:260px' }, c.comment || ''),
                h(
                  'td',
                  { class: 'actions' },
                  editable
                    ? [
                        iconBtn('edit', 'Edit column', () => columnDialog(st, s, c), 'ghost sm'),
                        iconBtn(
                          'trash',
                          'Drop column',
                          () =>
                            runDdl(
                              { action: 'drop_column', table: st.table, columns: [c.name] },
                              {
                                title: `Drop the column ${c.name}?`,
                                message: 'Its data is deleted for every row. This cannot be undone.',
                                confirm: 'Drop column',
                                danger: true,
                              },
                            ),
                          'ghost sm',
                        ),
                      ]
                    : null,
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

/** A column's default as shown in the list, with ON UPDATE when it has one. */
function defaultLabel(c) {
  const base = defaultValueLabel(c);
  return c.on_update
    ? h('span', null, base, ' ', h('span', { class: 'badge', title: `ON UPDATE ${c.on_update}` }, 'on update'))
    : base;
}

/** A column's default: a value, an expression, NULL, auto increment or a generated expression. */
function defaultValueLabel(c) {
  const d = c.default;
  if (d.kind === 'auto')
    return h(
      'span',
      { class: 'badge' },
      PG ? `identity ${c.identity === 'always' ? 'always' : 'by default'}` : 'auto increment',
    );
  if (d.kind === 'generated' || c.generated)
    return h(
      'span',
      { title: c.expression || '' },
      h('span', { class: 'badge' }, c.stored || PG ? 'stored' : 'virtual'),
      ' = ',
      c.expression || '',
    );
  if (d.kind === 'literal')
    return NUMERIC_CATEGORIES.has(c.cat) && /^-?[\d.]+$/.test(d.value) ? d.value : JSON.stringify(d.value);
  if (d.kind === 'expr') return d.value;
  if (d.kind === 'null') return 'NULL';
  return h('span', { class: 'muted' }, '—');
}

/**
 * The column form, for a new column (c = null) or a change. spec mirrors the engine's column spec.
 */
function columnDialog(st, s, c, onDone) {
  const editing = !!c;
  const spec = editing
    ? {
        name: c.name,
        type: !PG && (c.cat === 'enum' || c.cat === 'set') ? c.cat : typeName(c),
        options: c.options ? [...c.options] : [],
        nullable: c.nullable,
        default: ['none', 'null', 'literal', 'expr'].includes(c.default.kind)
          ? { kind: c.default.kind, value: c.default.value ?? '' }
          : { kind: 'none', value: '' },
        auto_increment: !PG && c.auto,
        on_update: !PG && !!c.on_update,
        identity: PG ? c.identity || '' : '',
        generated: c.generated ? { expr: c.expression || '', stored: !!c.stored } : null,
        comment: c.comment || '',
        collation: c.collation || '',
        using: '',
      }
    : {
        name: '',
        type: PG ? 'text' : 'varchar(255)',
        options: [],
        nullable: true,
        default: { kind: 'none', value: '' },
        auto_increment: false,
        on_update: false,
        identity: '',
        generated: null,
        comment: '',
        collation: '',
        using: '',
      };
  const startType = spec.type;
  let position = '';
  const form = h('div', { class: 'stack' });
  const preview = h('div');
  const saveBtn = btn(editing ? 'Save column' : 'Add column', () => save(), 'primary');
  const m = modal({
    title: editing ? `Edit column ${c.name}` : `Add a column to ${tableLabel(st.table)}`,
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

  function args() {
    const col = {
      name: spec.name.trim(),
      type: spec.type.trim(),
      nullable: spec.nullable,
      comment: spec.comment,
    };
    if (!PG && (col.type === 'enum' || col.type === 'set')) col.options = spec.options;
    if (spec.collation && isTextType(col.type)) col.collation = spec.collation;
    if (spec.generated && spec.generated.expr.trim())
      col.generated = { expr: spec.generated.expr.trim(), stored: PG ? true : spec.generated.stored };
    else {
      col.default =
        spec.default.kind === 'literal' || spec.default.kind === 'expr'
          ? { kind: spec.default.kind, value: spec.default.value }
          : { kind: spec.default.kind };
      if (!PG && spec.auto_increment) col.auto_increment = true;
      if (!PG && spec.on_update && isTimeType(col.type)) col.on_update = true;
      if (PG && spec.identity) col.identity = spec.identity;
    }
    if (PG && editing && spec.using.trim() && spec.type !== startType) col.using = spec.using.trim();
    const a = { action: editing ? 'modify_column' : 'add_column', table: st.table, column: col };
    if (editing) a.name = c.name;
    if (!PG && position === 'first') a.first = true;
    else if (!PG && position) a.after = position;
    return a;
  }
  async function showSql() {
    try {
      const r = await api('ddl', { ...args(), dry: true });
      fill(preview, h('div', { class: 'card' }, codeBlock(r.sql.join(';\n') + ';')));
    } catch (e) {
      fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
    }
  }
  async function save() {
    await busy(saveBtn, async () => {
      try {
        await api('ddl', args());
        toast(editing ? `Column ${spec.name} saved` : `Column ${spec.name} added`);
        m.sticky = false;
        m.close();
        afterDdl({ table: st.table });
        onDone && onDone();
      } catch (e) {
        fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
      }
    });
  }

  function draw() {
    const type = spec.type.trim();
    const nameIn = input({
      value: spec.name,
      placeholder: 'column_name',
      oninput: (e) => (spec.name = e.target.value),
    });
    const typeIn = input({
      value: spec.type,
      list: typeList(),
      class: 'input mono',
      oninput: (e) => {
        spec.type = e.target.value;
        drawLater();
      },
    });
    const genOn = !!spec.generated;
    const defKind = select(
      [
        ['none', 'No default'],
        ['null', 'NULL'],
        ['literal', 'A value'],
        ['expr', 'An expression or function'],
      ],
      spec.default.kind,
      {
        onchange: (e) => {
          spec.default.kind = e.target.value;
          draw();
        },
      },
    );
    const defValue =
      spec.default.kind === 'literal' || spec.default.kind === 'expr'
        ? input({
            value: spec.default.value,
            class: 'input' + (spec.default.kind === 'expr' ? ' mono' : ''),
            list: spec.default.kind === 'expr' ? (typeList(), 'vdbm-funcs') : null,
            placeholder: spec.default.kind === 'expr' ? BOOT.functions[0] : 'Value',
            oninput: (e) => (spec.default.value = e.target.value),
          })
        : null;
    const parts = [
      h(
        'div',
        { class: 'grid2' },
        field('Name', nameIn),
        field(
          'Type',
          typeIn,
          PG
            ? 'e.g. text, bigint, numeric(10,2), timestamptz, jsonb, text[]'
            : 'e.g. varchar(255), int unsigned, decimal(10,2), datetime, json',
        ),
      ),
    ];
    if (!PG && (type === 'enum' || type === 'set')) {
      parts.push(
        field(
          'Values',
          h('textarea', {
            class: 'input',
            rows: 4,
            value: spec.options.join('\n'),
            oninput: (e) =>
              (spec.options = e.target.value
                .split('\n')
                .map((x) => x.trim())
                .filter(Boolean)),
          }),
          'One value per line.',
        ),
      );
    }
    parts.push(
      h(
        'div',
        { class: 'row', style: 'gap:20px;flex-wrap:wrap' },
        toggle('Allow NULL', spec.nullable, (v) => (spec.nullable = v)),
        !PG && isIntType(type) && !genOn
          ? toggle('Auto increment', spec.auto_increment, (v) => {
              spec.auto_increment = v;
              draw();
            })
          : null,
        !PG && isTimeType(type) && !genOn
          ? toggle('Update to the current time on every change', spec.on_update, (v) => (spec.on_update = v))
          : null,
        toggle('Generated column', genOn, (v) => {
          spec.generated = v ? { expr: '', stored: true } : null;
          draw();
        }),
      ),
    );
    if (genOn) {
      parts.push(
        h(
          'div',
          { class: 'grid2' },
          field(
            'Expression',
            input({
              class: 'input mono',
              value: spec.generated.expr,
              placeholder: PG ? 'upper(name)' : "CONCAT(first, ' ', last)",
              oninput: (e) => (spec.generated.expr = e.target.value),
            }),
          ),
          PG
            ? h('div', { class: 'small muted', style: 'padding-top:28px' }, 'PostgreSQL stores generated values.')
            : field(
                'Storage',
                select(
                  [
                    ['1', 'Stored'],
                    ['0', 'Virtual (computed on read)'],
                  ],
                  spec.generated.stored ? '1' : '0',
                  { onchange: (e) => (spec.generated.stored = e.target.value === '1') },
                ),
              ),
        ),
      );
    } else if (PG && isIntType(type)) {
      parts.push(
        h(
          'div',
          { class: 'grid2' },
          field(
            'Identity',
            select(
              [
                ['', 'None'],
                ['by_default', 'Generated by default'],
                ['always', 'Generated always'],
              ],
              spec.identity,
              {
                onchange: (e) => {
                  spec.identity = e.target.value;
                  draw();
                },
              },
            ),
          ),
          spec.identity ? h('div') : field('Default', defKind),
        ),
      );
      if (!spec.identity && defValue)
        parts.push(field(spec.default.kind === 'expr' ? 'Default expression' : 'Default value', defValue));
    } else if (!spec.auto_increment) {
      parts.push(
        h(
          'div',
          { class: 'grid2' },
          field('Default', defKind),
          defValue ? field(spec.default.kind === 'expr' ? 'Expression' : 'Value', defValue) : h('div'),
        ),
      );
    }
    if (PG && editing && type !== startType) {
      parts.push(
        field(
          'Convert existing values with (USING)',
          input({
            class: 'input mono',
            value: spec.using,
            placeholder: `${quoteIdent(c.name)}::${type || 'type'}`,
            oninput: (e) => (spec.using = e.target.value),
          }),
          'Optional. Needed when PostgreSQL cannot convert the old type to the new one by itself.',
        ),
      );
    }
    const extra = [];
    if (isTextType(type))
      extra.push(
        field(
          'Collation',
          input({
            value: spec.collation,
            class: 'input mono',
            placeholder: PG ? 'default' : 'table default',
            oninput: (e) => (spec.collation = e.target.value),
          }),
        ),
      );
    if (!PG) {
      const cols = s.columns.filter((x) => !c || x.name !== c.name);
      extra.push(
        field(
          'Position',
          select(
            [
              ['', editing ? 'Keep where it is' : 'At the end'],
              ['first', 'First'],
              ...cols.map((x) => [x.name, `After ${x.name}`]),
            ],
            position,
            { onchange: (e) => (position = e.target.value) },
          ),
        ),
      );
    }
    if (extra.length) parts.push(h('div', { class: 'grid2' }, extra));
    parts.push(field('Comment', input({ value: spec.comment, oninput: (e) => (spec.comment = e.target.value) })));
    fill(form, ...parts);
  }
  const drawLater = debounce(() => {
    const focused = document.activeElement;
    const pos = focused && focused.selectionStart;
    const wasType = focused && focused.getAttribute('list') === 'vdbm-types';
    draw();
    if (wasType) {
      const el = form.querySelector('[list="vdbm-types"]');
      el.focus();
      el.setSelectionRange(pos, pos);
    }
  }, 350);
  draw();
}
