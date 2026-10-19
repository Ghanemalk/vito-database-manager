// The Processes page: what the server is running, with the option to stop a query or end a connection.

import { api } from '../api.js';
import { ENGINE, PG, STORAGE_PREFIX } from '../boot.js';
import { formatBytes, formatCompact, formatDuration, formatNumber, plural } from '../lib/format.js';
import { storage } from '../lib/storage.js';
import { mainEl, stopMonitor } from '../shell.js';
import { state } from '../state.js';
import { btn, busy, fill, h, spinner, toggle } from '../ui/dom.js';
import { icon } from '../ui/icons.js';
import { confirmBox } from '../ui/modals.js';
import { fail, toast } from '../ui/toasts.js';
import { stat } from './overview.js';

/** Draws the Processes page. */
export function renderMonitor() {
  let auto = storage.get(STORAGE_PREFIX + ':monauto', true);
  let hideIdle = storage.get(STORAGE_PREFIX + ':monidle', true);
  let last = null;
  const statsEl = h('div', { class: 'stats' });
  const listEl = h('div', { class: 'card' });
  const errEl = h('div');
  const page = h(
    'div',
    { class: 'page' },
    h(
      'div',
      { class: 'page-head' },
      h(
        'div',
        { class: 'grow' },
        h('h1', null, 'Processes'),
        h('div', { class: 'sub' }, `Connections and queries running on the ${ENGINE} server`),
      ),
      h(
        'div',
        { class: 'row' },
        toggle('Hide idle', hideIdle, (v) => {
          hideIdle = v;
          storage.set(STORAGE_PREFIX + ':monidle', v);
          draw();
        }),
        toggle('Refresh every 3 s', auto, (v) => {
          auto = v;
          storage.set(STORAGE_PREFIX + ':monauto', v);
          schedule();
        }),
        btn('Refresh', (e) => busy(e.currentTarget, load), '', 'refresh'),
      ),
    ),
    errEl,
    statsEl,
    listEl,
  );
  fill(mainEl, page);
  let inflight = false;
  async function load() {
    if (inflight) return;
    inflight = true;
    try {
      last = await api('monitor');
      fill(errEl);
      draw();
    } catch (e) {
      fill(errEl, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
    } finally {
      inflight = false;
    }
  }
  function schedule() {
    stopMonitor();
    if (auto)
      state.monitorTimer = setInterval(() => !document.hidden && state.route.view === 'monitor' && load(), 3000);
  }
  function draw() {
    if (!last) {
      fill(listEl, h('div', { class: 'empty' }, spinner()));
      return;
    }
    const s = last.stats;
    fill(
      statsEl,
      stat('Uptime', formatDuration(s.uptime)),
      stat(
        'Connections',
        `${formatNumber(s.connections)}${s.max_connections ? ' / ' + formatNumber(s.max_connections) : ''}`,
        s.max_used_connections !== null && s.max_used_connections !== undefined
          ? `peak ${formatNumber(s.max_used_connections)}`
          : null,
      ),
      stat('Running now', formatNumber(s.running)),
      stat(
        'Queries',
        formatCompact(s.queries),
        s.slow_queries !== null && s.slow_queries !== undefined ? `${formatNumber(s.slow_queries)} slow` : null,
      ),
      stat(
        'Cache hit rate',
        s.cache_hit !== null && s.cache_hit !== undefined ? (s.cache_hit * 100).toFixed(2) + '%' : '—',
        s.buffer_pool ? `buffer ${formatBytes(s.buffer_pool)}` : null,
      ),
      stat(
        'Commits',
        formatCompact(s.commits),
        s.rollbacks !== null && s.rollbacks !== undefined ? `${formatCompact(s.rollbacks)} rollbacks` : null,
      ),
      stat(PG ? 'Deadlocks' : 'Lock waits', formatNumber(PG ? s.deadlocks : s.lock_waits)),
      s.received !== null && s.received !== undefined
        ? stat('Traffic', `${formatBytes(s.received)} in`, `${formatBytes(s.sent)} out`)
        : null,
    );
    const idle = (p) =>
      /^(sleep|idle)$/i.test(String(p.command || p.state || '')) || /^idle$/i.test(String(p.state || ''));
    const procs = last.processes.filter((p) => !hideIdle || !idle(p) || p.self);
    fill(
      listEl,
      h(
        'div',
        { class: 'card-head' },
        h('h3', null, `${plural(procs.length, 'connection')}`),
        hideIdle ? h('span', { class: 'small muted' }, `${last.processes.length - procs.length} idle hidden`) : null,
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
              h('th', null, 'ID'),
              h('th', null, 'User'),
              h('th', null, PG ? 'Client' : 'Host'),
              h('th', null, 'Database'),
              h('th', null, 'State'),
              h('th', { class: 'num' }, 'Time'),
              h('th', null, 'Query'),
              h('th'),
            ),
          ),
          h(
            'tbody',
            null,
            procs.map((p) =>
              h(
                'tr',
                null,
                h('td', { class: 'mono' }, String(p.id)),
                h('td', null, p.user || ''),
                h('td', { class: 'muted small' }, [p.app, p.host].filter(Boolean).join(' · ')),
                h('td', null, p.db || ''),
                h(
                  'td',
                  null,
                  h('span', { class: 'badge' }, p.command || ''),
                  p.state ? h('div', { class: 'small muted' }, p.state) : null,
                ),
                h('td', { class: 'num' }, p.time !== null ? formatDuration(p.time) : ''),
                h(
                  'td',
                  {
                    class: 'mono small',
                    style: 'max-width:440px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap',
                    title: p.info || '',
                  },
                  p.info || '',
                ),
                h(
                  'td',
                  { class: 'actions' },
                  p.self
                    ? h('span', { class: 'badge solid' }, 'this page')
                    : [
                        p.info ? btn(PG ? 'Cancel' : 'Kill query', () => kill(p, false), 'sm') : null,
                        btn(PG ? 'Terminate' : 'Kill', () => kill(p, true), 'danger sm'),
                      ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
  async function kill(p, connection) {
    const ok = await confirmBox({
      title: connection ? `End connection ${p.id}?` : `Stop the query of connection ${p.id}?`,
      message: connection
        ? 'The connection is closed; an open transaction on it is rolled back.'
        : 'The running statement is cancelled; the connection stays open.',
      sql: p.info || null,
      confirm: connection ? 'End connection' : 'Stop query',
      danger: true,
    });
    if (!ok) return;
    try {
      await api('kill', { id: String(p.id), connection });
      toast(connection ? `Connection ${p.id} ended` : `Query of ${p.id} stopped`);
      load();
    } catch (e) {
      fail(e);
    }
  }
  draw();
  load();
  schedule();
}
