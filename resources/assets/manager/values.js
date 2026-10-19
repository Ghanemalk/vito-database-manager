// Database values in the browser. The API sends text, null, { b, n } for binary (base64 and length) and { t, n, u }
// for a value cut short (the start, the full length and its unit).

import { PG } from './boot.js';
import { base64ToBytes, bytesToHex } from './lib/files.js';
import { formatBytes, formatNumber } from './lib/format.js';
import { h } from './ui/dom.js';

/** Whether a value from the API means true ('1', 't', 'true', 'on'). */
export const isTrue = (v) => v === '1' || v === 't' || v === 'true' || v === 'TRUE' || v === 'on';

/** true or false as the engine writes it. */
export const boolOut = (on) => (PG ? (on ? 'true' : 'false') : on ? '1' : '0');
export const NUMERIC_CATEGORIES = new Set(['int', 'num', 'year', 'bit']);

/** A binary value: { b: the bytes in base64 (only the start of a long value), n: the full length }. */
export const isBinary = (v) => v !== null && typeof v === 'object' && 'b' in v;

/** A value cut short: { t: the start, n: the full length, u: 'bytes' when n counts bytes }. */
export const isTruncated = (v) => v !== null && typeof v === 'object' && 't' in v;

/** One cell of the grid or of a query result, as DOM. */
export function cellView(v, c) {
  if (v === null || v === undefined) return h('span', { class: 'v-null' }, 'NULL');
  if (isBinary(v)) {
    if (!v.n) return h('span', { class: 'v-bin' }, '0 bytes');
    const hex = bytesToHex(base64ToBytes(v.b), 12);
    return h('span', { class: 'v-bin' }, '0x' + hex.toUpperCase() + (v.n > 12 ? '…' : '') + ` · ${formatBytes(v.n)}`);
  }
  if (isTruncated(v)) {
    const t = v.t.replace(/\r?\n/g, ' ↵ ');
    return h(
      'span',
      null,
      t,
      h('span', { class: 'v-more' }, v.u === 'bytes' ? `… ${formatBytes(v.n)}` : `… ${formatNumber(v.n)} chars`),
    );
  }
  if (v === '') return h('span', { class: 'v-empty' }, 'empty');
  if (c && c.cat === 'bool')
    return h('span', { class: 'v-bool' + (isTrue(v) ? ' on' : '') }, isTrue(v) ? 'true' : 'false');
  return String(v).replace(/\r?\n/g, ' ↵ ');
}

/** A value as text for copying and exporting; binary as 0x… hex. */
export function plainValue(v) {
  if (v === null || v === undefined) return null;
  if (isBinary(v)) return '0x' + bytesToHex(base64ToBytes(v.b)).toUpperCase();
  if (isTruncated(v)) return v.t;
  return String(v);
}

/** Rows as CSV text, quoted where needed. */
export function setToCsv(columns, rows, sep = ',') {
  const q = (s) =>
    s === null ? '' : /[",\r\n\t;]/.test(s) || s === '' || s !== s.trim() ? '"' + s.replace(/"/g, '""') + '"' : s;
  return [
    columns.map((c) => q(String(c))).join(sep),
    ...rows.map((r) => r.map((v) => q(plainValue(v))).join(sep)),
  ].join('\r\n');
}
