// The address bar: #/ (overview), #/table/<schema or ->/<name>[/<tab>], #/sql and #/monitor.

import { render } from './shell.js';

/** The route the address bar shows: { view, table?, tab? }. */
export function parseHash() {
  const parts = location.hash
    .replace(/^#\/?/, '')
    .split('/')
    .filter((p) => p !== '')
    .map((p) => {
      try {
        return decodeURIComponent(p);
      } catch {
        return p;
      }
    });
  if (parts[0] === 'table' && parts.length >= 3) {
    return {
      view: 'table',
      table: { schema: parts[1] === '-' ? null : parts[1], name: parts[2] },
      tab: parts[3] || 'rows',
    };
  }
  if (parts[0] === 'sql') return { view: 'sql' };
  if (parts[0] === 'monitor') return { view: 'monitor' };
  return { view: 'overview' };
}

/** The address of a route; parseHash() reads it back. */
export function hashOf(r) {
  if (r.view === 'table') {
    return (
      '#/table/' +
      encodeURIComponent(r.table.schema ?? '-') +
      '/' +
      encodeURIComponent(r.table.name) +
      (r.tab && r.tab !== 'rows' ? '/' + r.tab : '')
    );
  }
  return r.view === 'overview' ? '#/' : '#/' + r.view;
}

/** Shows a route. The same route again draws the page again. */
export const go = (r) => {
  const target = hashOf(r);
  if (location.hash === target) render();
  else location.hash = target;
};

/** Shows a table's page, on the Rows tab unless another is given. */
export const openTable = (t, tab = 'rows') =>
  go({ view: 'table', table: { schema: t.schema ?? null, name: t.name }, tab });
