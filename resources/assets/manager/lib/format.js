// Numbers, sizes and durations as people read them.

const numberFormat = new Intl.NumberFormat();
const compactFormat = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });

/** A number with thousands separators; — for none. */
export const formatNumber = (n) => (n === null || n === undefined || n === '' ? '—' : numberFormat.format(Number(n)));

/** A short number for tight places: 9,999, then 12K, 1.2M… */
export const formatCompact = (n) =>
  n === null || n === undefined ? '' : n < 10000 ? numberFormat.format(n) : compactFormat.format(n);

/** A size in B, KB, MB, GB or TB. */
export function formatBytes(n) {
  if (n === null || n === undefined) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let v = Number(n);
  let i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return (i === 0 ? v : v.toFixed(v < 10 ? 1 : 0)) + ' ' + units[i];
}

/** Seconds as 3d 4h, 2h 5m, 4m 10s or 12s. */
export function formatDuration(s) {
  if (s === null || s === undefined) return '—';
  const d = Math.floor(s / 86400),
    hh = Math.floor((s % 86400) / 3600),
    m = Math.floor((s % 3600) / 60);
  return d ? `${d}d ${hh}h` : hh ? `${hh}h ${m}m` : m ? `${m}m ${s % 60}s` : `${s}s`;
}

/** How long a query took. */
export const formatMs = (ms) =>
  ms === null || ms === undefined
    ? ''
    : ms < 1
      ? `${Number(ms).toFixed(2)} ms`
      : ms < 1000
        ? `${Math.round(ms)} ms`
        : `${(ms / 1000).toFixed(2)} s`;

/** plural(3, 'row') is "3 rows"; many is for irregular words. */
export const plural = (n, one, many) => `${formatNumber(n)} ${n === 1 ? one : many || one + 's'}`;

/** Calls fn once the calls have stopped for ms milliseconds. */
export const debounce = (fn, ms) => {
  let t;
  return (...a) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...a), ms);
  };
};
