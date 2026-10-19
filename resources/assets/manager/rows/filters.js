// Filtering rows: the operators each column type offers, and the filter popover.

import { PG } from '../boot.js';
import { btn, fill, h, input, select } from '../ui/dom.js';
import { closePopover, popover } from '../ui/popovers.js';
import { NUMERIC_CATEGORIES } from '../values.js';
import { loadRows } from './grid.js';

const FILTER_OPS = [
  ['=', 'equals'],
  ['!=', 'does not equal'],
  ['contains', 'contains'],
  ['not_contains', 'does not contain'],
  ['starts', 'starts with'],
  ['ends', 'ends with'],
  ['<', 'is less than'],
  ['<=', 'is at most'],
  ['>', 'is greater than'],
  ['>=', 'is at least'],
  ['between', 'is between'],
  ['in', 'is one of'],
  ['not_in', 'is none of'],
  ['null', 'is NULL'],
  ['not_null', 'is not NULL'],
  ['empty', 'is empty'],
  ['not_empty', 'is not empty'],
  ['regexp', 'matches regex'],
];

export const FILTER_OP_LABELS = Object.fromEntries(FILTER_OPS);

/** The operators that make sense for a column, by its category. */
function filterOpsFor(c) {
  const base = ['=', '!=', 'null', 'not_null'];
  if (c.cat === 'bin') return base;
  if (c.cat === 'bool') return base;
  if (NUMERIC_CATEGORIES.has(c.cat) || ['date', 'datetime', 'time'].includes(c.cat))
    return ['=', '!=', '<', '<=', '>', '>=', 'between', 'in', 'not_in', 'null', 'not_null'];
  return [
    'contains',
    'not_contains',
    '=',
    '!=',
    'starts',
    'ends',
    'in',
    'not_in',
    'empty',
    'not_empty',
    'regexp',
    'null',
    'not_null',
    '<',
    '>',
  ];
}

/** Operators such as "is NULL" that take no value. */
export const opTakesNoValue = (op) => ['null', 'not_null', 'empty', 'not_empty'].includes(op);

/** The popover that adds a filter. */
export function filterPopover(anchor, st) {
  const d = st.data;
  if (!d) return;
  const cols = d.columns;
  const colSel = select(
    cols.map((c) => c.name),
    cols[0]?.name,
    { class: 'select sm' },
  );
  const opSel = h('select', { class: 'select sm' });
  const v1 = input({ class: 'input sm', placeholder: 'Value' });
  const v2 = input({ class: 'input sm', placeholder: 'and' });
  const hint = h('div', { class: 'small muted' });
  const sync = () => {
    const c = cols.find((x) => x.name === colSel.value);
    const ops = filterOpsFor(c);
    const keep = ops.includes(opSel.value) ? opSel.value : ops[0];
    fill(opSel, ...ops.map((o) => h('option', { value: o }, FILTER_OP_LABELS[o])));
    opSel.value = keep;
    const op = opSel.value;
    v1.classList.toggle('hidden', opTakesNoValue(op));
    v2.classList.toggle('hidden', op !== 'between');
    v1.placeholder =
      c.cat === 'bin'
        ? 'Hex, e.g. 00ff10'
        : c.cat === 'bool'
          ? PG
            ? 'true or false'
            : '1 or 0'
          : ['in', 'not_in'].includes(op)
            ? 'Values, separated by commas'
            : 'Value';
    hint.textContent = op === 'regexp' ? (PG ? 'POSIX regular expression (~).' : 'Regular expression (REGEXP).') : '';
  };
  colSel.addEventListener('change', sync);
  opSel.addEventListener('change', sync);
  const apply = () => {
    const f = { col: colSel.value, op: opSel.value };
    if (!opTakesNoValue(f.op)) f.value = v1.value;
    if (f.op === 'between') f.value2 = v2.value;
    st.filters.push(f);
    st.page = 1;
    closePopover();
    loadRows(st);
  };
  [v1, v2].forEach((el) => el.addEventListener('keydown', (e) => e.key === 'Enter' && apply()));
  sync();
  popover(
    anchor,
    h(
      'div',
      { class: 'stack', style: 'gap:8px' },
      h('div', { class: 'small muted' }, 'Show rows where'),
      colSel,
      opSel,
      v1,
      v2,
      hint,
      h('div', { class: 'row', style: 'justify-content:flex-end' }, btn('Add filter', apply, 'primary sm')),
    ),
    { pad: true, width: 300 },
  );
}
