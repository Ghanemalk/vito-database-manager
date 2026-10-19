/* Database Manager for Vito — runs on Vito's own pages.
 *
 * On a server's Databases page it adds a "Database Manager" button and an "Open" button per database. Opening one
 * shows the manager inside Vito's layout, in place of the page's content: Vito's navigation, header and sidebar
 * stay where they are. The manager runs in a frame of its own page (?embed=1), so neither side's styles or scripts
 * can disturb the other. The address bar carries the open database (?manager=<id>) and the manager's view (the
 * hash), so a reload or a shared link opens the same place. Vito renders the page with React, so everything here
 * is placed after each render and borrows the page's own classes to look native. */
(() => {
  'use strict';

  const me = document.currentScript;
  const base = ((me && me.dataset.base) || location.origin).replace(/\/+$/, '');
  const basePath = new URL(base, location.href).pathname.replace(/\/+$/, '');
  const MARK = 'data-vdbm';
  const cache = new Map(); // server id -> {at, data}; at 0: known, but due to be fetched again
  const pending = new Map(); // server id -> the fetch in flight
  const failures = new Map(); // server id -> {count, until}
  let drawn = null; // the list the buttons on the page were made from
  let titleBefore = null;
  let titleSet = null;
  let missingSince = 0;

  const ICON =
    '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>';
  // While the manager is open, Vito's sidebar takes its own collapsed look (only the icon rail, as on Vito's pages
  // without a second menu), so the manager's table list is the only one; the user's sidebar setting is untouched.
  const STYLE = `
html.vdbm-open [data-slot="sidebar-wrapper"] { --sidebar-width: calc(var(--sidebar-width-icon) + 1px) !important; }
html.vdbm-open [data-slot="sidebar-wrapper"] > [data-slot="sidebar"]
  [data-sidebar="sidebar"] > [data-slot="sidebar"]:not(:first-child) { display: none !important; }
html.vdbm-open [data-vdbm-content] > :not(#vdbm-host) { display: none !important; }
html.vdbm-open, html.vdbm-open body { overflow: hidden; }
#vdbm-host { display: block; width: 100%; min-height: 320px; }
#vdbm-host > iframe { display: block; width: 100%; height: 100%; border: 0; background: transparent; }`;

  function serverIdOf(path) {
    if (basePath && path.startsWith(basePath)) path = path.slice(basePath.length);
    const m = /^\/servers\/(\d+)\/database\/?$/.exec(path);
    return m ? m[1] : null;
  }
  const serverId = () => serverIdOf(location.pathname);
  function wanted() {
    const v = new URLSearchParams(location.search).get('manager');
    return v && /^\d+$/.test(v) ? v : null;
  }
  const frameUrl = (server, db, mode) => `${base}/servers/${server}/database-manager/${db}?${mode}=1`;

  // ── the database list ───────────────────────────────────────────────────────────────────
  // It is kept for the tab's session, so on every later visit the buttons are drawn with Vito's own render and the
  // list is fetched again behind them.
  const storeKey = (id) => `vdbm:list:${base}:${id}`;
  const usable = (data) => !!(data && data.ok && Array.isArray(data.databases));

  function remember(id, data) {
    cache.set(id, { at: Date.now(), data });
    failures.delete(id);
    try {
      sessionStorage.setItem(storeKey(id), JSON.stringify(data));
    } catch {
      // storage can be blocked; the list in memory still serves this page
    }
  }

  function forget(id) {
    cache.delete(id);
    try {
      sessionStorage.removeItem(storeKey(id));
    } catch {
      // nothing stored
    }
  }

  /** The list as last seen, however old: what the buttons are drawn from while a newer one is fetched. */
  function known(id) {
    const hit = cache.get(id);
    if (hit) return hit.data;
    try {
      const data = JSON.parse(sessionStorage.getItem(storeKey(id)) || 'null');
      if (usable(data)) {
        cache.set(id, { at: 0, data });
        return data;
      }
    } catch {
      // unreadable: fetched instead
    }
    return null;
  }

  /** Resolves to the server's list, false when this user may not open the manager there, null when it failed. */
  function list(id) {
    const hit = cache.get(id);
    if (hit && hit.at && Date.now() - hit.at < 30000) return Promise.resolve(hit.data);
    const failed = failures.get(id);
    if (failed && Date.now() < failed.until) return Promise.resolve(null);
    if (pending.has(id)) return pending.get(id);
    const promise = fetch(`${base}/servers/${id}/database-manager/list`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(async (r) => {
        const j = r.ok && !r.redirected ? await r.json().catch(() => null) : null;
        if (usable(j)) {
          remember(id, j);
          return j;
        }
        // not allowed here, or signed out: no buttons
        if (r.redirected || [401, 403, 419].includes(r.status) || (j && j.ok === false)) {
          forget(id);
          return false;
        }
        throw new Error(`list: ${r.status}`);
      })
      .catch(() => {
        // a passing failure (network, a busy server): the last list stays, and it is asked for again shortly
        const count = (failures.get(id)?.count || 0) + 1;
        const wait = Math.min(1000 * 2 ** (count - 1), 15000);
        failures.set(id, { count, until: Date.now() + wait });
        setTimeout(schedule, wait + 20);
        return null;
      })
      .finally(() => pending.delete(id));
    pending.set(id, promise);
    return promise;
  }

  // Vito served the Databases page itself: the tag carries its list
  try {
    const seed = me && me.dataset.list ? JSON.parse(me.dataset.list) : null;
    if (usable(seed) && serverId()) remember(serverId(), seed);
  } catch {
    // fetched instead
  }

  // ── the manager inside the page ─────────────────────────────────────────────────────────
  function style() {
    if (document.getElementById('vdbm-style')) return;
    const s = document.createElement('style');
    s.id = 'vdbm-style';
    s.textContent = STYLE;
    document.head.append(s);
  }

  /** The element Vito renders the page's content into, below its header. */
  function contentArea() {
    const inset = document.querySelector('main[data-slot="sidebar-inset"]');
    if (!inset) return null;
    return Array.from(inset.children).find((el) => el.tagName === 'DIV' && el.classList.contains('flex-1')) || null;
  }

  function fit() {
    const host = document.getElementById('vdbm-host');
    if (!host) return;
    const top = Math.max(0, Math.round(host.getBoundingClientRect().top + window.scrollY));
    host.style.height = `calc(100dvh - ${top}px)`;
  }

  function mount(server, db) {
    const content = contentArea();
    if (!content) {
      // React may not have rendered yet; past that, this Vito is laid out differently: open the page on its own
      missingSince = missingSince || Date.now();
      if (Date.now() - missingSince > 4000) location.replace(frameUrl(server, db, 'standalone') + location.hash);
      else setTimeout(schedule, 250);
      return;
    }
    missingSince = 0;
    let host = document.getElementById('vdbm-host');
    if (host && host.parentElement !== content) {
      host.remove();
      host = null;
    }
    if (host && host.dataset.server === server && host.dataset.db === db) {
      fit();
      return;
    }
    style();
    if (!host) {
      host = document.createElement('div');
      host.id = 'vdbm-host';
      content.setAttribute('data-vdbm-content', '');
      content.append(host);
    }
    host.dataset.server = server;
    host.dataset.db = db;
    const frame = document.createElement('iframe');
    frame.title = 'Database Manager';
    frame.allow = 'clipboard-read; clipboard-write';
    frame.src = frameUrl(server, db, 'embed') + location.hash;
    frame.addEventListener('load', () => {
      // a frame that did not get the manager (signed out, blocked from framing, an error page): show it on its own
      let ok = false;
      try {
        const doc = frame.contentDocument;
        if (doc && doc.location.href === 'about:blank') return;
        ok = !!(doc && doc.getElementById('vdbm-boot'));
      } catch {
        ok = false;
      }
      if (!ok) location.replace(frameUrl(server, host.dataset.db, 'standalone') + location.hash);
    });
    host.replaceChildren(frame);
    if (titleBefore === null) titleBefore = document.title;
    document.documentElement.classList.add('vdbm-open');
    window.scrollTo(0, 0);
    fit();
  }

  function unmount() {
    const host = document.getElementById('vdbm-host');
    if (host) {
      host.parentElement?.removeAttribute('data-vdbm-content');
      host.remove();
    }
    document.documentElement.classList.remove('vdbm-open');
    // only while it is still ours: after a visit to another page Vito has already named the tab
    if (titleBefore !== null && document.title === titleSet) document.title = titleBefore;
    titleBefore = null;
    titleSet = null;
  }

  function addressFor(db, hash) {
    const u = new URL(location.href);
    if (db) u.searchParams.set('manager', db);
    else u.searchParams.delete('manager');
    u.hash = hash || '';
    return u.toString();
  }

  function open(db) {
    // Vito's page stays the same Inertia page, so its history state goes along
    history.pushState(history.state, '', addressFor(String(db), ''));
    run();
  }

  function close() {
    history.pushState(history.state, '', addressFor(null, ''));
    run();
  }

  window.addEventListener('message', (e) => {
    if (e.origin !== location.origin || !e.data || e.data.vdbm !== 1) return;
    const host = document.getElementById('vdbm-host');
    const frame = host && host.querySelector('iframe');
    if (!frame || e.source !== frame.contentWindow) return;
    if (e.data.type === 'state') {
      const db = e.data.db ? String(e.data.db) : host.dataset.db;
      host.dataset.db = db;
      history.replaceState(history.state, '', addressFor(db, typeof e.data.hash === 'string' ? e.data.hash : ''));
      if (typeof e.data.title === 'string' && e.data.title) document.title = titleSet = `${e.data.title} - Vito`;
    } else if (e.data.type === 'close') {
      close();
    }
  });
  window.addEventListener('resize', fit);

  // ── buttons on the Databases page ───────────────────────────────────────────────────────
  function svg() {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const attrs = {
      viewBox: '0 0 24 24',
      width: '24',
      height: '24',
      fill: 'none',
      stroke: 'currentColor',
      'stroke-width': '2',
      'stroke-linecap': 'round',
      'stroke-linejoin': 'round',
      'aria-hidden': 'true',
    };
    for (const [k, v] of Object.entries(attrs)) s.setAttribute(k, v);
    s.innerHTML = ICON; // constant markup
    return s;
  }

  function link(db, className, label, labelClass, title) {
    const a = document.createElement('a');
    a.href = db.url;
    a.className = className;
    a.setAttribute(MARK, '');
    a.title = title;
    a.append(svg());
    const span = document.createElement('span');
    if (labelClass) span.className = labelClass;
    span.textContent = label;
    a.append(span);
    a.addEventListener('click', (e) => {
      // keep the click away from the row's own handlers; a modified click opens a new tab as usual
      e.stopPropagation();
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      open(db.id);
    });
    return a;
  }

  function inject(data) {
    // the header: next to Docs / Sync / Create
    const docs = document.querySelector('a[href*="vitodeploy.com/docs/servers/database"]');
    const group = docs && docs.parentElement;
    if (group && data.databases[0] && !group.querySelector(`[${MARK}]`)) {
      const outline = docs.querySelector('button');
      const cls = outline ? outline.className : '';
      group.insertBefore(
        link(
          data.databases[0],
          cls,
          'Database Manager',
          'hidden lg:block',
          'Browse and edit tables, run SQL and watch queries',
        ),
        group.firstChild,
      );
    }

    // each row: an "Open" button before the row menu
    let missing = 0;
    const byName = new Map(data.databases.map((d) => [d.name, d]));
    const outlineCls = docs && docs.querySelector('button') ? docs.querySelector('button').className : '';
    for (const tr of document.querySelectorAll('table tbody tr')) {
      if (tr.querySelector(`[${MARK}]`)) continue;
      const trigger = Array.from(tr.querySelectorAll('button')).find(
        (b) => (b.textContent || '').trim() === 'Open menu',
      );
      if (!trigger) continue;
      let db = null;
      for (const td of tr.cells) {
        const text = (td.textContent || '').trim();
        if (byName.has(text)) {
          db = byName.get(text);
          break;
        }
      }
      if (!db) {
        missing++;
        continue;
      }
      const holder = trigger.closest('div') || trigger.parentElement;
      const a = link(db, outlineCls.replace(/\bh-9\b/g, 'h-8'), 'Open', '', `Open ${db.name} in the Database Manager`);
      a.style.marginInlineEnd = '6px';
      a.style.height = '2rem';
      holder.insertBefore(a, holder.firstChild);
    }
    return missing;
  }

  function clearButtons() {
    drawn = null;
    for (const el of document.querySelectorAll(`[${MARK}]`)) el.remove();
  }

  /** Puts the buttons in from this list, first taking out any made from a different one. */
  function draw(data) {
    const made = JSON.stringify(data.databases);
    if (drawn !== null && drawn !== made) clearButtons();
    drawn = made;
    return inject(data);
  }

  let timer = null;
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(run, 60);
  }
  async function run() {
    const id = serverId();
    const db = id ? wanted() : null;
    if (id && db) mount(id, db);
    else unmount();
    if (!id) return;
    const before = known(id);
    if (before) draw(before);
    const data = await list(id);
    if (serverId() !== id) return;
    if (data === false) clearButtons();
    if (!data) return;
    // a row we can't match is most likely a database created since the list was fetched
    const hit = cache.get(id);
    if (draw(data) > 0 && hit && Date.now() - hit.at > 5000) {
      hit.at = 0;
      schedule();
    }
  }

  const observer = new MutationObserver(() => {
    const id = serverId();
    // Observer callbacks run before the browser paints, so buttons drawn here appear with Vito's own render.
    const data = id ? known(id) : null;
    if (data) draw(data);
    if (id || document.getElementById('vdbm-host')) schedule();
  });
  const start = () => {
    observer.observe(document.body, { childList: true, subtree: true });
    run();
  };
  document.addEventListener('inertia:start', (e) => {
    // fetch while Vito is still loading the page, so the list is there for its first render
    const url = e.detail && e.detail.visit && e.detail.visit.url;
    const id = url ? serverIdOf(new URL(String(url), location.href).pathname) : null;
    if (id) list(id);
  });
  document.addEventListener('inertia:navigate', () => {
    // the list may have changed (a database was created or deleted): fetched again behind the buttons
    const hit = cache.get(serverId());
    if (hit && Date.now() - hit.at > 2000) hit.at = 0;
    schedule();
  });
  window.addEventListener('popstate', schedule);
  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);
})();
