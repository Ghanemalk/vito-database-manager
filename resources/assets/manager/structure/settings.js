// The Settings tab: information, name, comment, options, maintenance, and duplicating, emptying or dropping the table.

import { api } from '../api.js';
import { MARIA, PG } from '../boot.js';
import { copyText, download } from '../lib/files.js';
import { formatBytes, formatNumber } from '../lib/format.js';
import { tableKey } from '../lib/sql.js';
import { resultSet } from '../pages/sql-console.js';
import { go, openTable } from '../router.js';
import { state } from '../state.js';
import { btn, busy, check, field, h, iconBtn, input, put, select } from '../ui/dom.js';
import { codeBlock } from '../ui/highlight.js';
import { modal } from '../ui/modals.js';
import { fail, toast } from '../ui/toasts.js';
import { afterDdl, runDdl } from './common.js';

/** The Settings tab. */
export function settingsTab(st, s, body) {
  const t = s.table;
  const isTable = ['table', 'partitioned'].includes(t.type);
  const kv = (rows) =>
    h(
      'table',
      { class: 'list' },
      h(
        'tbody',
        null,
        rows
          .filter((r) => r[1] !== null && r[1] !== undefined && r[1] !== '')
          .map(([k, v]) =>
            h('tr', null, h('td', { class: 'muted', style: 'width:40%' }, k), h('td', { class: 'mono' }, String(v))),
          ),
      ),
    );
  const infoRows = PG
    ? [
        ['Type', t.type],
        ['Owner', t.owner],
        ['Estimated rows', formatNumber(t.rows)],
        [
          'Live / dead rows',
          t.live_rows !== null ? `${formatNumber(t.live_rows)} / ${formatNumber(t.dead_rows)}` : null,
        ],
        ['Total size', formatBytes(t.size)],
        ['Table', formatBytes(t.data)],
        ['Indexes', formatBytes(t.index)],
        ['Row level security', t.rls ? 'on' : 'off'],
        ['Partition of', t.partition_of],
        ['Partition key', t.partition_key],
        ['Partition bound', t.partition_bound],
        ['Last vacuum', t.last_vacuum],
        ['Last analyze', t.last_analyze],
      ]
    : [
        ['Type', t.type],
        ['Engine', t.engine],
        ['Row format', t.row_format],
        ['Estimated rows', formatNumber(t.rows)],
        ['Average row', formatBytes(t.avg_row)],
        ['Data', formatBytes(t.data)],
        ['Indexes', formatBytes(t.index)],
        ['Free', formatBytes(t.free)],
        ['Next auto-increment', t.auto_increment !== null ? formatNumber(t.auto_increment) : null],
        ['Collation', t.collation],
        ['Created', t.created],
        ['Updated', t.updated],
      ];
  const cards = [];
  cards.push(h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h3', null, 'Information')), kv(infoRows)));

  const rename = input({ value: t.name });
  const renameBtn = btn(
    'Rename',
    () => {
      const to = rename.value.trim();
      if (!to || to === t.name) return;
      runDdl(
        { action: 'rename_table', table: st.table, to },
        { title: `Rename ${t.name} to ${to}?`, message: 'Queries and code that use the old name stop working.' },
      ).then((r) => {
        if (r) setTimeout(() => openTable({ schema: st.table.schema, name: to }, 'settings'), 300);
      });
    },
    'sm',
  );
  const comment = h('textarea', { class: 'input', rows: 2, value: t.comment || '' });
  const commentBtn = btn(
    'Save comment',
    () =>
      busy(commentBtn, async () => {
        try {
          await api('ddl', { action: 'table_options', table: st.table, comment: comment.value });
          toast('Comment saved');
          afterDdl({ table: st.table });
        } catch (e) {
          fail(e);
        }
      }),
    'sm',
  );
  const general = [
    field('Name', h('div', { class: 'row' }, rename, renameBtn)),
    field('Comment', comment),
    h('div', null, commentBtn),
  ];
  if (PG && state.overview && state.overview.schemas.length > 1) {
    const sch = select(
      state.overview.schemas.filter((x) => x !== st.table.schema),
      null,
    );
    general.push(
      field(
        'Move to schema',
        h(
          'div',
          { class: 'row' },
          sch,
          btn(
            'Move',
            () =>
              runDdl(
                { action: 'move_table', table: st.table, to: sch.value },
                { title: `Move ${t.name} to ${sch.value}?` },
              ).then((r) => {
                if (r) setTimeout(() => openTable({ schema: sch.value, name: t.name }, 'settings'), 300);
              }),
            'sm',
          ),
        ),
      ),
    );
  }
  cards.push(
    h(
      'div',
      { class: 'card' },
      h('div', { class: 'card-head' }, h('h3', null, 'General')),
      h('div', { class: 'card-body stack' }, general),
    ),
  );

  if (!PG && isTable) {
    const engines = ['InnoDB', 'MyISAM', ...(MARIA ? ['Aria'] : []), 'MEMORY', 'ARCHIVE'];
    const eng = select(engines.includes(t.engine) ? engines : [t.engine, ...engines], t.engine);
    const ai = input({ value: t.auto_increment ?? '', inputmode: 'numeric' });
    const coll = input({ class: 'input mono', value: t.collation || '' });
    let convert = false;
    const optsBtn = btn(
      'Save options',
      () => {
        const a = { action: 'table_options', table: st.table };
        if (eng.value !== t.engine) a.engine = eng.value;
        if (ai.value !== String(t.auto_increment ?? '') && ai.value !== '') a.auto_increment = ai.value;
        if (coll.value && coll.value !== t.collation) {
          a.collation = coll.value;
          a.convert = convert;
        }
        if (Object.keys(a).length === 2) return toast('Nothing to change.');
        runDdl(a, {
          title: 'Change the table options?',
          message:
            a.engine || a.convert
              ? 'Changing the engine or converting the character set rebuilds the table; it is locked while that runs.'
              : null,
        });
      },
      'sm',
    );
    cards.push(
      h(
        'div',
        { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Options')),
        h(
          'div',
          { class: 'card-body stack' },
          h('div', { class: 'grid2' }, field('Engine', eng), field('Next auto-increment value', ai)),
          field('Collation', coll),
          check('Convert the existing text to this collation (CONVERT TO CHARACTER SET)', false, (v) => (convert = v)),
          h('div', null, optsBtn),
        ),
      ),
    );
  }

  const tasks = PG
    ? [
        ['vacuum', 'Vacuum', 'Reclaims space from dead rows and refreshes statistics.'],
        ['analyze', 'Analyze', 'Refreshes the planner statistics.'],
        ['reindex', 'Reindex', 'Rebuilds every index of the table.'],
        ['vacuum_full', 'Vacuum full', 'Rewrites the whole table to return space to the disk. Locks the table.'],
        ...(t.type === 'matview' ? [['refresh', 'Refresh', 'Recomputes the materialized view.']] : []),
      ]
    : [
        ['analyze', 'Analyze', 'Refreshes index statistics.'],
        ['optimize', 'Optimize', 'Rebuilds the table to reclaim space. Locks the table.'],
        ['check', 'Check', 'Checks the table for errors.'],
        ['repair', 'Repair', 'Repairs a damaged MyISAM or Aria table.'],
        ['checksum', 'Checksum', 'Computes a checksum of the data.'],
      ];
  cards.push(
    h(
      'div',
      { class: 'card' },
      h('div', { class: 'card-head' }, h('h3', null, 'Maintenance')),
      h(
        'table',
        { class: 'list' },
        h(
          'tbody',
          null,
          tasks.map(([task, label, desc]) => {
            const b = btn(
              label,
              () =>
                busy(b, async () => {
                  try {
                    const r = await api('ddl', { action: 'maintenance', table: st.table, task });
                    const sets = r.results.flatMap((x) => x.sets);
                    modal({
                      title: `${label} · ${t.name}`,
                      body: sets.length ? sets.map((set) => resultSet(set)) : h('p', { class: 'lead' }, 'Done.'),
                      size: 'mid',
                    });
                    st.structure = null;
                  } catch (e) {
                    fail(e);
                  }
                }),
              'sm',
            );
            return h(
              'tr',
              null,
              h('td', null, h('b', null, label), h('div', { class: 'small muted' }, desc)),
              h('td', { class: 'actions' }, b),
            );
          }),
        ),
      ),
    ),
  );

  if (isTable) {
    const dupName = input({ value: t.name + '_copy' });
    let withData = true;
    cards.push(
      h(
        'div',
        { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Duplicate')),
        h(
          'div',
          { class: 'card-body stack' },
          field('New table name', dupName),
          check('Copy the rows too', true, (v) => (withData = v)),
          h(
            'div',
            null,
            btn(
              'Duplicate',
              () =>
                runDdl(
                  { action: 'duplicate_table', table: st.table, to: dupName.value.trim(), data: withData },
                  { title: `Create ${dupName.value.trim()} as a copy?` },
                ).then((r) => {
                  if (r) toast('Table duplicated');
                }),
              'sm',
              'copy',
            ),
          ),
        ),
      ),
    );
  }

  cards.push(
    h(
      'div',
      { class: 'card', style: 'grid-column:1/-1' },
      h(
        'div',
        { class: 'card-head' },
        h('h3', null, PG ? 'Definition' : 'CREATE statement'),
        iconBtn('copy', 'Copy', () => copyText(s.ddl), 'ghost sm'),
        iconBtn('download', 'Download', () => download(s.ddl + '\n', `${t.name}.sql`, 'application/sql'), 'ghost sm'),
      ),
      codeBlock(s.ddl || ''),
    ),
  );

  const danger = [];
  if (isTable) {
    let opt1 = false,
      opt2 = false;
    danger.push(
      h(
        'tr',
        null,
        h(
          'td',
          null,
          h('b', null, 'Empty the table'),
          h('div', { class: 'small muted' }, 'Deletes every row at once (TRUNCATE).'),
          h(
            'div',
            { class: 'row', style: 'gap:16px;margin-top:6px;flex-wrap:wrap' },
            PG
              ? check('Restart identity sequences', false, (v) => (opt1 = v))
              : check('Ignore foreign key checks', false, (v) => (opt1 = v)),
            PG ? check('Also empty tables that reference it (CASCADE)', false, (v) => (opt2 = v)) : null,
          ),
        ),
        h(
          'td',
          { class: 'actions' },
          btn(
            'Empty',
            () =>
              runDdl(
                {
                  action: 'truncate_table',
                  table: st.table,
                  ...(PG ? { restart_identity: opt1, cascade: opt2 } : { ignore_fk: opt1 }),
                },
                {
                  title: `Empty ${t.name}?`,
                  message: 'Every row is deleted. This cannot be undone.',
                  confirm: 'Empty table',
                  danger: true,
                  typed: t.name,
                },
              ).then((r) => r && toast('Table emptied')),
            'danger sm',
          ),
        ),
      ),
    );
  }
  let cascade = false;
  danger.push(
    h(
      'tr',
      null,
      h(
        'td',
        null,
        h('b', null, `Drop the ${t.type}`),
        h('div', { class: 'small muted' }, 'Deletes it with all its rows, indexes and triggers.'),
        PG
          ? h(
              'div',
              { style: 'margin-top:6px' },
              check('Also drop objects that depend on it (CASCADE)', false, (v) => (cascade = v)),
            )
          : null,
      ),
      h(
        'td',
        { class: 'actions' },
        btn(
          'Drop',
          () =>
            runDdl(
              { action: 'drop_table', table: st.table, ...(PG ? { cascade } : {}) },
              {
                title: `Drop ${t.name}?`,
                message: 'It is deleted with all its data. This cannot be undone.',
                confirm: `Drop ${t.type}`,
                danger: true,
                typed: t.name,
              },
            ).then((r) => {
              if (r) {
                toast(`${t.name} dropped`);
                state.tables.delete(tableKey(st.table));
                go({ view: 'overview' });
              }
            }),
          'danger sm',
        ),
      ),
    ),
  );
  cards.push(
    h(
      'div',
      { class: 'card', style: 'grid-column:1/-1;border-color:oklch(0.577 0.245 27.325 / 40%)' },
      h('div', { class: 'card-head' }, h('h3', { style: 'color:var(--destructive)' }, 'Danger zone')),
      h('table', { class: 'list' }, h('tbody', null, danger)),
    ),
  );
  put(body, h('div', { class: 'grid2', style: 'align-items:start' }, cards));
}
