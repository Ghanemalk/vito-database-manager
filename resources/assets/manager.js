/* Database Manager for Vito — the browser app. No build step and no dependencies.
 * Every value from the database is written with textContent, never as HTML. */
(() => {
  'use strict';

  const BOOT = JSON.parse(document.getElementById('vdbm-boot').textContent);
  const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const FLAVOR = BOOT.engine.flavor;
  const PG = FLAVOR === 'postgresql';
  const MARIA = FLAVOR === 'mariadb';
  const ENGINE = PG ? 'PostgreSQL' : MARIA ? 'MariaDB' : 'MySQL';
  const STORE = `vdbm:${BOOT.server.id}:${BOOT.database ? BOOT.database.id : 0}`;
  const SPONSOR = 'https://github.com/sponsors/Ghanemalk';
  // Inside Vito's Databases page (see launcher.js): that page owns the address bar and the way back.
  const EMBED = BOOT.embed === true && window.parent !== window;
  const tell = (msg) => {
    if (!EMBED) return;
    try {
      window.parent.postMessage({ vdbm: 1, ...msg }, location.origin);
    } catch {
      // the page that framed us is gone
    }
  };

  // ── icons (lucide, ISC) ─────────────────────────────────────────────────────────────────
  const ICONS = {
    database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
    table: '<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>',
    view: '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
    search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
    filter: '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>',
    download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
    trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
    x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    left: '<path d="m15 18-6-6 6-6"/>',
    right: '<path d="m9 18 6-6-6-6"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    back: '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    play: '<polygon points="6 3 20 12 6 21 6 3"/>',
    history: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
    sliders: '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
    key: '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
    link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    columns: '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/>',
    list: '<line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/>',
    code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
    copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    more: '<circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>',
    up: '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
    dn: '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
    edit: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/>',
    alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    terminal: '<path d="m7 11 2-2-2-2"/><path d="M11 13h4"/><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>',
    lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    folder: '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
    zap: '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
    layers: '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    menu: '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
    open: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
  };

  // ── DOM helpers ─────────────────────────────────────────────────────────────────────────
  const PROPS = new Set(['value', 'checked', 'disabled', 'selected', 'indeterminate', 'readOnly', 'multiple', 'type', 'placeholder',
    'title', 'rows', 'spellcheck', 'htmlFor', 'tabIndex', 'href', 'target', 'accept', 'min', 'max', 'step', 'name']);

  function h(tag, props, ...kids) {
    const el = document.createElement(tag);
    add(el, kids);
    if (props) {
      for (const k of Object.keys(props)) {
        const v = props[k];
        if (v === undefined || v === null || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k === 'style') typeof v === 'string' ? (el.style.cssText = v) : Object.assign(el.style, v);
        else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else if (k === 'dataset') Object.assign(el.dataset, v);
        else if (PROPS.has(k)) el[k] = v;
        else el.setAttribute(k, v === true ? '' : String(v));
      }
    }
    return el;
  }
  function add(el, kids) {
    for (const k of kids.flat(Infinity)) {
      if (k === null || k === undefined || k === false || k === true) continue;
      el.append(k instanceof Node ? k : document.createTextNode(String(k)));
    }
    return el;
  }
  /** append and replaceChildren that skip null and false like h() does (the DOM methods would print "null"). */
  const put = (el, ...kids) => add(el, kids);
  function fill(el, ...kids) {
    el.replaceChildren();
    return add(el, kids);
  }
  function icon(name, cls) {
    const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('class', 'i' + (cls ? ' ' + cls : ''));
    s.setAttribute('aria-hidden', 'true');
    s.innerHTML = ICONS[name] || ''; // constant markup from the table above
    return s;
  }
  const btn = (label, onclick, cls = '', ic = null, extra = {}) =>
    h('button', { type: 'button', class: 'btn ' + cls, onclick, ...extra }, ic ? icon(ic) : null, label ? h('span', null, label) : null);
  const iconBtn = (ic, title, onclick, cls = 'ghost') => h('button', { type: 'button', class: 'btn icon ' + cls, title, 'aria-label': title, onclick }, icon(ic));
  const field = (label, control, hint) => h('label', { class: 'field' }, h('span', null, label), control, hint ? h('span', { class: 'hint' }, hint) : null);
  const input = (props = {}) => h('input', { class: 'input', type: 'text', spellcheck: false, autocomplete: 'off', dir: 'auto', ...props });
  const select = (options, value, props = {}) => {
    const el = h('select', { class: 'select', ...props }, options.map((o) => {
      const [v, label] = Array.isArray(o) ? o : [o, o];
      return h('option', { value: v }, label);
    }));
    if (value !== undefined && value !== null) el.value = value;
    return el;
  };
  const check = (label, checked, onchange, cls = 'check') => {
    const box = h('input', { type: 'checkbox', checked: !!checked, onchange: (e) => onchange && onchange(e.target.checked) });
    return h('label', { class: cls }, box, label ? h('span', null, label) : null);
  };
  const toggle = (label, checked, onchange) => check(label, checked, onchange, 'switch');
  const spinner = () => h('span', { class: 'spinner' });

  async function busy(button, fn) {
    if (button.disabled) return undefined;
    const old = Array.from(button.childNodes);
    button.disabled = true;
    fill(button, spinner(), ...old.filter((n) => n.nodeName === 'SPAN'));
    try {
      return await fn();
    } finally {
      button.disabled = false;
      fill(button, ...old);
    }
  }

  // ── storage and formatting ──────────────────────────────────────────────────────────────
  const ls = {
    get(k, d) {
      try {
        const v = localStorage.getItem(k);
        return v === null ? d : JSON.parse(v);
      } catch {
        return d;
      }
    },
    set(k, v) {
      try {
        localStorage.setItem(k, JSON.stringify(v));
      } catch {
        // storage full or disabled: these are conveniences only
      }
    },
  };
  const nf = new Intl.NumberFormat();
  const cf = new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 });
  const num = (n) => (n === null || n === undefined || n === '' ? '—' : nf.format(Number(n)));
  const compact = (n) => (n === null || n === undefined ? '' : n < 10000 ? nf.format(n) : cf.format(n));
  function bytes(n) {
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
  function duration(s) {
    if (s === null || s === undefined) return '—';
    const d = Math.floor(s / 86400), hh = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    return d ? `${d}d ${hh}h` : hh ? `${hh}h ${m}m` : m ? `${m}m ${s % 60}s` : `${s}s`;
  }
  const msf = (ms) => (ms === null || ms === undefined ? '' : ms < 1 ? `${Number(ms).toFixed(2)} ms` : ms < 1000 ? `${Math.round(ms)} ms` : `${(ms / 1000).toFixed(2)} s`);
  const plural = (n, one, many) => `${num(n)} ${n === 1 ? one : many || one + 's'}`;
  const debounce = (fn, ms) => {
    let t;
    return (...a) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...a), ms);
    };
  };

  // ── SQL text ────────────────────────────────────────────────────────────────────────────
  const qi = (n) => (PG ? `"${String(n).replace(/"/g, '""')}"` : '`' + String(n).replace(/`/g, '``') + '`');
  const tableSql = (t) => (PG ? `${qi(t.schema || 'public')}.${qi(t.name)}` : qi(t.name));
  const tkey = (t) => `${t.schema ?? ''}\u0001${t.name}`;
  const tlabel = (t) => (PG && t.schema && t.schema !== 'public' ? `${t.schema}.${t.name}` : t.name);
  const sameTable = (a, b) => !!a && !!b && a.name === b.name && (a.schema ?? null) === (b.schema ?? null);

  const KEYWORDS = new Set(('SELECT FROM WHERE AND OR NOT NULL IS IN AS ON JOIN LEFT RIGHT INNER OUTER FULL CROSS NATURAL GROUP BY ORDER HAVING LIMIT ' +
    'OFFSET INSERT INTO VALUES UPDATE SET DELETE CREATE TABLE ALTER DROP INDEX VIEW PRIMARY KEY FOREIGN REFERENCES UNIQUE CHECK DEFAULT ' +
    'CONSTRAINT DISTINCT UNION ALL EXISTS BETWEEN LIKE ILIKE CASE WHEN THEN ELSE END ASC DESC WITH RECURSIVE RETURNING TRUNCATE BEGIN ' +
    'COMMIT ROLLBACK START TRANSACTION IF REPLACE PROCEDURE FUNCTION TRIGGER DATABASE SCHEMA GRANT REVOKE EXPLAIN ANALYZE SHOW DESCRIBE ' +
    'USE CALL DECLARE RETURN RETURNS LANGUAGE COLUMN ADD RENAME TO MODIFY CHANGE AUTO_INCREMENT ENGINE CHARSET COLLATE TRUE FALSE INTERVAL ' +
    'CAST OVER PARTITION WINDOW FOR LOCK SHARE NOWAIT SKIP LOCKED DELIMITER DO LATERAL USING CASCADE RESTRICT TEMPORARY TEMP EXCEPT ' +
    'INTERSECT ANY SOME FETCH FIRST NEXT ROWS ONLY ROW OF IGNORE DUPLICATE CONFLICT NOTHING VACUUM OPTIMIZE SEQUENCE OWNER GENERATED ' +
    'ALWAYS IDENTITY STORED VIRTUAL COMMENT EXTENSION TYPE ENUM INT INTEGER BIGINT SMALLINT TINYINT MEDIUMINT VARCHAR CHAR TEXT BOOLEAN ' +
    'BOOL DATE DATETIME TIMESTAMP TIMESTAMPTZ TIME DECIMAL NUMERIC FLOAT DOUBLE PRECISION REAL JSON JSONB BLOB LONGTEXT MEDIUMTEXT UUID ' +
    'SERIAL BIGSERIAL BYTEA UNSIGNED VARBINARY BINARY ZONE AT LOOP WHILE ELSIF RAISE NOTICE EXCEPTION PERFORM EXECUTE').split(' '));

  /** [type, text] pairs: k keyword, s string, n number, c comment, i quoted identifier, f function, '' anything else. */
  function tokenize(src) {
    const out = [];
    const n = src.length;
    let i = 0;
    while (i < n) {
      const c = src[i], d = src[i + 1];
      if ((c === '-' && d === '-') || (!PG && c === '#')) {
        let j = src.indexOf('\n', i);
        if (j < 0) j = n;
        out.push(['c', src.slice(i, j)]);
        i = j;
      } else if (c === '/' && d === '*') {
        let j = src.indexOf('*/', i + 2);
        j = j < 0 ? n : j + 2;
        out.push(['c', src.slice(i, j)]);
        i = j;
      } else if (c === "'" || (!PG && c === '"')) {
        let j = i + 1;
        while (j < n) {
          if (!PG && src[j] === '\\') { j += 2; continue; }
          if (src[j] === c) {
            if (src[j + 1] === c) { j += 2; continue; }
            j++;
            break;
          }
          j++;
        }
        out.push(['s', src.slice(i, j)]);
        i = j;
      } else if (PG && c === '$' && /^\$(?:[A-Za-z_]\w*)?\$/.test(src.slice(i, i + 66))) {
        const tag = /^\$(?:[A-Za-z_]\w*)?\$/.exec(src.slice(i, i + 66))[0];
        let j = src.indexOf(tag, i + tag.length);
        j = j < 0 ? n : j + tag.length;
        out.push(['s', src.slice(i, j)]);
        i = j;
      } else if (c === '`' || (PG && c === '"')) {
        let j = i + 1;
        while (j < n) {
          if (src[j] === c) {
            if (src[j + 1] === c) { j += 2; continue; }
            j++;
            break;
          }
          j++;
        }
        out.push(['i', src.slice(i, j)]);
        i = j;
      } else if (c >= '0' && c <= '9') {
        const m = /^\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/.exec(src.slice(i, i + 64));
        out.push(['n', m[0]]);
        i += m[0].length;
      } else if (/[A-Za-z_]/.test(c)) {
        let j = i + 1;
        while (j < n && /[\w$]/.test(src[j])) j++;
        const w = src.slice(i, j);
        let k = j;
        while (src[k] === ' ') k++;
        out.push([KEYWORDS.has(w.toUpperCase()) ? 'k' : src[k] === '(' ? 'f' : '', w]);
        i = j;
      } else {
        let j = i + 1;
        while (j < n && !/[-#/'"`$0-9A-Za-z_]/.test(src[j])) j++;
        out.push(['', src.slice(i, j)]);
        i = j;
      }
    }
    return out;
  }
  function highlight(src, into) {
    const frag = document.createDocumentFragment();
    if (src.length > 400000) {
      put(frag, src);
    } else {
      for (const [t, s] of tokenize(src)) put(frag, t ? h('span', { class: 'tk-' + t }, s) : document.createTextNode(s));
    }
    fill(into, frag);
    return into;
  }
  const codeBlock = (sql) => highlight(sql, h('pre', { class: 'code' }));

  // ── server API ──────────────────────────────────────────────────────────────────────────
  class ApiError extends Error {
    constructor(info) {
      super(info.message || 'Something went wrong.');
      this.info = info;
      this.kind = info.kind || 'error';
    }
  }

  async function post(body) {
    let res;
    try {
      res = await fetch(BOOT.api, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body,
      });
    } catch {
      throw new ApiError({ kind: 'network', message: 'Vito could not be reached. Check your connection and try again.' });
    }
    let json = null;
    try {
      json = await res.json();
    } catch {
      // not JSON: handled below
    }
    if (res.status === 419 || res.status === 401) {
      sessionExpired();
      throw new ApiError({ kind: 'session', message: 'Your session expired. Reload the page to sign in again.' });
    }
    if (res.status === 403) throw new ApiError({ kind: 'forbidden', message: 'You do not have permission to manage this database.' });
    if (res.status === 413) throw new ApiError({ kind: 'too_large', message: 'The request is too large for the server.' });
    if (!json) throw new ApiError({ kind: 'http', message: `Vito answered with HTTP ${res.status}.` });
    if (!json.ok) throw new ApiError(json.error || { message: `Vito answered with HTTP ${res.status}.` });
    return json.data;
  }

  /** Scripts beyond PHP's post_max_size go up in parts first. */
  async function stash(text) {
    const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('');
    const size = 1000000;
    let part = 0;
    for (let at = 0; at < text.length; part++) {
      let end = Math.min(text.length, at + size);
      const code = text.charCodeAt(end - 1);
      if (end < text.length && code >= 0xd800 && code <= 0xdbff) end--; // keep surrogate pairs together
      await post(JSON.stringify({ op: 'stash', args: { id, part, data: text.slice(at, end) } }));
      at = end;
    }
    return id;
  }

  async function api(op, args = {}) {
    let body = JSON.stringify({ op, args });
    if (op === 'sql' && body.length > 3500000) {
      const id = await stash(args.sql);
      body = JSON.stringify({ op, args: { ...args, sql: undefined, stash: id } });
    }
    return post(body);
  }

  let expiredShown = false;
  function sessionExpired() {
    if (expiredShown) return;
    expiredShown = true;
    const m = modal({
      title: 'Session expired',
      body: h('p', { class: 'lead' }, 'You were signed out of Vito. Reload the page to sign in again — nothing has been lost on the server.'),
      foot: [h('span', { class: 'spacer' }), btn('Reload', () => (EMBED ? window.top : window).location.reload(), 'primary', 'refresh')],
      sticky: true,
      onClose: () => (expiredShown = false),
    });
    return m;
  }

  // ── toasts, modals, popovers ────────────────────────────────────────────────────────────
  const toastBox = h('div', { class: 'toasts', role: 'status', 'aria-live': 'polite' });
  function toast(message, kind = 'ok', ms) {
    const el = h('div', { class: 'toast ' + kind }, icon(kind === 'err' ? 'alert' : 'check'), h('div', { class: 't-body' }, message),
      h('button', { type: 'button', 'aria-label': 'Dismiss', onclick: () => el.remove() }, icon('x')));
    put(toastBox, el);
    setTimeout(() => el.remove(), ms ?? (kind === 'err' ? 9000 : 3500));
  }
  const fail = (e) => toast(e instanceof ApiError || e instanceof Error ? e.message : String(e), 'err');

  const layers = [];
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && layers.length) {
      const top = layers[layers.length - 1];
      if (!top.sticky) {
        e.preventDefault();
        top.close();
      }
    }
  });

  function modal({ title, body, foot, size = '', sticky = false, onClose, drawer = false }) {
    const box = h('div', { class: drawer ? 'drawer' : 'modal ' + size, role: 'dialog', 'aria-modal': 'true' });
    const overlay = h('div', { class: 'overlay' }, box);
    const layer = {
      el: box,
      sticky: false,
      close() {
        if (layer.closed) return;
        if (layer.guard && !layer.guard()) return;
        layer.closed = true;
        overlay.remove();
        layers.splice(layers.indexOf(layer), 1);
        onClose && onClose();
      },
    };
    const head = h('div', { class: drawer ? 'drawer-head' : 'modal-head' }, h('h2', null, title), iconBtn('x', 'Close', () => layer.close()));
    const bodyEl = h('div', { class: drawer ? 'drawer-body' : 'modal-body' }, body);
    const footEl = h('div', { class: drawer ? 'drawer-foot' : 'modal-foot' }, foot || []);
    put(box, head, bodyEl, footEl);
    if (!foot || (Array.isArray(foot) && !foot.length)) footEl.classList.add('hidden');
    overlay.addEventListener('mousedown', (e) => {
      if (e.target === overlay && !sticky) layer.close();
    });
    layer.sticky = sticky;
    layer.body = bodyEl;
    layer.foot = footEl;
    layer.head = head;
    layers.push(layer);
    put(document.body, overlay);
    requestAnimationFrame(() => {
      const f = box.querySelector('[autofocus], input:not([type=checkbox]):not([disabled]), textarea, select');
      f && f.focus();
    });
    return layer;
  }

  function confirmBox({ title, message, sql, confirm = 'Confirm', danger = false, typed = null, extra = null }) {
    return new Promise((resolve) => {
      let done = false;
      const typedInput = typed ? input({ placeholder: typed }) : null;
      const ok = btn(confirm, () => {
        done = true;
        m.close();
        resolve(true);
      }, danger ? 'danger' : 'primary');
      if (typedInput) {
        ok.disabled = true;
        typedInput.addEventListener('input', () => (ok.disabled = typedInput.value !== typed));
        typedInput.addEventListener('keydown', (e) => e.key === 'Enter' && !ok.disabled && ok.click());
      }
      const m = modal({
        title,
        body: [
          message ? h('p', { class: 'lead' }, message) : null,
          extra,
          sql ? h('div', { class: 'card' }, codeBlock(Array.isArray(sql) ? sql.join(';\n') + ';' : sql)) : null,
          typedInput ? field(h('span', null, 'Type ', h('b', { class: 'mono' }, typed), ' to confirm'), typedInput) : null,
        ],
        foot: [h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), ok],
        onClose: () => !done && resolve(false),
      });
      if (!typedInput) requestAnimationFrame(() => ok.focus());
    });
  }

  let openPop = null;
  function popover(anchor, content, { pad = false, align = 'start', width } = {}) {
    closePop();
    const el = h('div', { class: 'popover' + (pad ? ' pad' : '') }, content);
    if (width) el.style.width = width + 'px';
    put(document.body, el);
    const r = anchor.getBoundingClientRect();
    const w = el.offsetWidth, hgt = el.offsetHeight;
    let left = align === 'end' ? r.right - w : r.left;
    left = Math.max(8, Math.min(left, innerWidth - w - 8));
    let top = r.bottom + 4;
    if (top + hgt > innerHeight - 8 && r.top - hgt - 4 > 8) top = r.top - hgt - 4;
    el.style.left = left + 'px';
    el.style.top = Math.max(8, top) + 'px';
    const away = (e) => {
      if (!el.contains(e.target) && !anchor.contains(e.target)) closePop();
    };
    const esc = (e) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        closePop();
      }
    };
    setTimeout(() => document.addEventListener('mousedown', away), 0);
    document.addEventListener('keydown', esc, true);
    openPop = {
      el,
      close() {
        el.remove();
        document.removeEventListener('mousedown', away);
        document.removeEventListener('keydown', esc, true);
      },
    };
    requestAnimationFrame(() => {
      const f = el.querySelector('input:not([type=checkbox]), select, textarea');
      f && f.focus();
    });
    return openPop;
  }
  function closePop() {
    if (openPop) {
      openPop.close();
      openPop = null;
    }
  }
  function menu(anchor, items, opts) {
    const list = items.filter((x) => x && !x.hidden).map((x) =>
      x.sep ? h('hr') : h('button', {
        type: 'button',
        class: 'mi' + (x.danger ? ' danger' : ''),
        disabled: x.disabled,
        onclick: () => {
          closePop();
          x.onClick();
        },
      }, x.icon ? icon(x.icon) : null, x.label, x.hint ? h('span', { class: 'k' }, x.hint) : null));
    return popover(anchor, list, { align: 'end', ...opts });
  }

  function download(data, filename, mime) {
    const blob = data instanceof Blob ? data : new Blob([data], { type: mime || 'application/octet-stream' });
    const url = URL.createObjectURL(blob);
    const a = h('a', { href: url, download: filename });
    put(document.body, a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
  }
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      toast('Copied to the clipboard');
    } catch {
      toast('The browser did not allow copying.', 'err');
    }
  }
  const b64ToBytes = (b64) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
  const bytesToHex = (u8, max = Infinity) => Array.from(u8.subarray(0, Math.min(u8.length, max)), (b) => b.toString(16).padStart(2, '0')).join('');
  function fileToBase64(file) {
    return new Promise((resolve, reject) => {
      const r = new FileReader();
      r.onload = () => resolve(String(r.result).replace(/^data:[^,]*,/, ''));
      r.onerror = () => reject(new Error('The file could not be read.'));
      r.readAsDataURL(file);
    });
  }

  // ── values ──────────────────────────────────────────────────────────────────────────────
  const isTrue = (v) => v === '1' || v === 't' || v === 'true' || v === 'TRUE' || v === 'on';
  const boolOut = (on) => (PG ? (on ? 'true' : 'false') : on ? '1' : '0');
  const NUMERIC = new Set(['int', 'num', 'year', 'bit']);
  const isBin = (v) => v !== null && typeof v === 'object' && 'b' in v;
  const isCut = (v) => v !== null && typeof v === 'object' && 't' in v;

  /** One cell of the grid or of a query result, as DOM. */
  function cellView(v, c) {
    if (v === null || v === undefined) return h('span', { class: 'v-null' }, 'NULL');
    if (isBin(v)) {
      if (!v.n) return h('span', { class: 'v-bin' }, '0 bytes');
      const hex = bytesToHex(b64ToBytes(v.b), 12);
      return h('span', { class: 'v-bin' }, '0x' + hex.toUpperCase() + (v.n > 12 ? '…' : '') + ` · ${bytes(v.n)}`);
    }
    if (isCut(v)) {
      const t = v.t.replace(/\r?\n/g, ' ↵ ');
      return h('span', null, t, h('span', { class: 'v-more' }, v.u === 'bytes' ? `… ${bytes(v.n)}` : `… ${num(v.n)} chars`));
    }
    if (v === '') return h('span', { class: 'v-empty' }, 'empty');
    if (c && c.cat === 'bool') return h('span', { class: 'v-bool' + (isTrue(v) ? ' on' : '') }, isTrue(v) ? 'true' : 'false');
    return String(v).replace(/\r?\n/g, ' ↵ ');
  }
  function plainValue(v) {
    if (v === null || v === undefined) return null;
    if (isBin(v)) return '0x' + bytesToHex(b64ToBytes(v.b)).toUpperCase();
    if (isCut(v)) return v.t;
    return String(v);
  }
  function setToCsv(columns, rows, sep = ',') {
    const q = (s) => (s === null ? '' : /[",\r\n\t;]/.test(s) || s === '' || s !== s.trim() ? '"' + s.replace(/"/g, '""') + '"' : s);
    return [columns.map((c) => q(String(c))).join(sep), ...rows.map((r) => r.map((v) => q(plainValue(v))).join(sep))].join('\r\n');
  }

  // ── state ───────────────────────────────────────────────────────────────────────────────
  const S = {
    ov: null,
    ovError: null,
    schema: PG ? ls.get(STORE + ':schema', 'public') : null,
    sideFilter: '',
    route: { view: 'overview' },
    tables: new Map(), // per-table state, kept while switching tables
    monitorTimer: null,
  };
  function tstate(t) {
    const k = tkey(t);
    if (!S.tables.has(k)) {
      S.tables.set(k, {
        table: { schema: t.schema ?? null, name: t.name },
        page: 1,
        per: ls.get(STORE + ':per', 50),
        sort: [],
        filters: [],
        search: '',
        hidden: new Set(ls.get(STORE + ':hidden:' + k, [])),
        widths: ls.get(STORE + ':widths:' + k, {}),
        data: null,
        structure: null,
        selected: new Set(),
        seq: 0,
        loading: false,
        error: null,
      });
    }
    return S.tables.get(k);
  }
  function invalidateTables() {
    for (const st of S.tables.values()) {
      st.data = null;
      st.structure = null;
    }
  }

  // ── routing ─────────────────────────────────────────────────────────────────────────────
  function parseHash() {
    const parts = location.hash.replace(/^#\/?/, '').split('/').filter((p) => p !== '').map((p) => {
      try {
        return decodeURIComponent(p);
      } catch {
        return p;
      }
    });
    if (parts[0] === 'table' && parts.length >= 3) {
      return { view: 'table', table: { schema: parts[1] === '-' ? null : parts[1], name: parts[2] }, tab: parts[3] || 'rows' };
    }
    if (parts[0] === 'sql') return { view: 'sql' };
    if (parts[0] === 'monitor') return { view: 'monitor' };
    return { view: 'overview' };
  }
  function hashOf(r) {
    if (r.view === 'table') {
      return '#/table/' + encodeURIComponent(r.table.schema ?? '-') + '/' + encodeURIComponent(r.table.name) + (r.tab && r.tab !== 'rows' ? '/' + r.tab : '');
    }
    return r.view === 'overview' ? '#/' : '#/' + r.view;
  }
  const go = (r) => {
    const target = hashOf(r);
    if (location.hash === target) render();
    else location.hash = target;
  };
  const openTable = (t, tab = 'rows') => go({ view: 'table', table: { schema: t.schema ?? null, name: t.name }, tab });

  // ── shell ───────────────────────────────────────────────────────────────────────────────
  const root = document.getElementById('vdbm');
  const app = h('div', { class: 'app' });
  const sideEl = h('aside', { class: 'side', 'aria-label': 'Tables' });
  const mainEl = h('main', { class: 'main' });
  // On narrow screens the table list slides over the page; a tap on the page closes it.
  mainEl.addEventListener('pointerdown', () => app.classList.remove('side-open'));
  root.replaceWith(app);
  put(document.body, toastBox);

  function topBar() {
    const dbSelect = select(BOOT.databases.map((d) => [String(d.id), d.name + (d.ready ? '' : ' (not ready)')]), BOOT.database ? String(BOOT.database.id) : '', {
      class: 'select sm',
      style: 'width:auto;max-width:240px',
      'aria-label': 'Database',
      onchange: (e) => {
        const d = BOOT.databases.find((x) => String(x.id) === e.target.value);
        if (d) location.href = d.url;
      },
    });
    return h('header', { class: 'top' },
      iconBtn('menu', 'Tables', () => app.classList.toggle('side-open'), 'ghost side-toggle'),
      h('a', {
        class: 'btn ghost sm',
        href: BOOT.back,
        target: '_top',
        title: 'Back to the Databases page',
        onclick: (e) => {
          if (!EMBED) return;
          e.preventDefault();
          tell({ type: 'close' });
        },
      }, icon('back'), h('span', { class: 'hide-sm' }, 'Databases')),
      // inside Vito its header already names the server
      h('div', { class: 'crumbs' },
        EMBED ? null : h('span', { class: 'brand hide-sm' }, icon('database'), BOOT.server.name),
        EMBED ? null : h('span', { class: 'sep hide-sm' }, '/'),
        dbSelect),
      h('span', { class: 'spacer' }),
      h('span', { class: 'engine-badge hide-sm', title: S.ov?.version_comment || '' }, ENGINE + ' ' + (S.ov?.version ? String(S.ov.version).replace(/-.*$/, '') : BOOT.engine.version)),
      h('a', { class: 'btn sm sponsor', href: SPONSOR, target: '_blank', rel: 'noopener', title: 'Sponsor the Database Manager on GitHub' },
        icon('heart'), h('span', { class: 'hide-sm' }, 'Sponsor')),
    );
  }

  function sideBar() {
    const r = S.route;
    const head = h('div', { class: 'side-head' });
    const list = h('div', { class: 'side-list' });
    const nav = h('div', { class: 'nav' },
      navItem('layers', 'Overview', r.view === 'overview', () => go({ view: 'overview' })),
      navItem('terminal', 'SQL console', r.view === 'sql', () => go({ view: 'sql' })),
      navItem('activity', 'Processes', r.view === 'monitor', () => go({ view: 'monitor' })),
    );
    put(head, btn('New table', () => {
      app.classList.remove('side-open');
      createTableDialog();
    }, 'primary sm', 'plus'));
    const search = input({ class: 'input sm', placeholder: 'Search tables', value: S.sideFilter, 'aria-label': 'Search tables' });
    search.addEventListener('input', () => {
      S.sideFilter = search.value;
      fillList();
    });
    put(head, h('div', { class: 'input-icon' }, icon('search'), search));
    if (PG && S.ov && S.ov.schemas) {
      const schemas = [['*', 'All schemas'], ...S.ov.schemas.map((s) => [s, s])];
      put(head, select(schemas, S.schema, {
        class: 'select sm',
        'aria-label': 'Schema',
        onchange: (e) => {
          S.schema = e.target.value;
          ls.set(STORE + ':schema', S.schema);
          fillList();
        },
      }));
    }

    function fillList() {
      fill(list);
      if (S.ovError) {
        put(list, h('div', { class: 'empty small' }, icon('alert'), S.ovError));
        return;
      }
      if (!S.ov) {
        put(list, h('div', { class: 'empty' }, spinner()));
        return;
      }
      const term = S.sideFilter.trim().toLowerCase();
      const visible = S.ov.tables.filter((t) => (!PG || S.schema === '*' || t.schema === S.schema) && (!term || t.name.toLowerCase().includes(term)));
      const groups = [
        ['Tables', visible.filter((t) => ['table', 'partitioned', 'foreign'].includes(t.type))],
        ['Views', visible.filter((t) => ['view', 'matview', 'system view'].includes(t.type))],
        ['Other', visible.filter((t) => !['table', 'partitioned', 'foreign', 'view', 'matview', 'system view'].includes(t.type))],
      ];
      let any = false;
      for (const [label, items] of groups) {
        if (!items.length) continue;
        any = true;
        put(list, h('div', { class: 'group' }, label, h('span', { class: 'muted' }, `· ${items.length}`)));
        for (const t of items) {
          const active = r.view === 'table' && sameTable(r.table, t);
          put(list, h('button', {
            type: 'button',
            class: 'item' + (active ? ' active' : ''),
            title: (PG ? t.schema + '.' : '') + t.name + (t.comment ? ' — ' + t.comment : ''),
            style: t.partition_of ? 'padding-left:24px' : null,
            onclick: () => {
              app.classList.remove('side-open');
              openTable(t);
            },
          }, icon(t.type.includes('view') ? 'view' : 'table'), h('span', { class: 'name' }, PG && S.schema === '*' ? tlabel(t) : t.name),
          t.rows !== null && t.rows !== undefined && t.rows >= 0 ? h('span', { class: 'count', title: 'Estimated rows' }, compact(t.rows)) : null));
        }
      }
      if (!any) put(list, h('div', { class: 'empty small' }, term ? 'No table matches.' : 'No tables yet.'));
    }
    fillList();
    return [head, list, nav];
  }
  const navItem = (ic, label, active, onclick) => h('button', { type: 'button', class: 'item' + (active ? ' active' : ''), onclick }, icon(ic), h('span', { class: 'name' }, label));

  function renderShell() {
    fill(app, topBar(), sideEl, mainEl);
    fill(sideEl, ...sideBar());
  }

  async function loadOverview() {
    try {
      S.ov = await api('overview');
      S.ovError = null;
      if (PG && S.schema !== '*' && !S.ov.schemas.includes(S.schema)) S.schema = S.ov.schemas.includes('public') ? 'public' : '*';
    } catch (e) {
      S.ovError = e.message;
    }
  }
  async function refreshOverview() {
    await loadOverview();
    renderShell();
    if (S.route.view === 'overview') render();
  }

  function stopMonitor() {
    if (S.monitorTimer) {
      clearInterval(S.monitorTimer);
      S.monitorTimer = null;
    }
  }

  function render() {
    closePop();
    app.classList.remove('side-open');
    S.route = parseHash();
    stopMonitor();
    renderShell();
    mainEl.scrollTop = 0;
    const r = S.route;
    if (!BOOT.database) {
      fill(mainEl, h('div', { class: 'page' }, h('div', { class: 'empty' }, icon('database'), 'This server has no databases in Vito yet. Create one on the Databases page.')));
      return;
    }
    if (r.view === 'table') renderTable(r.table, r.tab);
    else if (r.view === 'sql') renderSql();
    else if (r.view === 'monitor') renderMonitor();
    else renderOverview();
    const label = r.view === 'table' ? tlabel(r.table) : r.view === 'sql' ? 'SQL' : r.view === 'monitor' ? 'Processes' : null;
    document.title = [label, BOOT.database?.name, BOOT.server.name].filter(Boolean).join(' · ');
    tell({ type: 'state', db: BOOT.database ? BOOT.database.id : null, hash: location.hash, title: document.title });
  }
  window.addEventListener('hashchange', render);

  // ── overview ────────────────────────────────────────────────────────────────────────────
  function renderOverview() {
    const page = h('div', { class: 'page' });
    fill(mainEl, page);
    if (S.ovError) {
      put(page, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, S.ovError)), h('div', null, btn('Try again', () => refreshOverview(), '', 'refresh')));
      return;
    }
    if (!S.ov) {
      put(page, h('div', { class: 'empty' }, spinner(), 'Loading the database…'));
      return;
    }
    const ov = S.ov;
    const tables = ov.tables.filter((t) => !t.type.includes('view'));
    const views = ov.tables.filter((t) => t.type.includes('view'));
    const rowsTotal = tables.reduce((s, t) => s + (t.rows > 0 ? t.rows : 0), 0);
    put(page, 
      h('div', { class: 'page-head' },
        h('div', { class: 'grow' },
          h('h1', null, ov.database.name),
          h('div', { class: 'sub' },
            h('span', null, `${ENGINE} ${ov.version || ''}`),
            ov.database.charset ? h('span', null, `${ov.database.charset}${ov.database.collation ? ' · ' + ov.database.collation : ''}`) : null,
            ov.database.timezone ? h('span', null, `Time zone ${ov.database.timezone}`) : null)),
        h('div', { class: 'row' },
          btn('Refresh', (e) => busy(e.currentTarget, refreshOverview), '', 'refresh'),
          btn('SQL console', () => go({ view: 'sql' }), '', 'terminal'),
          btn('New table', () => createTableDialog(), 'primary', 'plus'))),
      h('div', { class: 'stats' },
        stat('Size', bytes(ov.database.size), 'data + indexes'),
        stat('Tables', num(tables.length), PG ? `${ov.schemas.length} schemas` : null),
        stat('Views', num(views.length)),
        stat('Rows', '≈ ' + compact(rowsTotal), 'estimated'),
        stat(PG ? 'Functions' : 'Routines', num(ov.routines.length)),
        stat('Triggers', num(ov.triggers.length))),
    );

    if (!ov.tables.length) {
      put(page, h('div', { class: 'card' }, h('div', { class: 'empty' }, icon('table'), h('div', null, 'This database has no tables yet.'),
        btn('Create a table', () => createTableDialog(), 'primary', 'plus'))));
    } else {
      const maxSize = Math.max(1, ...ov.tables.map((t) => t.size || 0));
      put(page, h('div', { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Tables and views')),
        h('div', { style: 'overflow:auto' }, h('table', { class: 'list' },
          h('thead', null, h('tr', null, h('th', null, 'Name'), h('th', null, 'Type'), h('th', { class: 'num' }, 'Rows'), h('th', { class: 'num' }, 'Size'),
            h('th', { style: 'width:140px' }), PG ? null : h('th', null, 'Engine'), h('th', null, 'Comment'))),
          h('tbody', null, ov.tables.map((t) => h('tr', { class: 'click', onclick: () => openTable(t) },
            h('td', null, h('span', { class: 'row' }, icon(t.type.includes('view') ? 'view' : 'table'), h('span', null, tlabel(t)))),
            h('td', null, h('span', { class: 'badge' }, t.type)),
            h('td', { class: 'num' }, t.rows !== null && t.rows >= 0 ? '≈ ' + num(t.rows) : '—'),
            h('td', { class: 'num' }, bytes(t.size)),
            h('td', null, t.size ? h('div', { class: 'bar' }, h('i', { style: `width:${Math.max(2, Math.round((t.size / maxSize) * 100))}%` })) : null),
            PG ? null : h('td', { class: 'muted' }, t.engine || ''),
            h('td', { class: 'muted', style: 'max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, t.comment || '')))))),
      ));
    }

    const extras = [];
    if (ov.routines.length) {
      extras.push(listCard(PG ? 'Functions and procedures' : 'Routines', ['Name', 'Type', PG ? 'Arguments' : null], ov.routines.map((x) => [tlabelRoutine(x), x.type, PG ? x.args || '' : null])));
    }
    if (ov.triggers.length) extras.push(listCard('Triggers', ['Name', 'Table', 'When'], ov.triggers.map((x) => [x.name, x.table, [x.timing, x.event].filter(Boolean).join(' ')])));
    if (ov.events && ov.events.length) extras.push(listCard('Scheduled events', ['Name', 'Status', 'Every'], ov.events.map((x) => [x.name, x.status, x.every || x.type])));
    if (ov.extensions && ov.extensions.length) extras.push(listCard('Extensions', ['Name', 'Version'], ov.extensions.map((x) => [x.name, x.version])));
    if (extras.length) put(page, h('div', { class: 'grid2' }, extras));
  }
  const tlabelRoutine = (x) => (PG && x.schema && x.schema !== 'public' ? x.schema + '.' + x.name : x.name);
  const stat = (k, v, s) => h('div', { class: 'stat' }, h('div', { class: 'k' }, k), h('div', { class: 'v', title: String(v) }, v), s ? h('div', { class: 's' }, s) : null);
  function listCard(title, heads, rows) {
    const cols = heads.map((x, i) => (x === null ? null : i)).filter((x) => x !== null);
    return h('div', { class: 'card', style: 'min-width:0' },
      h('div', { class: 'card-head' }, h('h3', null, title), h('span', { class: 'badge' }, String(rows.length))),
      h('div', { style: 'overflow:auto;max-height:320px' }, h('table', { class: 'list' },
        h('thead', null, h('tr', null, cols.map((i) => h('th', null, heads[i])))),
        h('tbody', null, rows.map((r) => h('tr', null, cols.map((i) => h('td', { class: i === 0 ? 'mono' : 'muted' }, r[i] ?? ''))))))));
  }

  // ── table page ──────────────────────────────────────────────────────────────────────────
  function tableInfo(t) {
    return S.ov ? S.ov.tables.find((x) => sameTable(x, t)) : null;
  }

  function renderTable(t, tab) {
    const st = tstate(t);
    const info = tableInfo(t);
    if (S.ov && !info && !st.data) {
      fill(mainEl, h('div', { class: 'page' }, h('div', { class: 'empty' }, icon('table'), `The table ${tlabel(t)} does not exist (any more).`,
        btn('Back to the overview', () => go({ view: 'overview' })))));
      return;
    }
    const type = info?.type || st.data?.type || 'table';
    const isView = type.includes('view');
    const tabs = [['rows', 'Rows', 'list'], ['columns', 'Columns', 'columns'], ['indexes', 'Indexes', 'key'], ['relations', 'Relations', 'link'], ['settings', 'Settings', 'sliders']];
    const page = h('div', { class: 'page' });
    const body = h('div', { class: 'stack' });
    const headSub = h('div', { class: 'sub' },
      h('span', { class: 'badge' }, type),
      info && info.rows !== null && info.rows >= 0 ? h('span', null, '≈ ' + plural(info.rows, 'row')) : null,
      info && info.size ? h('span', null, bytes(info.size)) : null,
      info && info.engine ? h('span', null, info.engine) : null,
      info && info.comment ? h('span', null, info.comment) : null);
    put(page, 
      h('div', { class: 'page-head' },
        h('div', { class: 'grow' }, h('h1', null, tlabel(t)), headSub),
        h('div', { class: 'row' },
          btn('Query', () => {
            sqlDraft(`SELECT *\nFROM ${tableSql(t)}\nLIMIT 100;`);
            go({ view: 'sql' });
          }, '', 'terminal'),
          isView ? null : btn('Add row', () => rowEditor(t, null), 'primary', 'plus'))),
      h('div', { class: 'tabs', role: 'tablist' }, tabs.map(([id, label, ic]) => h('button', {
        type: 'button',
        role: 'tab',
        class: tab === id ? 'active' : '',
        'aria-selected': tab === id ? 'true' : 'false',
        onclick: () => openTable(t, id),
      }, icon(ic), label))),
      body,
    );
    fill(mainEl, page);
    page.classList.toggle('fill', tab === 'rows');
    if (tab === 'rows') renderRows(st, body);
    else renderStructureTab(st, tab, body);
  }

  // ── rows grid ───────────────────────────────────────────────────────────────────────────
  const OPS = [
    ['=', 'equals'], ['!=', 'does not equal'], ['contains', 'contains'], ['not_contains', 'does not contain'], ['starts', 'starts with'], ['ends', 'ends with'],
    ['<', 'is less than'], ['<=', 'is at most'], ['>', 'is greater than'], ['>=', 'is at least'], ['between', 'is between'], ['in', 'is one of'],
    ['not_in', 'is none of'], ['null', 'is NULL'], ['not_null', 'is not NULL'], ['empty', 'is empty'], ['not_empty', 'is not empty'], ['regexp', 'matches regex'],
  ];
  const OP_LABEL = Object.fromEntries(OPS);
  function opsFor(c) {
    const base = ['=', '!=', 'null', 'not_null'];
    if (c.cat === 'bin') return base;
    if (c.cat === 'bool') return base;
    if (NUMERIC.has(c.cat) || ['date', 'datetime', 'time'].includes(c.cat)) return ['=', '!=', '<', '<=', '>', '>=', 'between', 'in', 'not_in', 'null', 'not_null'];
    return ['contains', 'not_contains', '=', '!=', 'starts', 'ends', 'in', 'not_in', 'empty', 'not_empty', 'regexp', 'null', 'not_null', '<', '>'];
  }
  const noValue = (op) => ['null', 'not_null', 'empty', 'not_empty'].includes(op);

  async function loadRows(st) {
    const seq = ++st.seq;
    st.loading = true;
    st.error = null;
    st.onchange && st.onchange();
    try {
      const data = await api('rows', { table: st.table, page: st.page, per: st.per, sort: st.sort, filters: st.filters, search: st.search });
      if (seq !== st.seq) return;
      st.data = data;
      st.selected.clear();
      const pages = data.total !== null ? Math.max(1, Math.ceil(data.total / st.per)) : null;
      if (pages !== null && st.page > pages && !data.approx) {
        st.page = pages;
        return loadRows(st);
      }
    } catch (e) {
      if (seq !== st.seq) return;
      st.error = e;
    } finally {
      if (seq === st.seq) {
        st.loading = false;
        st.onchange && st.onchange();
      }
    }
  }

  function renderRows(st, body) {
    const t = st.table;
    const search = input({ class: 'input sm', placeholder: 'Search all columns', value: st.search, 'aria-label': 'Search rows' });
    const runSearch = () => {
      if (search.value === st.search) return;
      st.search = search.value;
      st.page = 1;
      loadRows(st);
    };
    search.addEventListener('input', debounce(runSearch, 450));
    search.addEventListener('keydown', (e) => e.key === 'Enter' && runSearch());
    const filterBtn = btn('Filter', (e) => filterPopover(e.currentTarget, st), 'sm', 'filter');
    const colsBtn = btn('Columns', (e) => columnsPopover(e.currentTarget, st), 'sm', 'columns');
    const refreshBtn = iconBtn('refresh', 'Reload', () => loadRows(st), 'sm');
    const delBtn = btn('', () => deleteSelected(st), 'danger sm hidden', 'trash');
    const importBtn = btn('Import', () => importDialog(st), 'sm', 'upload');
    const exportBtn = btn('Export', () => exportDialog(st), 'sm', 'download');
    const toolbar = h('div', { class: 'toolbar' },
      h('div', { class: 'input-icon' }, icon('search'), search), filterBtn, colsBtn, refreshBtn, delBtn, h('span', { class: 'grow' }), importBtn, exportBtn);
    const chips = h('div', { class: 'chips' });
    const notice = h('div');
    const wrap = h('div', { class: 'grid-wrap' });
    const foot = h('div', { class: 'grid-foot' });
    const styleEl = h('style');
    put(body, toolbar, chips, notice, wrap, foot, styleEl);

    function draw() {
      const d = st.data;
      const editable = d ? ['table', 'partitioned'].includes(d.type) && d.key.mode !== 'none' : false;
      importBtn.classList.toggle('hidden', !!d && !['table', 'partitioned'].includes(d.type));
      // chips
      fill(chips, ...st.filters.map((f, i) => h('span', { class: 'chip' },
        h('span', null, h('b', null, f.col), ' ', OP_LABEL[f.op] || f.op, noValue(f.op) ? '' : ' ', noValue(f.op) ? '' : h('span', { class: 'mono' }, f.op === 'between' ? `${f.value} and ${f.value2}` : f.value)),
        h('button', { type: 'button', title: 'Remove filter', onclick: () => {
          st.filters.splice(i, 1);
          st.page = 1;
          loadRows(st);
        } }, icon('x')))));
      if (st.filters.length > 1) put(chips, h('button', { type: 'button', class: 'btn ghost sm', onclick: () => {
        st.filters = [];
        st.page = 1;
        loadRows(st);
      } }, 'Clear filters'));
      filterBtn.querySelector('span').textContent = st.filters.length ? `Filter · ${st.filters.length}` : 'Filter';
      const hiddenCount = d ? d.columns.filter((c) => st.hidden.has(c.name)).length : 0;
      colsBtn.querySelector('span').textContent = hiddenCount ? `Columns · ${hiddenCount} hidden` : 'Columns';
      delBtn.classList.toggle('hidden', !st.selected.size);
      fill(delBtn, icon('trash'), h('span', null, `Delete ${st.selected.size}`));

      fill(notice);
      if (d && d.key.mode === 'hash') put(notice, h('div', { class: 'alert warn' }, icon('alert'), h('div', { class: 'a-body' },
        'This table has no primary or unique key. Rows are matched by their full content, so a change applies to one of any identical rows. Add a primary key for reliable editing.')));
      if (d && d.key.mode === 'ctid') put(notice, h('div', { class: 'alert warn' }, icon('alert'), h('div', { class: 'a-body' },
        'This table has no primary or unique key. Rows are matched by their physical location (ctid), which changes when a row is updated — reload before editing again. Add a primary key for reliable editing.')));
      if (d && !['table', 'partitioned'].includes(d.type)) put(notice, h('div', { class: 'alert info' }, icon('info'), h('div', { class: 'a-body' }, `This is a ${d.type}: its rows are read-only here.`)));
      if (d && d.count_error) put(notice, h('div', { class: 'alert warn' }, icon('alert'), h('div', { class: 'a-body' }, 'Counting the rows failed: ' + d.count_error)));

      fill(wrap);
      if (st.loading) put(wrap, h('div', { class: 'loading-bar' }));
      if (st.error) {
        put(wrap, h('div', { class: 'card-body' }, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, st.error.message))));
        fill(foot);
        return;
      }
      if (!d) {
        put(wrap, h('div', { class: 'empty' }, spinner()));
        return;
      }
      const cols = d.columns.map((c, i) => ({ c, i })).filter((x) => !st.hidden.has(x.c.name));
      const keyCols = new Set(['pk', 'unique'].includes(d.key.mode) ? d.key.cols : []);
      const allBox = h('input', { type: 'checkbox', 'aria-label': 'Select all rows on this page', checked: d.rows.length > 0 && st.selected.size === d.rows.length,
        indeterminate: st.selected.size > 0 && st.selected.size < d.rows.length, disabled: !editable,
        onchange: (e) => {
          st.selected = e.target.checked ? new Set(d.rows.map((_, i) => i)) : new Set();
          draw();
        } });
      const sortOf = (name) => st.sort.findIndex((s) => s[0] === name);
      const thead = h('thead', null, h('tr', null, h('th', { class: 'c-check' }, allBox), h('th', { class: 'c-open' }), cols.map(({ c }) => {
        const si = sortOf(c.name);
        const th = h('th', { title: `${c.name} · ${c.type}${c.comment ? ' — ' + c.comment : ''}` },
          h('div', { class: 'th', onclick: (e) => toggleSort(st, c.name, e.shiftKey) },
            keyCols.has(c.name) ? icon('key') : null,
            h('span', { class: 't' }, c.name),
            h('span', { class: 'type' }, shortType(c)),
            si >= 0 ? h('span', { class: 'sort' }, icon(st.sort[si][1] === 'desc' ? 'dn' : 'up'), st.sort.length > 1 ? String(si + 1) : '') : null),
          h('span', { class: 'rs', onmousedown: (e) => startResize(e, st, c.name, th, draw), onclick: (e) => e.stopPropagation() }));
        return th;
      })));
      const tbody = h('tbody');
      d.rows.forEach((row, ri) => {
        const tr = h('tr', { class: st.selected.has(ri) ? 'sel' : '' });
        const box = h('input', { type: 'checkbox', 'aria-label': 'Select row', checked: st.selected.has(ri), disabled: !editable,
          onchange: (e) => {
            e.target.checked ? st.selected.add(ri) : st.selected.delete(ri);
            draw();
          } });
        put(tr, h('td', { class: 'c-check' }, box),
          h('td', { class: 'c-open' }, h('button', { type: 'button', class: 'open-row', title: editable ? 'Open and edit the row' : 'Open the row', 'aria-label': 'Open the row', onclick: () => rowEditor(t, ri) }, icon('open'))));
        for (const { c, i } of cols) {
          const v = row[i];
          const td = h('td', { class: (NUMERIC.has(c.cat) && typeof v === 'string' ? 'num' : '') + (['json', 'bin', 'geo', 'vector', 'array'].includes(c.cat) ? ' mono' : '') }, cellView(v, c));
          if (typeof v === 'string' && v.length > 40) td.title = v.length > 1000 ? v.slice(0, 1000) + '…' : v;
          // double-click edits a short value in place; anything else opens the row
          td.addEventListener('dblclick', (e) => {
            if (td.classList.contains('editing')) return;
            e.preventDefault();
            window.getSelection().removeAllRanges();
            if (editable && inlineEditable(c, v)) inlineEdit(st, td, ri, c, v);
            else rowEditor(t, ri);
          });
          put(tr, td);
        }
        put(tbody, tr);
      });
      const table = h('table', { class: 'grid' }, thead, tbody);
      put(wrap, table);
      if (!d.rows.length) {
        put(wrap, h('div', { class: 'empty' }, icon(st.filters.length || st.search ? 'search' : 'table'),
          st.filters.length || st.search ? 'No rows match the search and filters.' : 'This table is empty.',
          !st.filters.length && !st.search && editable ? btn('Add the first row', () => rowEditor(t, null), 'primary sm', 'plus') : null));
      }
      // widths of resized columns
      styleEl.textContent = cols.map(({ c }, k) => (st.widths[c.name] ? `table.grid tr > :nth-child(${k + 3}){min-width:${st.widths[c.name]}px;max-width:${st.widths[c.name]}px;width:${st.widths[c.name]}px}` : '')).join('\n');

      // footer
      const from = d.rows.length ? (d.page - 1) * d.per + 1 : 0;
      const to = (d.page - 1) * d.per + d.rows.length;
      const pages = d.total !== null ? Math.max(1, Math.ceil(d.total / d.per)) : null;
      const pageInput = input({ class: 'input sm', value: String(d.page), 'aria-label': 'Page' });
      pageInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          const p = Math.max(1, Math.min(pages ?? Infinity, parseInt(pageInput.value, 10) || 1));
          st.page = p;
          loadRows(st);
        }
      });
      const perSel = select([25, 50, 100, 250, 500].map((n) => [String(n), `${n} per page`]), String(st.per), {
        class: 'select sm',
        style: 'width:auto',
        onchange: (e) => {
          st.per = parseInt(e.target.value, 10);
          ls.set(STORE + ':per', st.per);
          st.page = 1;
          loadRows(st);
        },
      });
      fill(foot, 
        h('span', null, d.rows.length ? `${num(from)}–${num(to)} of ${d.approx ? '≈ ' : ''}${d.total === null ? '?' : num(d.total)}` : `${d.approx ? '≈ ' : ''}${num(d.total ?? 0)} rows`),
        st.selected.size ? h('span', null, `· ${st.selected.size} selected`) : null,
        h('div', { class: 'pager' }, perSel,
          iconBtn('left', 'Previous page', () => {
            st.page = Math.max(1, d.page - 1);
            loadRows(st);
          }, 'sm' + (d.page <= 1 ? '' : '')),
          pageInput, pages !== null ? h('span', null, `of ${num(pages)}`) : null,
          iconBtn('right', 'Next page', () => {
            st.page = d.page + 1;
            loadRows(st);
          }, 'sm')));
      const [prev, next] = foot.querySelectorAll('.pager .btn');
      prev.disabled = d.page <= 1;
      next.disabled = pages !== null ? d.page >= pages : d.rows.length < d.per;
    }
    st.onchange = draw;
    draw();
    if (!st.data || !sameTable(st.data.table, t)) loadRows(st);
  }

  /** MariaDB keeps JSON as LONGTEXT with a json_valid() check: call it json, as it was declared. */
  const typeName = (c) => (MARIA && c.cat === 'json' && /^longtext\b/i.test(c.type) ? 'json' : String(c.type));
  function shortType(c) {
    const t = c.cat === 'enum' || c.cat === 'set' ? c.cat : typeName(c);
    return t.length > 22 ? t.slice(0, 21) + '…' : t;
  }
  function toggleSort(st, name, multi) {
    const i = st.sort.findIndex((s) => s[0] === name);
    const cur = i >= 0 ? st.sort[i][1] : null;
    const next = cur === null ? 'asc' : cur === 'asc' ? 'desc' : null;
    if (!multi) st.sort = next ? [[name, next]] : [];
    else if (i >= 0) next ? (st.sort[i] = [name, next]) : st.sort.splice(i, 1);
    else st.sort.push([name, 'asc']);
    st.page = 1;
    loadRows(st);
  }
  function startResize(e, st, name, th, redraw) {
    e.preventDefault();
    e.stopPropagation();
    const x0 = e.clientX;
    const w0 = th.getBoundingClientRect().width;
    const move = (ev) => {
      st.widths[name] = Math.max(60, Math.round(w0 + ev.clientX - x0));
      th.style.minWidth = th.style.maxWidth = th.style.width = st.widths[name] + 'px';
    };
    const up = () => {
      document.removeEventListener('mousemove', move);
      document.removeEventListener('mouseup', up);
      ls.set(STORE + ':widths:' + tkey(st.table), st.widths);
      redraw();
    };
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', up);
  }

  function filterPopover(anchor, st) {
    const d = st.data;
    if (!d) return;
    const cols = d.columns;
    const colSel = select(cols.map((c) => c.name), cols[0]?.name, { class: 'select sm' });
    const opSel = h('select', { class: 'select sm' });
    const v1 = input({ class: 'input sm', placeholder: 'Value' });
    const v2 = input({ class: 'input sm', placeholder: 'and' });
    const hint = h('div', { class: 'small muted' });
    const sync = () => {
      const c = cols.find((x) => x.name === colSel.value);
      const ops = opsFor(c);
      const keep = ops.includes(opSel.value) ? opSel.value : ops[0];
      fill(opSel, ...ops.map((o) => h('option', { value: o }, OP_LABEL[o])));
      opSel.value = keep;
      const op = opSel.value;
      v1.classList.toggle('hidden', noValue(op));
      v2.classList.toggle('hidden', op !== 'between');
      v1.placeholder = c.cat === 'bin' ? 'Hex, e.g. 00ff10' : c.cat === 'bool' ? (PG ? 'true or false' : '1 or 0') : ['in', 'not_in'].includes(op) ? 'Values, separated by commas' : 'Value';
      hint.textContent = op === 'regexp' ? (PG ? 'POSIX regular expression (~).' : 'Regular expression (REGEXP).') : '';
    };
    colSel.addEventListener('change', sync);
    opSel.addEventListener('change', sync);
    const apply = () => {
      const f = { col: colSel.value, op: opSel.value };
      if (!noValue(f.op)) f.value = v1.value;
      if (f.op === 'between') f.value2 = v2.value;
      st.filters.push(f);
      st.page = 1;
      closePop();
      loadRows(st);
    };
    [v1, v2].forEach((el) => el.addEventListener('keydown', (e) => e.key === 'Enter' && apply()));
    sync();
    popover(anchor, h('div', { class: 'stack', style: 'gap:8px' },
      h('div', { class: 'small muted' }, 'Show rows where'), colSel, opSel, v1, v2, hint,
      h('div', { class: 'row', style: 'justify-content:flex-end' }, btn('Add filter', apply, 'primary sm'))), { pad: true, width: 300 });
  }

  function columnsPopover(anchor, st) {
    const d = st.data;
    if (!d) return;
    const save = () => {
      ls.set(STORE + ':hidden:' + tkey(st.table), [...st.hidden]);
      st.onchange && st.onchange();
    };
    const boxes = d.columns.map((c) => check(c.name, !st.hidden.has(c.name), (on) => {
      on ? st.hidden.delete(c.name) : st.hidden.add(c.name);
      save();
    }));
    popover(anchor, h('div', { class: 'stack', style: 'gap:6px' },
      h('div', { class: 'row' }, h('span', { class: 'small muted grow' }, 'Shown columns'),
        h('button', { type: 'button', class: 'btn ghost sm', onclick: () => {
          st.hidden.clear();
          save();
          closePop();
        } }, 'Show all')),
      boxes), { pad: true, width: 260 });
  }

  async function deleteSelected(st) {
    const d = st.data;
    const keys = [...st.selected].map((i) => d.keys[i]).filter(Boolean);
    if (!keys.length) return;
    const ok = await confirmBox({
      title: `Delete ${plural(keys.length, 'row')}?`,
      message: `The selected ${keys.length === 1 ? 'row is' : 'rows are'} deleted from ${tlabel(st.table)} at once. This cannot be undone.`,
      confirm: 'Delete',
      danger: true,
    });
    if (!ok) return;
    try {
      const r = await api('delete', { table: st.table, keys });
      toast(r.deleted === r.requested ? `${plural(r.deleted, 'row')} deleted` : `${r.deleted} of ${r.requested} rows deleted — the others had already changed.`, r.deleted === r.requested ? 'ok' : 'err');
      await loadRows(st);
      bumpCount(st.table, -r.deleted);
    } catch (e) {
      fail(e);
    }
  }
  function bumpCount(t, delta) {
    const info = tableInfo(t);
    if (info && info.rows !== null && info.rows >= 0) info.rows = Math.max(0, info.rows + delta);
    fill(sideEl, ...sideBar());
  }

  // ── inline editing ──────────────────────────────────────────────────────────────────────
  function inlineEditable(c, v) {
    if (c.generated || isBin(v) || isCut(v) || ['bin', 'json', 'geo', 'set'].includes(c.cat)) return false;
    return typeof v === 'string' ? !/[\r\n]/.test(v) : true;
  }
  function inlineEdit(st, td, ri, c, v) {
    const d = st.data;
    let ctl;
    if (c.cat === 'bool') ctl = select([['', '—'], [boolOut(true), 'true'], [boolOut(false), 'false']], v === null ? '' : boolOut(isTrue(v)));
    else if (c.cat === 'enum' && c.options) ctl = select(['', ...c.options], v ?? '');
    else ctl = h('input', { type: 'text', value: v ?? '', spellcheck: false, dir: 'auto' });
    td.classList.add('editing');
    fill(td, ctl);
    ctl.focus();
    if (ctl.select) ctl.select();
    let done = false;
    const finish = async (save) => {
      if (done) return;
      done = true;
      const nv = ctl.value;
      if (!save || nv === (v ?? '') || (v === null && nv === '')) {
        td.classList.remove('editing');
        fill(td, cellView(v, c));
        return;
      }
      fill(td, spinner());
      try {
        await api('update', { table: st.table, key: d.keys[ri], values: { [c.name]: nv } });
        toast(`${c.name} updated`);
        await loadRows(st);
      } catch (e) {
        fail(e);
        td.classList.remove('editing');
        fill(td, cellView(v, c));
      }
    };
    ctl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        finish(true);
      } else if (e.key === 'Escape') {
        e.stopPropagation();
        finish(false);
      }
    });
    ctl.addEventListener('blur', () => finish(true));
    ctl.addEventListener('click', (e) => e.stopPropagation());
  }

  // ── row editor ──────────────────────────────────────────────────────────────────────────
  /**
   * ri: index of a row on the current page, or null for a new row. preset: values for a new row (duplicate).
   */
  async function rowEditor(t, ri, preset = null) {
    const st = tstate(t);
    let d = st.data;
    if (!d) {
      await loadRows(st);
      d = st.data;
      if (!d) return;
    }
    const editable = ['table', 'partitioned'].includes(d.type);
    const creating = ri === null;
    const columns = d.columns;
    const key = creating ? null : d.keys[ri];
    const canEdit = editable && (creating || (key !== null && d.key.mode !== 'none'));
    const fields = [];
    const body = h('div', { class: 'stack', style: 'gap:14px' });
    const saveBtn = btn(creating ? 'Create row' : 'Save changes', () => save(), 'primary', null, { disabled: !creating });
    const footer = [];
    if (!creating && canEdit) {
      footer.push(btn('', () => del(), 'ghost', 'trash', { title: 'Delete this row', 'aria-label': 'Delete this row' }));
      footer.push(btn('Duplicate', () => {
        const values = {};
        for (const f of fields) {
          if (f.c.generated || f.c.default.kind === 'auto' || (['pk', 'unique'].includes(d.key.mode) && d.key.cols.includes(f.c.name))) continue;
          const out = f.output();
          if (out !== undefined) values[f.c.name] = out;
        }
        m.guard = null;
        m.close();
        rowEditor(t, null, values);
      }, 'ghost', 'copy'));
    }
    footer.push(h('span', { class: 'spacer' }), btn(canEdit ? 'Cancel' : 'Close', () => m.close()));
    if (canEdit) footer.push(saveBtn);
    const m = modal({ title: creating ? `New row · ${tlabel(t)}` : `Row · ${tlabel(t)}`, body, foot: footer, drawer: true });
    m.guard = () => !fields.some((f) => f.changed()) || window.__vdbmDiscard === true || discardAsk();
    let asking = false;
    function discardAsk() {
      if (asking) return false;
      asking = true;
      confirmBox({ title: 'Discard your changes?', message: 'The changes in this row have not been saved.', confirm: 'Discard', danger: true }).then((ok) => {
        asking = false;
        if (ok) {
          m.guard = null;
          m.close();
        }
      });
      return false;
    }

    let row = creating ? null : d.rows[ri];
    if (!creating) {
      put(body, h('div', { class: 'empty' }, spinner(), 'Loading the row…'));
      try {
        const full = await api('row', { table: t, key });
        row = full.row;
        if (full.ambiguous) body.prepend(h('div', { class: 'alert warn' }, icon('alert'), h('div', { class: 'a-body' }, 'Several rows match this one exactly; a change applies to only one of them.')));
      } catch (e) {
        fill(body, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
        return;
      }
      body.querySelector('.empty')?.remove();
    }

    const keyCols = new Set(['pk', 'unique'].includes(d.key.mode) ? d.key.cols : []);
    for (const [i, c] of columns.entries()) {
      const f = makeField(c, creating ? undefined : row[i], { creating, readonly: !canEdit, isKey: keyCols.has(c.name), preset: preset && c.name in preset ? preset[c.name] : undefined, onchange: refresh });
      fields.push(f);
      put(body, f.el);
    }
    function refresh() {
      if (!creating) saveBtn.disabled = !fields.some((f) => f.changed());
    }
    body.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'Enter' || e.key === 's')) {
        e.preventDefault();
        if (!saveBtn.disabled) saveBtn.click();
      }
    });

    async function save() {
      const values = {};
      for (const f of fields) {
        if (!f.changed() && !(creating && f.touched())) continue;
        const out = f.output();
        if (out !== undefined) values[f.c.name] = out;
      }
      for (const f of fields) f.validate && f.validate();
      if (fields.some((f) => f.invalid && f.invalid())) {
        toast('Fix the highlighted fields first.', 'err');
        return;
      }
      await busy(saveBtn, async () => {
        try {
          if (creating) {
            const r = await api('insert', { table: t, values });
            toast(r.insert_id && r.insert_id !== '0' ? `Row created (id ${r.insert_id})` : 'Row created');
            bumpCount(t, 1);
          } else {
            const r = await api('update', { table: t, key, values });
            toast(r.affected ? 'Row saved' : 'Saved — nothing changed in the database');
          }
          m.guard = null;
          m.close();
          await loadRows(st);
        } catch (e) {
          if (e.kind === 'stale') {
            m.guard = null;
            m.close();
            loadRows(st);
          }
          fail(e);
        }
      });
    }
    async function del() {
      const ok = await confirmBox({ title: 'Delete this row?', message: 'It is removed at once. This cannot be undone.', confirm: 'Delete', danger: true });
      if (!ok) return;
      try {
        await api('delete', { table: t, keys: [key] });
        toast('Row deleted');
        bumpCount(t, -1);
        m.guard = null;
        m.close();
        await loadRows(st);
      } catch (e) {
        fail(e);
      }
    }
  }

  /**
   * One field of the row editor. output(): the value to send — a string, null, {default}, {expr}, {x} (hex) or {b} (base64);
   * undefined leaves the column out.
   */
  function makeField(c, initial, { creating, readonly, isKey, preset, onchange }) {
    // state: mode value | null | expr | default
    let mode = 'value';
    let value = '';
    let expr = '';
    let touched = false;
    let invalid = false;
    let binNew = null; // {x} or {b}
    if (!creating) {
      if (initial === null) mode = 'null';
      else if (isBin(initial)) value = initial;
      else if (isCut(initial)) value = initial;
      else value = String(initial);
    } else if (preset !== undefined) {
      touched = true;
      if (preset === null) mode = 'null';
      else if (typeof preset === 'object' && preset.expr) {
        mode = 'expr';
        expr = preset.expr;
      } else if (isBin(preset)) {
        binNew = { b: preset.b };
        value = preset;
      } else value = String(preset);
    }
    const startMode = mode, startValue = value;
    const cut = isCut(initial);
    const locked = readonly || c.generated || cut;
    const el = h('div', { class: 'field-row' });
    const tools = h('div', { class: 'fr-tools' });
    const badges = [];
    if (isKey) badges.push(h('span', { class: 'badge pk' }, icon('key'), 'key'));
    if (c.default.kind === 'auto') badges.push(h('span', { class: 'badge' }, PG ? 'identity' : 'auto increment'));
    if (c.generated) badges.push(h('span', { class: 'badge' }, 'generated'));
    if (!c.nullable && c.default.kind === 'none') badges.push(h('span', { class: 'badge', title: 'NOT NULL without a default' }, 'required'));
    put(el, h('div', { class: 'fr-head' }, h('span', { class: 'fr-name', title: c.name }, c.name), h('span', { class: 'fr-type', title: c.type }, typeName(c)), badges, tools));
    const holder = h('div');
    const note = h('div', { class: 'note' });
    put(el, holder, note);
    if (c.comment) note.textContent = c.comment;

    const defaultText = () => {
      const k = c.default.kind;
      if (k === 'auto') return PG ? 'Generated by the identity' : 'Next auto-increment value';
      if (k === 'generated') return 'Computed by the database';
      if (k === 'literal') return `Default: ${c.default.value}`;
      if (k === 'expr') return `Default: ${c.default.value}`;
      if (k === 'null') return 'Default: NULL';
      return c.nullable ? 'Default: NULL' : 'No default — a value is required';
    };

    function changed() {
      if (locked) return false;
      if (creating) return touched;
      if (mode !== startMode) return true;
      if (mode === 'value') return binNew !== null || value !== startValue;
      return mode === 'expr' || mode === 'default';
    }
    function setMode(m) {
      mode = m;
      touched = true;
      draw();
      onchange();
    }
    function output() {
      if (locked) return undefined;
      if (creating && !touched) return undefined;
      if (mode === 'null') return null;
      if (mode === 'default') return { default: true };
      if (mode === 'expr') return { expr };
      if (c.cat === 'bin') return binNew ?? undefined;
      return value;
    }

    function draw() {
      el.classList.toggle('changed', changed());
      fill(tools);
      if (!locked) {
        if (c.nullable) put(tools, check('NULL', mode === 'null', (on) => setMode(on ? 'null' : 'value')));
        put(tools, h('button', { type: 'button', class: 'btn ghost sm icon', title: 'Set to a function or DEFAULT', onclick: (e) => menu(e.currentTarget, [
          { label: 'DEFAULT', icon: 'zap', onClick: () => setMode('default'), hint: c.default.kind !== 'none' ? String(c.default.value ?? c.default.kind) : '' },
          { sep: true },
          ...BOOT.functions.map((fn) => ({ label: fn, onClick: () => {
            expr = fn;
            setMode('expr');
          } })),
          creating && touched ? { sep: true } : null,
          creating && touched ? { label: 'Leave out (use the default)', onClick: () => {
            touched = false;
            mode = 'value';
            value = '';
            binNew = null;
            draw();
            onchange();
          } } : null,
        ].filter(Boolean)) }, icon('zap')));
      }
      fill(holder);
      if (c.generated) {
        put(holder, h('div', { class: 'input mono', style: 'display:flex;align-items:center;opacity:.75' }, value === '' && mode === 'null' ? 'NULL' : plainValue(initial) ?? 'NULL'));
        note.textContent = c.expression ? `= ${c.expression}` : 'Generated column';
        return;
      }
      if (mode === 'null') {
        put(holder, h('div', { class: 'input', style: 'display:flex;align-items:center' }, h('span', { class: 'v-null' }, 'NULL')));
        return;
      }
      if (mode === 'default') {
        put(holder, h('div', { class: 'input', style: 'display:flex;align-items:center;gap:8px' }, h('span', { class: 'badge solid' }, 'DEFAULT'), h('span', { class: 'muted small' }, defaultText())));
        return;
      }
      if (mode === 'expr') {
        put(holder, h('div', { class: 'input mono', style: 'display:flex;align-items:center;gap:8px' }, icon('zap'), expr, h('span', { class: 'grow' }),
          h('button', { type: 'button', class: 'btn ghost sm', onclick: () => setMode('value') }, 'Type a value')));
        return;
      }
      if (cut) {
        put(holder, h('textarea', { class: 'input mono', rows: 6, readOnly: true, value: initial.t }));
        note.textContent = `This value has ${num(initial.n)} characters — too long to edit here. Use the SQL console to change it.`;
        return;
      }
      put(holder, control());
    }

    function control() {
      const ph = creating && !touched ? defaultText() : '';
      const onInput = (v) => {
        value = v;
        touched = true;
        el.classList.toggle('changed', changed());
        onchange();
      };
      if (c.cat === 'bin') return binControl();
      if (c.cat === 'bool') {
        const sel = select([['', creating && !touched ? ph : '—'], [boolOut(true), 'true'], [boolOut(false), 'false']], creating && !touched ? '' : value === '' ? '' : boolOut(isTrue(value)), {
          disabled: readonly,
          onchange: (e) => onInput(e.target.value),
        });
        return sel;
      }
      if ((c.cat === 'enum') && Array.isArray(c.options)) {
        return select([['', creating && !touched ? ph : '—'], ...c.options.map((o) => [o, o])], creating && !touched ? '' : value, { disabled: readonly, onchange: (e) => onInput(e.target.value) });
      }
      if (c.cat === 'set' && Array.isArray(c.options)) {
        const on = new Set(value === '' ? [] : value.split(','));
        return h('div', { class: 'row', style: 'flex-wrap:wrap;gap:12px' }, c.options.map((o) => check(o, on.has(o), (v) => {
          v ? on.add(o) : on.delete(o);
          onInput(c.options.filter((x) => on.has(x)).join(','));
        })));
      }
      const multi = ['text', 'json', 'geo', 'array', 'other'].includes(c.cat) && (c.cat !== 'other' || value.length > 60 || /\n/.test(value)) || (c.cat === 'str' && (value.length > 80 || /\n/.test(value) || (c.max ?? 0) > 255));
      const mono = ['json', 'geo', 'vector', 'array', 'other', 'bin'].includes(c.cat);
      const placeholders = { date: 'YYYY-MM-DD', datetime: 'YYYY-MM-DD HH:MM:SS', time: 'HH:MM:SS', year: 'YYYY', json: '{"key": "value"}', geo: 'POINT(1 2)', vector: '[1, 2, 3]', array: '{1,2,3}' };
      const props = { class: 'input' + (mono ? ' mono' : ''), value, placeholder: ph || placeholders[c.cat] || '', readOnly: readonly, spellcheck: false, dir: 'auto', 'aria-label': c.name };
      if (NUMERIC.has(c.cat)) props.inputmode = 'decimal';
      const el2 = multi ? h('textarea', { ...props, rows: c.cat === 'json' ? Math.min(14, Math.max(4, value.split('\n').length + 1)) : Math.min(10, Math.max(3, Math.ceil(value.length / 60))) }) : h('input', { ...props, type: 'text' });
      el2.addEventListener('input', () => {
        onInput(el2.value);
        if (c.cat === 'json') validateJson();
      });
      if (c.cat !== 'json') return el2;
      const status = h('span', { class: 'small muted' });
      const validateJson = () => {
        if (el2.value.trim() === '') {
          invalid = false;
          status.textContent = '';
          return;
        }
        try {
          JSON.parse(el2.value);
          invalid = false;
          status.textContent = 'Valid JSON';
          status.style.color = 'var(--success)';
        } catch (err) {
          invalid = true;
          status.textContent = 'Invalid JSON: ' + err.message;
          status.style.color = 'var(--destructive)';
        }
      };
      validateJson();
      return h('div', { class: 'stack', style: 'gap:6px' }, el2, h('div', { class: 'row' }, status, h('span', { class: 'grow' }),
        readonly ? null : h('button', { type: 'button', class: 'btn ghost sm', onclick: () => {
          try {
            el2.value = JSON.stringify(JSON.parse(el2.value), null, 2);
            el2.rows = Math.min(14, Math.max(4, el2.value.split('\n').length + 1));
            onInput(el2.value);
            validateJson();
          } catch {
            validateJson();
          }
        } }, 'Format')));
    }

    function binControl() {
      const cur = binNew ? binNew : isBin(value) ? value : null;
      const info = h('span', { class: 'v-bin' });
      const describe = () => {
        if (binNew && 'x' in binNew) info.textContent = `${bytes(binNew.x.length / 2)} (edited)`;
        else if (binNew && 'b' in binNew) info.textContent = `${bytes(atob(binNew.b).length)} (new file)`;
        else if (cur) info.textContent = cur.n ? `0x${bytesToHex(b64ToBytes(cur.b), 16).toUpperCase()}${cur.n > 16 ? '…' : ''} · ${bytes(cur.n)}` : '0 bytes';
        else info.textContent = creating ? 'No value' : '0 bytes';
      };
      describe();
      const file = h('input', { type: 'file', class: 'hidden', onchange: async () => {
        const f = file.files[0];
        if (!f) return;
        if (f.size > 16 * 1024 * 1024) {
          toast('Choose a file of at most 16 MB.', 'err');
          return;
        }
        binNew = { b: await fileToBase64(f) };
        touched = true;
        describe();
        draw();
        onchange();
      } });
      const actions = h('div', { class: 'row', style: 'flex-wrap:wrap' });
      if (!readonly) {
        put(actions, btn('Upload file', () => file.click(), 'sm', 'upload'));
        const small = !cur || cur.n <= 65536;
        if (small) put(actions, btn('Edit as hex', () => {
          const hex = binNew && 'x' in binNew ? binNew.x : cur ? bytesToHex(b64ToBytes(cur.b)) : '';
          const ta = h('textarea', { class: 'input mono', rows: 6, value: hex, spellcheck: false });
          fill(holder, ta, h('div', { class: 'note' }, 'Hexadecimal digits; spaces are ignored.'));
          ta.focus();
          ta.addEventListener('input', () => {
            const clean = ta.value.replace(/\s+/g, '').replace(/^0x/i, '');
            invalid = !/^(?:[0-9a-fA-F]{2})*$/.test(clean);
            ta.style.borderColor = invalid ? 'var(--destructive)' : '';
            if (!invalid) {
              binNew = { x: clean.toLowerCase() };
              touched = true;
              el.classList.toggle('changed', changed());
              onchange();
            }
          });
        }, 'sm', 'code'));
      }
      if (cur && cur.n) {
        put(actions, btn('Download', () => download(new Blob([b64ToBytes(cur.b)]), `${c.name}.bin`), 'sm', 'download'));
      }
      return h('div', { class: 'stack', style: 'gap:8px' }, h('div', { class: 'input', style: 'display:flex;align-items:center' }, info), actions, file);
    }

    draw();
    return { c, el, changed, output, touched: () => touched, invalid: () => invalid && mode === 'value' };
  }

  // ── import ──────────────────────────────────────────────────────────────────────────────
  /** RFC 4180 CSV. An unquoted empty field is null, a quoted one ''. */
  function parseCsv(text, sep) {
    const rows = [];
    let row = [];
    let i = 0;
    const n = text.length;
    let field = '';
    let quoted = false;
    let any = false;
    const end = () => {
      row.push(quoted || field !== '' ? field : null);
      field = '';
      quoted = false;
    };
    while (i < n) {
      const ch = text[i];
      if (ch === '"' && field === '' && !quoted) {
        quoted = true;
        any = true;
        i++;
        while (i < n) {
          if (text[i] === '"') {
            if (text[i + 1] === '"') {
              field += '"';
              i += 2;
              continue;
            }
            i++;
            break;
          }
          field += text[i++];
        }
        continue;
      }
      if (ch === sep) {
        end();
        any = true;
        i++;
        continue;
      }
      if (ch === '\n' || ch === '\r') {
        end();
        rows.push(row);
        row = [];
        any = false;
        i += ch === '\r' && text[i + 1] === '\n' ? 2 : 1;
        continue;
      }
      field += ch;
      any = true;
      i++;
    }
    if (any || field !== '' || row.length) {
      end();
      rows.push(row);
    }
    return rows.filter((r) => !(r.length === 1 && r[0] === null));
  }
  function guessSep(text) {
    const line = text.slice(0, text.indexOf('\n') > 0 ? text.indexOf('\n') : 2000);
    let best = ',', count = -1;
    for (const s of [',', ';', '\t', '|']) {
      const k = line.split(s).length - 1;
      if (k > count) {
        best = s;
        count = k;
      }
    }
    return best;
  }

  async function importDialog(st) {
    if (!st.data) await loadRows(st);
    const d = st.data;
    if (!d) return;
    const cols = d.columns.filter((c) => !c.generated);
    let text = null, rows = [], header = true, sep = ',', nullEmpty = true, mode = 'insert', mapping = [];
    const body = h('div', { class: 'stack' });
    const runBtn = btn('Import', () => run(), 'primary', 'upload', { disabled: true });
    const m = modal({ title: `Import into ${tlabel(st.table)}`, body, foot: [h('span', { class: 'spacer' }), btn('Close', () => m.close()), runBtn], size: 'wide', sticky: true });
    const file = h('input', { type: 'file', accept: '.csv,.tsv,.txt,text/csv,text/plain', class: 'hidden', onchange: () => file.files[0] && readFile(file.files[0]) });
    const drop = h('div', { class: 'card', style: 'border-style:dashed;cursor:pointer', onclick: () => file.click() },
      h('div', { class: 'empty' }, icon('upload'), h('div', null, 'Drop a CSV file here, or click to choose one'), h('div', { class: 'small' }, 'The first row can hold the column names. Exports from this manager import as they are.')));
    drop.addEventListener('dragover', (e) => {
      e.preventDefault();
      drop.style.borderColor = 'var(--primary)';
    });
    drop.addEventListener('dragleave', () => (drop.style.borderColor = ''));
    drop.addEventListener('drop', (e) => {
      e.preventDefault();
      drop.style.borderColor = '';
      e.dataTransfer.files[0] && readFile(e.dataTransfer.files[0]);
    });
    const setup = h('div', { class: 'stack' });
    const progress = h('div');
    put(body, drop, file, setup, progress);

    async function readFile(f) {
      if (f.size > 200 * 1024 * 1024) {
        toast('Choose a file of at most 200 MB.', 'err');
        return;
      }
      text = (await f.text()).replace(/^﻿/, '');
      sep = guessSep(text);
      drop.querySelector('.empty > div').textContent = `${f.name} · ${bytes(f.size)}`;
      parse();
    }
    function parse() {
      rows = parseCsv(text, sep);
      const width = Math.max(0, ...rows.slice(0, 50).map((r) => r.length));
      const names = header && rows.length ? rows[0] : [];
      mapping = Array.from({ length: width }, (_, k) => {
        const n = String(names[k] ?? '').trim().toLowerCase();
        const hit = cols.find((c) => c.name.toLowerCase() === n) || (!header ? cols[k] : null);
        return hit ? hit.name : '';
      });
      drawSetup();
    }
    function drawSetup() {
      const data = header ? rows.slice(1) : rows;
      const sample = data.slice(0, 3);
      const names = header && rows.length ? rows[0] : [];
      const keyOk = ['pk', 'unique'].includes(d.key.mode);
      fill(setup, 
        h('div', { class: 'grid3' },
          field('Separator', select([[',', 'Comma ,'], [';', 'Semicolon ;'], ['\t', 'Tab'], ['|', 'Pipe |']], sep, { onchange: (e) => {
            sep = e.target.value;
            parse();
          } })),
          field('Rows that already exist', select([['insert', 'Stop with an error'], ['ignore', 'Skip them'], ...(keyOk ? [['upsert', 'Update them']] : [])], mode, { onchange: (e) => (mode = e.target.value) }),
            keyOk ? 'Matched by the primary or unique key.' : 'Updating needs a primary or unique key.'),
          h('div', { class: 'stack', style: 'gap:8px;padding-top:24px' },
            check('First row holds column names', header, (v) => {
              header = v;
              parse();
            }),
            check('Empty unquoted cells are NULL', nullEmpty, (v) => (nullEmpty = v)))),
        h('div', { class: 'small muted' }, `${plural(data.length, 'row')} to import. Choose the table column for each file column.`),
        h('div', { class: 'grid-wrap', style: 'max-height:340px' }, h('table', { class: 'list' },
          h('thead', null, h('tr', null, h('th', null, 'File column'), h('th', null, 'Imports into'), h('th', null, 'Sample values'))),
          h('tbody', null, mapping.map((target, k) => h('tr', null,
            h('td', { class: 'mono' }, header ? String(names[k] ?? `#${k + 1}`) : `#${k + 1}`),
            h('td', { style: 'width:240px' }, select([['', '— skip —'], ...cols.map((c) => [c.name, `${c.name} · ${shortType(c)}`])], target, {
              class: 'select sm',
              onchange: (e) => {
                mapping[k] = e.target.value;
                validate();
              },
            })),
            h('td', { class: 'muted mono', style: 'max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, sample.map((r) => (r[k] === null || r[k] === undefined ? 'NULL' : JSON.stringify(r[k]))).join('  ·  '))))))));
      validate();
    }
    function validate() {
      const used = mapping.filter(Boolean);
      const dup = used.find((n, i) => used.indexOf(n) !== i);
      runBtn.disabled = !used.length || !!dup || !(header ? rows.length - 1 : rows.length);
      fill(progress, dup ? h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, `${dup} is chosen for more than one file column.`)) : '');
    }
    async function run() {
      const data = header ? rows.slice(1) : rows;
      const targets = mapping.map((name, k) => [name, k]).filter(([name]) => name);
      const columns = targets.map(([name]) => name);
      const total = data.length;
      let done = 0, affected = 0, warnings = 0;
      const bar = h('i', { style: 'width:0%' });
      const label = h('span', { class: 'small muted' });
      fill(progress, h('div', { class: 'stack', style: 'gap:6px' }, h('div', { class: 'bar', style: 'height:8px' }, bar), label));
      runBtn.disabled = true;
      m.sticky = true;
      try {
        let at = 0;
        while (at < total) {
          const batch = [];
          let size = 0;
          while (at < total && batch.length < 2000 && size < 2500000) {
            const r = data[at++];
            const vals = targets.map(([, k]) => {
              const v = r[k] === undefined ? null : r[k];
              return v === null ? (nullEmpty ? null : '') : v;
            });
            size += JSON.stringify(vals).length;
            batch.push(vals);
          }
          const res = await api('insert_many', { table: st.table, columns, rows: batch, mode });
          affected += res.affected || 0;
          warnings += (res.warnings || []).length;
          done += batch.length;
          bar.style.width = `${Math.round((done / total) * 100)}%`;
          label.textContent = `${num(done)} of ${num(total)} rows sent · ${num(affected)} affected`;
        }
        label.textContent = `Done: ${num(total)} rows sent, ${num(affected)} affected${warnings ? `, ${warnings} warnings` : ''}.`;
        toast(`Import finished: ${num(affected)} rows affected`);
        loadRows(st);
        refreshOverview();
      } catch (e) {
        put(progress, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' },
          `Stopped after ${num(done)} rows (each batch of up to 2000 rows is all or nothing). ${e.message}`)));
        loadRows(st);
      } finally {
        m.sticky = false;
        runBtn.disabled = false;
      }
    }
  }

  // ── export ──────────────────────────────────────────────────────────────────────────────
  function exportDialog(st) {
    let format = 'csv', scope = st.filters.length || st.search ? 'filtered' : 'all', structure = true;
    const limit = input({ placeholder: 'All rows', inputmode: 'numeric' });
    const opts = h('div');
    const draw = () => fill(opts, format === 'sql' ? check('Include CREATE TABLE', structure, (v) => (structure = v)) : '');
    const fmt = select([['csv', 'CSV (Excel, Sheets)'], ['json', 'JSON'], ['sql', 'SQL INSERT statements']], format, { onchange: (e) => {
      format = e.target.value;
      draw();
    } });
    const scopeSel = select([['filtered', 'Rows matching the search and filters'], ['all', 'All rows']], scope, { onchange: (e) => (scope = e.target.value), disabled: !(st.filters.length || st.search) });
    draw();
    const go2 = btn('Download', () => busy(go2, async () => {
      try {
        const args = { table: st.table, format, sort: st.sort, limit: parseInt(limit.value, 10) || 0, structure };
        if (scope === 'filtered') Object.assign(args, { filters: st.filters, search: st.search });
        const r = await api('export', args);
        download(r.data, r.filename, r.mime + ';charset=utf-8');
        toast(`${plural(r.rows, 'row')} exported`);
        m.close();
      } catch (e) {
        fail(e);
      }
    }), 'primary', 'download');
    const m = modal({
      title: `Export ${tlabel(st.table)}`,
      body: [field('Format', fmt), field('Rows', scopeSel), field('Limit', limit, 'Leave empty to export every row. Very large tables are better exported with a backup.'), opts],
      foot: [h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), go2],
    });
  }

  // ── structure tabs ──────────────────────────────────────────────────────────────────────
  async function loadStructure(st, force = false) {
    if (st.structure && !force) return st.structure;
    st.structure = await api('structure', { table: st.table });
    return st.structure;
  }

  function renderStructureTab(st, tab, body) {
    fill(body, h('div', { class: 'empty' }, spinner(), 'Loading the structure…'));
    loadStructure(st).then((s) => {
      if (S.route.view !== 'table' || !sameTable(S.route.table, st.table) || S.route.tab !== tab) return;
      fill(body);
      if (tab === 'columns') columnsTab(st, s, body);
      else if (tab === 'indexes') indexesTab(st, s, body);
      else if (tab === 'relations') relationsTab(st, s, body);
      else settingsTab(st, s, body);
    }).catch((e) => fill(body, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message))));
  }

  /** Runs one structure change: an SQL preview to confirm, then the change. */
  async function ddl(args, { title, message, confirm = 'Apply', danger = false, typed = null, skipPreview = false } = {}) {
    let sql;
    try {
      sql = (await api('ddl', { ...args, dry: true })).sql;
    } catch (e) {
      fail(e);
      return null;
    }
    if (!skipPreview) {
      const ok = await confirmBox({ title, message, sql, confirm, danger, typed });
      if (!ok) return null;
    }
    try {
      const r = await api('ddl', args);
      afterDdl(args);
      return r;
    } catch (e) {
      fail(e);
      return null;
    }
  }
  function afterDdl(args) {
    const t = args.table && typeof args.table === 'object' ? args.table : null;
    if (t) {
      const st = tstate(t);
      st.structure = null;
      st.data = null;
    }
    invalidateTables();
    refreshOverview().then(() => S.route.view === 'table' && render());
  }

  function columnsTab(st, s, body) {
    const fkCols = new Set(s.foreign_keys.flatMap((f) => f.columns));
    const uniq = new Set(s.indexes.filter((i) => i.unique && !i.primary && i.columns.length === 1).map((i) => i.columns[0].name));
    const pk = new Set(s.key.mode === 'pk' ? s.key.cols : []);
    const editable = ['table', 'partitioned'].includes(s.table.type);
    put(body, h('div', { class: 'card' },
      h('div', { class: 'card-head' }, h('h3', null, `${plural(s.columns.length, 'column')}`),
        editable ? btn('Add column', () => columnDialog(st, s, null), 'primary sm', 'plus') : null),
      h('div', { style: 'overflow:auto' }, h('table', { class: 'list' },
        h('thead', null, h('tr', null, h('th', null, 'Name'), h('th', null, 'Type'), h('th', null, 'Null'), h('th', null, 'Default'), h('th', null, 'Comment'), h('th'))),
        h('tbody', null, s.columns.map((c) => h('tr', null,
          h('td', null, h('div', { class: 'row', style: 'gap:6px' }, h('b', { class: 'mono', style: 'font-weight:600' }, c.name),
            pk.has(c.name) ? h('span', { class: 'badge pk' }, 'PK') : null, uniq.has(c.name) ? h('span', { class: 'badge uni' }, 'UNIQUE') : null,
            fkCols.has(c.name) ? h('span', { class: 'badge fk' }, 'FK') : null)),
          h('td', { class: 'mono' }, typeName(c), c.collation && !PG && c.cat !== 'json' ? h('div', { class: 'small muted' }, c.collation) : null),
          h('td', null, c.nullable ? h('span', { class: 'badge' }, 'NULL') : h('span', { class: 'badge solid' }, 'NOT NULL')),
          h('td', { class: 'mono small' }, defaultLabel(c)),
          h('td', { class: 'muted', style: 'max-width:260px' }, c.comment || ''),
          h('td', { class: 'actions' }, editable ? [
            iconBtn('edit', 'Edit column', () => columnDialog(st, s, c), 'ghost sm'),
            iconBtn('trash', 'Drop column', () => ddl({ action: 'drop_column', table: st.table, columns: [c.name] }, {
              title: `Drop the column ${c.name}?`, message: 'Its data is deleted for every row. This cannot be undone.', confirm: 'Drop column', danger: true,
            }), 'ghost sm'),
          ] : null))))))));
  }
  function defaultLabel(c) {
    const base = defaultValueLabel(c);
    return c.on_update ? h('span', null, base, ' ', h('span', { class: 'badge', title: `ON UPDATE ${c.on_update}` }, 'on update')) : base;
  }
  function defaultValueLabel(c) {
    const d = c.default;
    if (d.kind === 'auto') return h('span', { class: 'badge' }, PG ? `identity ${c.identity === 'always' ? 'always' : 'by default'}` : 'auto increment');
    if (d.kind === 'generated' || c.generated) return h('span', { title: c.expression || '' }, h('span', { class: 'badge' }, c.stored || PG ? 'stored' : 'virtual'), ' = ', c.expression || '');
    if (d.kind === 'literal') return NUMERIC.has(c.cat) && /^-?[\d.]+$/.test(d.value) ? d.value : JSON.stringify(d.value);
    if (d.kind === 'expr') return d.value;
    if (d.kind === 'null') return 'NULL';
    return h('span', { class: 'muted' }, '—');
  }

  const TYPES = PG
    ? ['bigint', 'integer', 'smallint', 'numeric(10,2)', 'real', 'double precision', 'boolean', 'text', 'varchar(255)', 'char(1)', 'uuid', 'jsonb', 'json',
      'date', 'timestamp', 'timestamptz', 'time', 'interval', 'bytea', 'inet', 'cidr', 'macaddr', 'text[]', 'integer[]', 'tsvector', 'xml', 'money']
    : ['bigint unsigned', 'bigint', 'int', 'int unsigned', 'smallint', 'tinyint', 'tinyint(1)', 'decimal(10,2)', 'double', 'float', 'varchar(255)', 'char(36)',
      'text', 'mediumtext', 'longtext', 'json', 'date', 'datetime', 'datetime(3)', 'timestamp', 'time', 'year', 'enum', 'set', 'blob', 'longblob',
      'varbinary(255)', 'binary(16)', 'bit(1)', 'point', 'geometry', ...(MARIA ? ['uuid', 'inet6', 'vector(3)'] : ['vector(3)'])];
  let typeListId = null;
  function typeList() {
    if (!typeListId) {
      typeListId = 'vdbm-types';
      put(document.body, h('datalist', { id: typeListId }, TYPES.map((t) => h('option', { value: t }))));
      put(document.body, h('datalist', { id: 'vdbm-funcs' }, BOOT.functions.map((t) => h('option', { value: t }))));
    }
    return typeListId;
  }
  const isIntType = (t) => /^(tiny|small|medium|big)?int(eger)?\b|^serial|^bigserial|^int[248]\b/i.test(t.trim());
  const isTimeType = (t) => /^(datetime|timestamp)\b/i.test(t.trim());
  const isTextType = (t) => /^(var)?char|^(tiny|medium|long)?text|^enum|^set|^character/i.test(t.trim());

  /**
   * The column form, for a new column (c = null) or a change. spec mirrors the engine's column spec.
   */
  function columnDialog(st, s, c, onDone) {
    const editing = !!c;
    const spec = editing ? {
      name: c.name,
      type: !PG && (c.cat === 'enum' || c.cat === 'set') ? c.cat : typeName(c),
      options: c.options ? [...c.options] : [],
      nullable: c.nullable,
      default: ['none', 'null', 'literal', 'expr'].includes(c.default.kind) ? { kind: c.default.kind, value: c.default.value ?? '' } : { kind: 'none', value: '' },
      auto_increment: !PG && c.auto,
      on_update: !PG && !!c.on_update,
      identity: PG ? c.identity || '' : '',
      generated: c.generated ? { expr: c.expression || '', stored: !!c.stored } : null,
      comment: c.comment || '',
      collation: c.collation || '',
      using: '',
    } : { name: '', type: PG ? 'text' : 'varchar(255)', options: [], nullable: true, default: { kind: 'none', value: '' }, auto_increment: false, on_update: false, identity: '', generated: null, comment: '', collation: '', using: '' };
    const startType = spec.type;
    let position = '';
    const form = h('div', { class: 'stack' });
    const preview = h('div');
    const saveBtn = btn(editing ? 'Save column' : 'Add column', () => save(), 'primary');
    const m = modal({
      title: editing ? `Edit column ${c.name}` : `Add a column to ${tlabel(st.table)}`,
      body: [form, preview],
      foot: [btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'), h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), saveBtn],
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
      if (spec.generated && spec.generated.expr.trim()) col.generated = { expr: spec.generated.expr.trim(), stored: PG ? true : spec.generated.stored };
      else {
        col.default = spec.default.kind === 'literal' || spec.default.kind === 'expr' ? { kind: spec.default.kind, value: spec.default.value } : { kind: spec.default.kind };
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
      const nameIn = input({ value: spec.name, placeholder: 'column_name', oninput: (e) => (spec.name = e.target.value) });
      const typeIn = input({ value: spec.type, list: typeList(), class: 'input mono', oninput: (e) => {
        spec.type = e.target.value;
        drawLater();
      } });
      const genOn = !!spec.generated;
      const defKind = select([['none', 'No default'], ['null', 'NULL'], ['literal', 'A value'], ['expr', 'An expression or function']], spec.default.kind, {
        onchange: (e) => {
          spec.default.kind = e.target.value;
          draw();
        },
      });
      const defValue = spec.default.kind === 'literal' || spec.default.kind === 'expr'
        ? input({ value: spec.default.value, class: 'input' + (spec.default.kind === 'expr' ? ' mono' : ''), list: spec.default.kind === 'expr' ? (typeList(), 'vdbm-funcs') : null,
          placeholder: spec.default.kind === 'expr' ? BOOT.functions[0] : 'Value', oninput: (e) => (spec.default.value = e.target.value) })
        : null;
      const parts = [
        h('div', { class: 'grid2' }, field('Name', nameIn), field('Type', typeIn, PG ? 'e.g. text, bigint, numeric(10,2), timestamptz, jsonb, text[]' : 'e.g. varchar(255), int unsigned, decimal(10,2), datetime, json')),
      ];
      if (!PG && (type === 'enum' || type === 'set')) {
        parts.push(field('Values', h('textarea', { class: 'input', rows: 4, value: spec.options.join('\n'), oninput: (e) => (spec.options = e.target.value.split('\n').map((x) => x.trim()).filter(Boolean)) }), 'One value per line.'));
      }
      parts.push(h('div', { class: 'row', style: 'gap:20px;flex-wrap:wrap' },
        toggle('Allow NULL', spec.nullable, (v) => (spec.nullable = v)),
        !PG && isIntType(type) && !genOn ? toggle('Auto increment', spec.auto_increment, (v) => {
          spec.auto_increment = v;
          draw();
        }) : null,
        !PG && isTimeType(type) && !genOn ? toggle('Update to the current time on every change', spec.on_update, (v) => (spec.on_update = v)) : null,
        toggle('Generated column', genOn, (v) => {
          spec.generated = v ? { expr: '', stored: true } : null;
          draw();
        })));
      if (genOn) {
        parts.push(h('div', { class: 'grid2' },
          field('Expression', input({ class: 'input mono', value: spec.generated.expr, placeholder: PG ? "upper(name)" : "CONCAT(first, ' ', last)", oninput: (e) => (spec.generated.expr = e.target.value) })),
          PG ? h('div', { class: 'small muted', style: 'padding-top:28px' }, 'PostgreSQL stores generated values.') : field('Storage', select([['1', 'Stored'], ['0', 'Virtual (computed on read)']], spec.generated.stored ? '1' : '0', { onchange: (e) => (spec.generated.stored = e.target.value === '1') }))));
      } else if (PG && isIntType(type)) {
        parts.push(h('div', { class: 'grid2' },
          field('Identity', select([['', 'None'], ['by_default', 'Generated by default'], ['always', 'Generated always']], spec.identity, { onchange: (e) => {
            spec.identity = e.target.value;
            draw();
          } })),
          spec.identity ? h('div') : field('Default', defKind)));
        if (!spec.identity && defValue) parts.push(field(spec.default.kind === 'expr' ? 'Default expression' : 'Default value', defValue));
      } else if (!spec.auto_increment) {
        parts.push(h('div', { class: 'grid2' }, field('Default', defKind), defValue ? field(spec.default.kind === 'expr' ? 'Expression' : 'Value', defValue) : h('div')));
      }
      if (PG && editing && type !== startType) {
        parts.push(field('Convert existing values with (USING)', input({ class: 'input mono', value: spec.using, placeholder: `${qi(c.name)}::${type || 'type'}`, oninput: (e) => (spec.using = e.target.value) }),
          'Optional. Needed when PostgreSQL cannot convert the old type to the new one by itself.'));
      }
      const extra = [];
      if (isTextType(type)) extra.push(field('Collation', input({ value: spec.collation, class: 'input mono', placeholder: PG ? 'default' : 'table default', oninput: (e) => (spec.collation = e.target.value) })));
      if (!PG) {
        const cols = s.columns.filter((x) => !c || x.name !== c.name);
        extra.push(field('Position', select([['', editing ? 'Keep where it is' : 'At the end'], ['first', 'First'], ...cols.map((x) => [x.name, `After ${x.name}`])], position, { onchange: (e) => (position = e.target.value) })));
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

  function indexesTab(st, s, body) {
    const editable = ['table', 'partitioned', 'matview'].includes(s.table.type);
    put(body, h('div', { class: 'card' },
      h('div', { class: 'card-head' }, h('h3', null, plural(s.indexes.length, 'index', 'indexes')), editable ? btn('Add index', () => indexDialog(st, s), 'primary sm', 'plus') : null),
      s.indexes.length ? h('div', { style: 'overflow:auto' }, h('table', { class: 'list' },
        h('thead', null, h('tr', null, h('th', null, 'Name'), h('th', null, 'Kind'), h('th', null, 'Columns'), PG ? h('th', { class: 'num' }, 'Size') : h('th', { class: 'num' }, 'Cardinality'), h('th'))),
        h('tbody', null, s.indexes.map((ix) => h('tr', null,
          h('td', { class: 'mono' }, ix.name, ix.visible === false ? h('span', { class: 'badge warn', style: 'margin-left:6px' }, PG ? 'invalid' : 'invisible') : null),
          h('td', null, h('span', { class: 'badge' + (ix.primary ? ' pk' : ix.unique ? ' uni' : '') }, ix.primary ? 'PRIMARY' : ix.unique ? 'UNIQUE' : String(ix.type || 'INDEX').toUpperCase()),
            PG && ix.type && (ix.primary || ix.unique) ? h('span', { class: 'small muted', style: 'margin-left:6px' }, ix.type) : null),
          h('td', { class: 'mono small' }, ix.columns.map((x) => (x.expr ? `(${x.expr})` : x.name) + (x.length ? `(${x.length})` : '') + (!PG && x.order === 'desc' ? ' DESC' : '')).join(', '),
            ix.predicate ? h('div', { class: 'muted' }, 'WHERE ' + ix.predicate) : null),
          h('td', { class: 'num muted' }, PG ? bytes(ix.size) : num(ix.columns[ix.columns.length - 1]?.cardinality)),
          h('td', { class: 'actions' }, editable ? iconBtn('trash', 'Drop index', () => ddl({ action: 'drop_index', table: st.table, name: ix.name }, {
            title: `Drop the index ${ix.name}?`, message: ix.primary ? 'The table loses its primary key; rows can then only be matched by content.' : 'Queries that use it may become slower.', confirm: 'Drop index', danger: true,
          }), 'ghost sm') : null))))))
        : h('div', { class: 'empty' }, 'No indexes.')));
  }

  function indexDialog(st, s) {
    const kinds = PG ? [['index', 'Index'], ['unique', 'Unique'], ['primary', 'Primary key']] : [['index', 'Index'], ['unique', 'Unique'], ['fulltext', 'Full text'], ['spatial', 'Spatial'], ['primary', 'Primary key']];
    const a = { action: 'add_index', table: st.table, kind: 'index', name: '', columns: [{ name: s.columns[0]?.name || '', order: 'asc', length: '' }], method: 'btree', where: '', concurrently: false };
    const form = h('div', { class: 'stack' });
    const preview = h('div');
    const saveBtn = btn('Add index', () => save(), 'primary');
    const m = modal({ title: `Add an index to ${tlabel(st.table)}`, body: [form, preview], foot: [btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'), h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), saveBtn], size: 'mid', sticky: true });
    const payload = () => {
      const p = { action: 'add_index', table: st.table, kind: a.kind, columns: a.columns.filter((x) => x.name).map((x) => ({ name: x.name, order: x.order, ...(x.length ? { length: parseInt(x.length, 10) } : {}) })) };
      if (a.name.trim() && a.kind !== 'primary') p.name = a.name.trim();
      if (PG && a.kind !== 'primary') Object.assign(p, { method: a.method, where: a.where.trim(), concurrently: a.concurrently });
      return p;
    };
    async function showSql() {
      try {
        fill(preview, h('div', { class: 'card' }, codeBlock((await api('ddl', { ...payload(), dry: true })).sql.join(';\n') + ';')));
      } catch (e) {
        fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
      }
    }
    async function save() {
      await busy(saveBtn, async () => {
        try {
          await api('ddl', payload());
          toast('Index added');
          m.sticky = false;
          m.close();
          afterDdl({ table: st.table });
        } catch (e) {
          fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
        }
      });
    }
    function draw() {
      const colRows = a.columns.map((x, k) => h('div', { class: 'row' },
        select(s.columns.map((c) => c.name), x.name, { onchange: (e) => (x.name = e.target.value) }),
        select([['asc', 'ASC'], ['desc', 'DESC']], x.order, { style: 'width:110px', onchange: (e) => (x.order = e.target.value) }),
        PG ? null : input({ value: x.length, placeholder: 'Prefix', style: 'width:90px', inputmode: 'numeric', title: 'Index only the first N characters', oninput: (e) => (x.length = e.target.value) }),
        iconBtn('x', 'Remove', () => {
          a.columns.splice(k, 1);
          if (!a.columns.length) a.columns.push({ name: s.columns[0]?.name || '', order: 'asc', length: '' });
          draw();
        }, 'ghost sm')));
      fill(form, 
        h('div', { class: 'grid2' },
          field('Kind', select(kinds, a.kind, { onchange: (e) => {
            a.kind = e.target.value;
            draw();
          } })),
          a.kind === 'primary' ? h('div') : field('Name', input({ value: a.name, placeholder: 'Generated when empty', oninput: (e) => (a.name = e.target.value) }))),
        field('Columns', h('div', { class: 'stack', style: 'gap:8px' }, colRows, h('div', null, btn('Add a column', () => {
          a.columns.push({ name: s.columns.find((c) => !a.columns.some((x) => x.name === c.name))?.name || s.columns[0].name, order: 'asc', length: '' });
          draw();
        }, 'ghost sm', 'plus')))),
        PG && a.kind !== 'primary' ? h('div', { class: 'grid2' },
          field('Method', select(['btree', 'hash', 'gin', 'gist', 'spgist', 'brin'], a.method, { onchange: (e) => (a.method = e.target.value) }), 'gin for jsonb, arrays and full-text search.'),
          field('Only rows where (partial index)', input({ class: 'input mono', value: a.where, placeholder: 'deleted_at IS NULL', oninput: (e) => (a.where = e.target.value) }))) : null,
        PG && a.kind !== 'primary' ? toggle('Build concurrently (no write lock; slower)', a.concurrently, (v) => (a.concurrently = v)) : null,
      );
    }
    draw();
  }

  function relationsTab(st, s, body) {
    const editable = ['table', 'partitioned'].includes(s.table.type);
    const ruleBadge = (r) => (r && r !== 'NO ACTION' && r !== 'RESTRICT' ? h('span', { class: 'badge' }, r) : h('span', { class: 'muted small' }, r || ''));
    put(body, 
      h('div', { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Foreign keys'), editable ? btn('Add foreign key', () => fkDialog(st, s), 'primary sm', 'plus') : null),
        s.foreign_keys.length ? h('div', { style: 'overflow:auto' }, h('table', { class: 'list' },
          h('thead', null, h('tr', null, h('th', null, 'Name'), h('th', null, 'Columns'), h('th', null, 'References'), h('th', null, 'On delete'), h('th', null, 'On update'), h('th'))),
          h('tbody', null, s.foreign_keys.map((f) => h('tr', null,
            h('td', { class: 'mono' }, f.name, f.valid === false ? h('span', { class: 'badge warn', style: 'margin-left:6px' }, 'not validated') : null),
            h('td', { class: 'mono' }, f.columns.join(', ')),
            h('td', null, h('a', { href: hashOf({ view: 'table', table: { schema: PG ? f.ref_schema : null, name: f.ref_table } }), class: 'mono' },
              (PG && f.ref_schema && f.ref_schema !== 'public' ? f.ref_schema + '.' : '') + f.ref_table), h('span', { class: 'mono muted' }, ` (${f.ref_columns.join(', ')})`)),
            h('td', null, ruleBadge(f.on_delete)), h('td', null, ruleBadge(f.on_update)),
            h('td', { class: 'actions' }, editable ? iconBtn('trash', 'Drop foreign key', () => ddl({ action: 'drop_fk', table: st.table, name: f.name }, {
              title: `Drop the foreign key ${f.name}?`, confirm: 'Drop', danger: true,
            }), 'ghost sm') : null))))))
          : h('div', { class: 'empty small' }, 'This table references no other table.')),
      h('div', { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Referenced by')),
        s.references.length ? h('div', { style: 'overflow:auto' }, h('table', { class: 'list' },
          h('thead', null, h('tr', null, h('th', null, 'Table'), h('th', null, 'Columns'), h('th', null, 'Constraint'), h('th', null, 'On delete'))),
          h('tbody', null, s.references.map((f) => h('tr', { class: 'click', onclick: () => openTable({ schema: PG ? f.schema : null, name: f.table }, 'relations') },
            h('td', { class: 'mono' }, (PG && f.schema && f.schema !== 'public' ? f.schema + '.' : '') + f.table),
            h('td', { class: 'mono' }, `${f.columns.join(', ')} → ${f.ref_columns.join(', ')}`),
            h('td', { class: 'mono muted' }, f.name), h('td', null, ruleBadge(f.on_delete)))))))
          : h('div', { class: 'empty small' }, 'No other table references this one.')),
      h('div', { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Check constraints'), editable ? btn('Add check', () => checkDialog(st), 'sm', 'plus') : null),
        s.checks.length ? h('table', { class: 'list' }, h('tbody', null, s.checks.map((c) => {
          // MariaDB stores a JSON column as LONGTEXT plus a json_valid() check named after the column
          const jsonGuard = !PG && s.columns.some((col) => col.name === c.name && typeName(col) === 'json')
            && c.clause.replace(/\s+/g, '').toLowerCase() === `json_valid(\`${c.name}\`)`.toLowerCase();
          return h('tr', null,
            h('td', { class: 'mono', style: 'width:30%' }, c.name, jsonGuard ? h('span', { class: 'badge', style: 'margin-left:8px', title: 'MariaDB adds this check to every JSON column' }, 'json column') : null),
            h('td', { class: 'mono' }, c.clause),
            h('td', { class: 'actions' }, editable && !jsonGuard ? iconBtn('trash', 'Drop check', () => ddl({ action: 'drop_check', table: st.table, name: c.name }, { title: `Drop the check ${c.name}?`, confirm: 'Drop', danger: true }), 'ghost sm') : null));
        })))
          : h('div', { class: 'empty small' }, 'No check constraints.')),
      s.triggers.length ? h('div', { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Triggers')),
        h('div', { class: 'stack', style: 'padding:12px;gap:10px' }, s.triggers.map((x) => h('details', null,
          h('summary', { style: 'cursor:pointer' }, h('span', { class: 'mono' }, x.name), ' ', h('span', { class: 'badge' }, [x.timing, x.event].filter(Boolean).join(' ')), x.enabled === false ? h('span', { class: 'badge warn', style: 'margin-left:6px' }, 'disabled') : null),
          h('div', { class: 'card', style: 'margin-top:8px' }, codeBlock(x.definition || '')))))) : null,
      s.partitions && s.partitions.length ? h('div', { class: 'card' },
        h('div', { class: 'card-head' }, h('h3', null, 'Partitions'), h('span', { class: 'badge' }, String(s.partitions.length))),
        h('table', { class: 'list' }, h('tbody', null, s.partitions.map((p) => h('tr', { class: 'click', onclick: () => openTable({ schema: st.table.schema, name: p.name }) },
          h('td', { class: 'mono' }, p.name), h('td', { class: 'mono muted' }, p.bound || '')))))) : null,
    );
  }

  async function fkDialog(st, s) {
    const tables = (S.ov?.tables || []).filter((t) => ['table', 'partitioned'].includes(t.type));
    const a = { name: '', columns: [s.columns[0]?.name], ref: tables.find((t) => sameTable(t, st.table)) || tables[0], refCols: [], on_delete: '', on_update: '' };
    let refColumns = [];
    const form = h('div', { class: 'stack' });
    const preview = h('div');
    const saveBtn = btn('Add foreign key', () => save(), 'primary');
    const m = modal({ title: `Add a foreign key to ${tlabel(st.table)}`, body: [form, preview], foot: [btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'), h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), saveBtn], size: 'mid', sticky: true });
    const payload = () => ({
      action: 'add_fk', table: st.table, name: a.name.trim(), columns: a.columns.filter(Boolean),
      ref_table: a.ref ? { schema: a.ref.schema ?? null, name: a.ref.name } : null, ref_columns: a.refCols.filter(Boolean), on_delete: a.on_delete, on_update: a.on_update,
    });
    async function showSql() {
      try {
        fill(preview, h('div', { class: 'card' }, codeBlock((await api('ddl', { ...payload(), dry: true })).sql.join(';\n') + ';')));
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
        const rs = sameTable(a.ref, st.table) ? s : await api('structure', { table: { schema: a.ref.schema ?? null, name: a.ref.name } });
        refColumns = rs.columns.map((c) => c.name);
        const pk = rs.key.mode === 'pk' ? rs.key.cols : [];
        a.refCols = a.columns.map((_, k) => pk[k] || refColumns[k] || refColumns[0]);
      } catch (e) {
        fail(e);
      }
    }
    const rules = [['', 'Default (no action)'], ['cascade', 'Cascade'], ['set null', 'Set NULL'], ['restrict', 'Restrict'], ['set default', 'Set default']];
    function draw() {
      fill(form, 
        field('Name', input({ value: a.name, placeholder: 'Generated when empty', oninput: (e) => (a.name = e.target.value) })),
        field('References table', select(tables.map((t) => [tkey(t), tlabel(t)]), a.ref ? tkey(a.ref) : '', { onchange: async (e) => {
          a.ref = tables.find((t) => tkey(t) === e.target.value);
          await loadRef();
          draw();
        } })),
        field('Columns', h('div', { class: 'stack', style: 'gap:8px' }, a.columns.map((col, k) => h('div', { class: 'row' },
          select(s.columns.map((c) => c.name), col, { onchange: (e) => (a.columns[k] = e.target.value) }),
          h('span', { class: 'muted' }, '→'),
          select(refColumns, a.refCols[k], { onchange: (e) => (a.refCols[k] = e.target.value) }),
          a.columns.length > 1 ? iconBtn('x', 'Remove', () => {
            a.columns.splice(k, 1);
            a.refCols.splice(k, 1);
            draw();
          }, 'ghost sm') : null)),
        h('div', null, btn('Add a column pair', () => {
          a.columns.push(s.columns[0].name);
          a.refCols.push(refColumns[0]);
          draw();
        }, 'ghost sm', 'plus')))),
        h('div', { class: 'grid2' },
          field('When the referenced row is deleted', select(rules, a.on_delete, { onchange: (e) => (a.on_delete = e.target.value) })),
          field('When the referenced key changes', select(rules, a.on_update, { onchange: (e) => (a.on_update = e.target.value) }))),
      );
    }
    put(form, h('div', { class: 'empty' }, spinner()));
    await loadRef();
    draw();
  }

  function checkDialog(st) {
    const name = input({ placeholder: 'Generated when empty' });
    const clause = input({ class: 'input mono', placeholder: 'price >= 0' });
    const preview = h('div');
    const payload = () => ({ action: 'add_check', table: st.table, name: name.value.trim(), clause: clause.value });
    const showError = (e) => fill(preview, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
    async function showSql() {
      try {
        fill(preview, h('div', { class: 'card' }, codeBlock((await api('ddl', { ...payload(), dry: true })).sql.join(';\n') + ';')));
      } catch (e) {
        showError(e);
      }
    }
    const save = btn('Add check', () => busy(save, async () => {
      try {
        await api('ddl', payload());
        toast('Check added');
        m.close();
        afterDdl({ table: st.table });
      } catch (e) {
        showError(e);
      }
    }), 'primary');
    const m = modal({
      title: `Add a check constraint to ${tlabel(st.table)}`,
      body: [field('Name', name), field('Condition every row must meet', clause, 'One SQL expression. Existing rows must already meet it.'), preview],
      foot: [btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'), h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), save],
    });
  }

  function settingsTab(st, s, body) {
    const t = s.table;
    const isTable = ['table', 'partitioned'].includes(t.type);
    const kv = (rows) => h('table', { class: 'list' }, h('tbody', null, rows.filter((r) => r[1] !== null && r[1] !== undefined && r[1] !== '').map(([k, v]) => h('tr', null, h('td', { class: 'muted', style: 'width:40%' }, k), h('td', { class: 'mono' }, String(v))))));
    const infoRows = PG ? [
      ['Type', t.type], ['Owner', t.owner], ['Estimated rows', num(t.rows)], ['Live / dead rows', t.live_rows !== null ? `${num(t.live_rows)} / ${num(t.dead_rows)}` : null],
      ['Total size', bytes(t.size)], ['Table', bytes(t.data)], ['Indexes', bytes(t.index)], ['Row level security', t.rls ? 'on' : 'off'],
      ['Partition of', t.partition_of], ['Partition key', t.partition_key], ['Partition bound', t.partition_bound], ['Last vacuum', t.last_vacuum], ['Last analyze', t.last_analyze],
    ] : [
      ['Type', t.type], ['Engine', t.engine], ['Row format', t.row_format], ['Estimated rows', num(t.rows)], ['Average row', bytes(t.avg_row)], ['Data', bytes(t.data)],
      ['Indexes', bytes(t.index)], ['Free', bytes(t.free)], ['Next auto-increment', t.auto_increment !== null ? num(t.auto_increment) : null], ['Collation', t.collation],
      ['Created', t.created], ['Updated', t.updated],
    ];
    const cards = [];
    cards.push(h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h3', null, 'Information')), kv(infoRows)));

    const rename = input({ value: t.name });
    const renameBtn = btn('Rename', () => {
      const to = rename.value.trim();
      if (!to || to === t.name) return;
      ddl({ action: 'rename_table', table: st.table, to }, { title: `Rename ${t.name} to ${to}?`, message: 'Queries and code that use the old name stop working.' }).then((r) => {
        if (r) setTimeout(() => openTable({ schema: st.table.schema, name: to }, 'settings'), 300);
      });
    }, 'sm');
    const comment = h('textarea', { class: 'input', rows: 2, value: t.comment || '' });
    const commentBtn = btn('Save comment', () => busy(commentBtn, async () => {
      try {
        await api('ddl', { action: 'table_options', table: st.table, comment: comment.value });
        toast('Comment saved');
        afterDdl({ table: st.table });
      } catch (e) {
        fail(e);
      }
    }), 'sm');
    const general = [field('Name', h('div', { class: 'row' }, rename, renameBtn)), field('Comment', comment), h('div', null, commentBtn)];
    if (PG && S.ov && S.ov.schemas.length > 1) {
      const sch = select(S.ov.schemas.filter((x) => x !== st.table.schema), null);
      general.push(field('Move to schema', h('div', { class: 'row' }, sch, btn('Move', () => ddl({ action: 'move_table', table: st.table, to: sch.value }, { title: `Move ${t.name} to ${sch.value}?` }).then((r) => {
        if (r) setTimeout(() => openTable({ schema: sch.value, name: t.name }, 'settings'), 300);
      }), 'sm'))));
    }
    cards.push(h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h3', null, 'General')), h('div', { class: 'card-body stack' }, general)));

    if (!PG && isTable) {
      const engines = ['InnoDB', 'MyISAM', ...(MARIA ? ['Aria'] : []), 'MEMORY', 'ARCHIVE'];
      const eng = select(engines.includes(t.engine) ? engines : [t.engine, ...engines], t.engine);
      const ai = input({ value: t.auto_increment ?? '', inputmode: 'numeric' });
      const coll = input({ class: 'input mono', value: t.collation || '' });
      let convert = false;
      const optsBtn = btn('Save options', () => {
        const a = { action: 'table_options', table: st.table };
        if (eng.value !== t.engine) a.engine = eng.value;
        if (ai.value !== String(t.auto_increment ?? '') && ai.value !== '') a.auto_increment = ai.value;
        if (coll.value && coll.value !== t.collation) {
          a.collation = coll.value;
          a.convert = convert;
        }
        if (Object.keys(a).length === 2) return toast('Nothing to change.');
        ddl(a, { title: 'Change the table options?', message: a.engine || a.convert ? 'Changing the engine or converting the character set rebuilds the table; it is locked while that runs.' : null });
      }, 'sm');
      cards.push(h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h3', null, 'Options')), h('div', { class: 'card-body stack' },
        h('div', { class: 'grid2' }, field('Engine', eng), field('Next auto-increment value', ai)),
        field('Collation', coll), check('Convert the existing text to this collation (CONVERT TO CHARACTER SET)', false, (v) => (convert = v)), h('div', null, optsBtn))));
    }

    const tasks = PG
      ? [['vacuum', 'Vacuum', 'Reclaims space from dead rows and refreshes statistics.'], ['analyze', 'Analyze', 'Refreshes the planner statistics.'], ['reindex', 'Reindex', 'Rebuilds every index of the table.'],
        ['vacuum_full', 'Vacuum full', 'Rewrites the whole table to return space to the disk. Locks the table.'], ...(t.type === 'matview' ? [['refresh', 'Refresh', 'Recomputes the materialized view.']] : [])]
      : [['analyze', 'Analyze', 'Refreshes index statistics.'], ['optimize', 'Optimize', 'Rebuilds the table to reclaim space. Locks the table.'], ['check', 'Check', 'Checks the table for errors.'],
        ['repair', 'Repair', 'Repairs a damaged MyISAM or Aria table.'], ['checksum', 'Checksum', 'Computes a checksum of the data.']];
    cards.push(h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h3', null, 'Maintenance')), h('table', { class: 'list' }, h('tbody', null, tasks.map(([task, label, desc]) => {
      const b = btn(label, () => busy(b, async () => {
        try {
          const r = await api('ddl', { action: 'maintenance', table: st.table, task });
          const sets = r.results.flatMap((x) => x.sets);
          modal({ title: `${label} · ${t.name}`, body: sets.length ? sets.map((set) => resultSet(set)) : h('p', { class: 'lead' }, 'Done.'), size: 'mid' });
          st.structure = null;
        } catch (e) {
          fail(e);
        }
      }), 'sm');
      return h('tr', null, h('td', null, h('b', null, label), h('div', { class: 'small muted' }, desc)), h('td', { class: 'actions' }, b));
    })))));

    if (isTable) {
      const dupName = input({ value: t.name + '_copy' });
      let withData = true;
      cards.push(h('div', { class: 'card' }, h('div', { class: 'card-head' }, h('h3', null, 'Duplicate')), h('div', { class: 'card-body stack' },
        field('New table name', dupName), check('Copy the rows too', true, (v) => (withData = v)),
        h('div', null, btn('Duplicate', () => ddl({ action: 'duplicate_table', table: st.table, to: dupName.value.trim(), data: withData }, { title: `Create ${dupName.value.trim()} as a copy?` }).then((r) => {
          if (r) toast('Table duplicated');
        }), 'sm', 'copy')))));
    }

    cards.push(h('div', { class: 'card', style: 'grid-column:1/-1' },
      h('div', { class: 'card-head' }, h('h3', null, PG ? 'Definition' : 'CREATE statement'), iconBtn('copy', 'Copy', () => copyText(s.ddl), 'ghost sm'), iconBtn('download', 'Download', () => download(s.ddl + '\n', `${t.name}.sql`, 'application/sql'), 'ghost sm')),
      codeBlock(s.ddl || '')));

    const danger = [];
    if (isTable) {
      let opt1 = false, opt2 = false;
      danger.push(h('tr', null, h('td', null, h('b', null, 'Empty the table'), h('div', { class: 'small muted' }, 'Deletes every row at once (TRUNCATE).'),
        h('div', { class: 'row', style: 'gap:16px;margin-top:6px;flex-wrap:wrap' },
          PG ? check('Restart identity sequences', false, (v) => (opt1 = v)) : check('Ignore foreign key checks', false, (v) => (opt1 = v)),
          PG ? check('Also empty tables that reference it (CASCADE)', false, (v) => (opt2 = v)) : null)),
      h('td', { class: 'actions' }, btn('Empty', () => ddl({ action: 'truncate_table', table: st.table, ...(PG ? { restart_identity: opt1, cascade: opt2 } : { ignore_fk: opt1 }) }, {
        title: `Empty ${t.name}?`, message: 'Every row is deleted. This cannot be undone.', confirm: 'Empty table', danger: true, typed: t.name,
      }).then((r) => r && toast('Table emptied')), 'danger sm'))));
    }
    let cascade = false;
    danger.push(h('tr', null, h('td', null, h('b', null, `Drop the ${t.type}`), h('div', { class: 'small muted' }, 'Deletes it with all its rows, indexes and triggers.'),
      PG ? h('div', { style: 'margin-top:6px' }, check('Also drop objects that depend on it (CASCADE)', false, (v) => (cascade = v))) : null),
    h('td', { class: 'actions' }, btn('Drop', () => ddl({ action: 'drop_table', table: st.table, ...(PG ? { cascade } : {}) }, {
      title: `Drop ${t.name}?`, message: 'It is deleted with all its data. This cannot be undone.', confirm: `Drop ${t.type}`, danger: true, typed: t.name,
    }).then((r) => {
      if (r) {
        toast(`${t.name} dropped`);
        S.tables.delete(tkey(st.table));
        go({ view: 'overview' });
      }
    }), 'danger sm'))));
    cards.push(h('div', { class: 'card', style: 'grid-column:1/-1;border-color:oklch(0.577 0.245 27.325 / 40%)' },
      h('div', { class: 'card-head' }, h('h3', { style: 'color:var(--destructive)' }, 'Danger zone')), h('table', { class: 'list' }, h('tbody', null, danger))));
    put(body, h('div', { class: 'grid2', style: 'align-items:start' }, cards));
  }

  // ── create table ────────────────────────────────────────────────────────────────────────
  function createTableDialog() {
    const schemas = PG && S.ov ? S.ov.schemas : [];
    const t = { name: '', schema: PG ? (S.schema && S.schema !== '*' ? S.schema : 'public') : null, comment: '', engine: '', collation: '' };
    const idCol = PG
      ? { name: 'id', type: 'bigint', nullable: false, pk: true, identity: 'by_default', auto: false, def: 'none', value: '', comment: '' }
      : { name: 'id', type: 'bigint unsigned', nullable: false, pk: true, identity: '', auto: true, def: 'none', value: '', comment: '' };
    const cols = [idCol, { name: '', type: PG ? 'text' : 'varchar(255)', nullable: true, pk: false, identity: '', auto: false, def: 'none', value: '', comment: '' }];
    let timestamps = true;
    const form = h('div', { class: 'stack' });
    const preview = h('div');
    const saveBtn = btn('Create table', () => save(), 'primary');
    const m = modal({ title: 'New table', body: [form, preview], foot: [btn('Preview SQL', (e) => busy(e.currentTarget, showSql), 'ghost', 'code'), h('span', { class: 'spacer' }), btn('Cancel', () => m.close()), saveBtn], size: 'wide', sticky: true });

    function payload() {
      const columns = cols.filter((c) => c.name.trim()).map((c) => {
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
        if (!columns.some((c) => c.name === 'created_at')) columns.push({ name: 'created_at', type: ts, nullable: false, default: { kind: 'expr', value: now } });
        if (!columns.some((c) => c.name === 'updated_at')) columns.push({ name: 'updated_at', type: ts, nullable: false, default: { kind: 'expr', value: now }, ...(PG ? {} : { on_update: true }) });
      }
      const a = { action: 'create_table', table: PG ? { schema: t.schema, name: t.name.trim() } : { schema: null, name: t.name.trim() }, columns, primary: cols.filter((c) => c.pk && c.name.trim()).map((c) => c.name.trim()) };
      if (t.comment) a.comment = t.comment;
      if (!PG && t.engine) a.engine = t.engine;
      if (!PG && t.collation) a.collation = t.collation;
      return a;
    }
    async function showSql() {
      try {
        fill(preview, h('div', { class: 'card' }, codeBlock((await api('ddl', { ...payload(), dry: true })).sql.join(';\n') + ';')));
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
        const def = select([['none', '—'], ['null', 'NULL'], ['literal', 'Value'], ['expr', 'Expression']], c.def, { class: 'select sm', onchange: (e) => {
          c.def = e.target.value;
          draw();
        } });
        return h('tr', null,
          h('td', null, input({ class: 'input sm mono', value: c.name, placeholder: 'name', style: 'min-width:120px', oninput: (e) => (c.name = e.target.value) })),
          h('td', null, input({ class: 'input sm mono', value: c.type, list: typeList(), style: 'min-width:150px', oninput: (e) => (c.type = e.target.value) })),
          h('td', { style: 'text-align:center' }, h('input', { type: 'checkbox', checked: c.pk, title: 'Primary key', onchange: (e) => {
            c.pk = e.target.checked;
            draw();
          } })),
          h('td', { style: 'text-align:center' }, h('input', { type: 'checkbox', checked: c.nullable && !c.pk, disabled: c.pk, title: 'Allow NULL', onchange: (e) => (c.nullable = e.target.checked) })),
          h('td', null, PG
            ? select([['', '—'], ['by_default', 'by default'], ['always', 'always']], c.identity, { class: 'select sm', title: 'Identity', onchange: (e) => (c.identity = e.target.value) })
            : h('input', { type: 'checkbox', checked: c.auto, title: 'Auto increment', onchange: (e) => (c.auto = e.target.checked) })),
          h('td', null, h('div', { class: 'row', style: 'gap:4px' }, def, c.def === 'literal' || c.def === 'expr'
            ? input({ class: 'input sm' + (c.def === 'expr' ? ' mono' : ''), value: c.value, list: c.def === 'expr' ? 'vdbm-funcs' : null, style: 'width:140px', oninput: (e) => (c.value = e.target.value) }) : null)),
          h('td', null, input({ class: 'input sm', value: c.comment, placeholder: 'comment', style: 'min-width:120px', oninput: (e) => (c.comment = e.target.value) })),
          h('td', null, iconBtn('x', 'Remove column', () => {
            cols.splice(k, 1);
            draw();
          }, 'ghost sm')));
      });
      fill(form, 
        h('div', { class: PG ? 'grid3' : 'grid2' },
          PG ? field('Schema', select(schemas.length ? schemas : ['public'], t.schema, { onchange: (e) => (t.schema = e.target.value) })) : null,
          field('Table name', input({ value: t.name, placeholder: 'e.g. orders', oninput: (e) => (t.name = e.target.value), autofocus: true })),
          field('Comment', input({ value: t.comment, oninput: (e) => (t.comment = e.target.value) }))),
        !PG ? h('div', { class: 'grid2' },
          field('Engine', select([['', 'Server default (InnoDB)'], 'InnoDB', 'MyISAM', ...(MARIA ? ['Aria'] : []), 'MEMORY', 'ARCHIVE'], t.engine, { onchange: (e) => (t.engine = e.target.value) })),
          field('Collation', input({ class: 'input mono', value: t.collation, placeholder: 'Database default', oninput: (e) => (t.collation = e.target.value) }))) : null,
        h('div', { class: 'grid-wrap', style: 'max-height:none' }, h('table', { class: 'list' },
          h('thead', null, h('tr', null, h('th', null, 'Column'), h('th', null, 'Type'), h('th', null, 'Key'), h('th', null, 'Null'), h('th', null, PG ? 'Identity' : 'Auto'), h('th', null, 'Default'), h('th', null, 'Comment'), h('th'))),
          h('tbody', null, rows))),
        h('div', { class: 'row', style: 'flex-wrap:wrap' },
          btn('Add column', () => {
            cols.push({ name: '', type: PG ? 'text' : 'varchar(255)', nullable: true, pk: false, identity: '', auto: false, def: 'none', value: '', comment: '' });
            draw();
            form.querySelectorAll('tbody tr:last-child input')[0]?.focus();
          }, 'sm', 'plus'),
          h('span', { class: 'grow' }),
          toggle('Add created_at and updated_at', timestamps, (v) => (timestamps = v))),
      );
    }
    draw();
  }

  // ── SQL console ─────────────────────────────────────────────────────────────────────────
  const SQL_KEY = STORE + ':sql';
  const HIST_KEY = STORE + ':history';
  const sqlDraft = (text) => ls.set(SQL_KEY, text);
  const OPT_KEY = STORE + ':sqlopts';

  function renderSql() {
    const opts = Object.assign({ readonly: false, tx: false, max_rows: 1000, timeout: 30 }, ls.get(OPT_KEY, {}));
    const saveOpts = () => ls.set(OPT_KEY, opts);
    const ta = h('textarea', { spellcheck: false, 'aria-label': 'SQL', autocapitalize: 'off', autocomplete: 'off', wrap: 'off' });
    ta.value = ls.get(SQL_KEY, `SELECT *\nFROM ${S.ov && S.ov.tables[0] ? tableSql(S.ov.tables[0]) : 'my_table'}\nLIMIT 100;`);
    const pre = h('pre', { 'aria-hidden': 'true' });
    const gutter = h('div', { class: 'gutter', 'aria-hidden': 'true' });
    let errLine = null;
    // the line of the last error, marked behind the text and scrolled into view
    const band = h('div', { class: 'err-band', hidden: true });
    const lineHeight = () => parseFloat(getComputedStyle(ta).lineHeight) || 20;
    const placeBand = () => {
      band.hidden = errLine === null;
      if (errLine === null) return;
      band.style.top = `${12 + (errLine - 1) * lineHeight() - ta.scrollTop}px`;
      band.style.height = `${lineHeight()}px`;
    };
    const paint = () => {
      highlight(ta.value + '\n', pre);
      const lines = ta.value.split('\n').length;
      if (gutter.childElementCount !== lines || errLine !== null) {
        fill(gutter, ...Array.from({ length: lines }, (_, i) => h('div', { class: i + 1 === errLine ? 'err' : '' }, String(i + 1))));
      }
      placeBand();
    };
    const sync = () => {
      pre.scrollTop = ta.scrollTop;
      pre.scrollLeft = ta.scrollLeft;
      gutter.scrollTop = ta.scrollTop;
      placeBand();
    };
    const revealLine = (n) => {
      const top = 12 + (n - 1) * lineHeight();
      if (top < ta.scrollTop || top + lineHeight() > ta.scrollTop + ta.clientHeight) ta.scrollTop = Math.max(0, top - ta.clientHeight / 2);
      sync();
    };
    const persist = debounce(() => sqlDraft(ta.value), 400);
    ta.addEventListener('input', () => {
      if (errLine !== null) errLine = null;
      paint();
      sync();
      persist();
    });
    ta.addEventListener('scroll', sync);
    ta.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        run();
      } else if (e.key === 'Tab' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        const s = ta.selectionStart, en = ta.selectionEnd;
        if (!e.shiftKey && s === en) {
          ta.setRangeText('  ', s, en, 'end');
        } else {
          const start = ta.value.lastIndexOf('\n', s - 1) + 1;
          const block = ta.value.slice(start, en);
          const changed = e.shiftKey ? block.replace(/^ {1,2}/gm, '') : block.replace(/^/gm, '  ');
          ta.setRangeText(changed, start, en, 'select');
        }
        ta.dispatchEvent(new Event('input'));
      }
    });
    const runBtn = btn('Run', () => run(), 'primary', 'play', { title: 'Run (Ctrl+Enter). With a selection, runs only the selection.' });
    const results = h('div', { class: 'results' });
    const status = h('div', { class: 'row small muted', style: 'min-height:20px' });
    const maxSel = select([100, 500, 1000, 5000, 10000].map((n) => [String(n), `${num(n)} rows`]), String(opts.max_rows), { class: 'select sm', style: 'width:auto', title: 'Rows shown per result', onchange: (e) => {
      opts.max_rows = parseInt(e.target.value, 10);
      saveOpts();
    } });
    const toSel = select([5, 10, 30, 50].map((n) => [String(n), `${n} s`]), String(opts.timeout), { class: 'select sm', style: 'width:auto', title: 'Statement timeout', onchange: (e) => {
      opts.timeout = parseInt(e.target.value, 10);
      saveOpts();
    } });
    const head = h('div', { class: 'row', style: 'flex-wrap:wrap' },
      h('h1', { style: 'font-size:18px;margin:0 8px 0 0;font-weight:600' }, 'SQL console'),
      toggle('Read-only', opts.readonly, (v) => {
        opts.readonly = v;
        saveOpts();
      }),
      toggle('Transaction', opts.tx, (v) => {
        opts.tx = v;
        saveOpts();
      }),
      h('span', { class: 'small muted', title: 'Transaction: everything is rolled back when a statement fails' }, ''),
      maxSel, toSel,
      h('span', { class: 'grow' }),
      btn('History', (e) => historyPopover(e.currentTarget, ta), 'sm ghost', 'history'),
      iconBtn('download', 'Download the script', () => download(ta.value, `${BOOT.database.name}.sql`, 'application/sql'), 'sm ghost'),
      runBtn);
    const editor = h('div', { class: 'editor' }, gutter, h('div', { class: 'area' }, band, pre, ta));
    const page = h('div', { class: 'sql-page' }, head, editor, status, results);
    fill(mainEl, page);
    paint();
    ta.focus();

    async function run() {
      const sel = ta.selectionStart !== ta.selectionEnd ? [ta.selectionStart, ta.selectionEnd] : null;
      const text = sel ? ta.value.slice(sel[0], sel[1]) : ta.value;
      if (!text.trim()) return;
      const lineOffset = sel ? ta.value.slice(0, sel[0]).split('\n').length - 1 : 0;
      errLine = null;
      paint();
      fill(status, spinner(), 'Running…');
      runBtn.disabled = true;
      const t0 = performance.now();
      try {
        const r = await api('sql', { sql: text, readonly: opts.readonly, tx: opts.tx, max_rows: opts.max_rows, timeout: opts.timeout });
        const took = performance.now() - t0;
        pushHistory(text, !r.error);
        showResults(r, lineOffset, took);
        if (!opts.readonly && r.results.some((x) => !['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'WITH', 'VALUES', 'TABLE'].includes(String(x.kind).toUpperCase()))) {
          invalidateTables();
          loadOverview().then(() => fill(sideEl, ...sideBar()));
        }
      } catch (e) {
        fill(status);
        if (e.info && e.info.line) {
          errLine = e.info.line + lineOffset;
          paint();
          revealLine(errLine);
        }
        fill(results, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' }, e.message)));
      } finally {
        runBtn.disabled = false;
      }
    }

    function showResults(r, lineOffset, took) {
      fill(results);
      const total = r.results.reduce((s, x) => s + (x.ms || 0), 0);
      fill(status, 
        r.error ? icon('alert') : icon('check'),
        `${r.ran} of ${plural(r.statements, 'statement')} ran · ${msf(total)} in the database · ${msf(took)} in all`,
        r.summary ? h('span', { class: 'badge' }, 'summary only') : null,
        r.rolled_back ? h('span', { class: 'badge warn' }, 'rolled back') : null);
      if (r.error) {
        const e = r.error;
        if (e.line) {
          const line = e.line + lineOffset;
          errLine = line;
          paint();
          // after the results are in place, so the editor has its final height
          requestAnimationFrame(() => revealLine(line));
        }
        const lines = [e.message];
        if (e.detail) lines.push('Detail: ' + e.detail);
        if (e.hint) lines.push('Hint: ' + e.hint);
        put(results, h('div', { class: 'alert err' }, icon('alert'), h('div', { class: 'a-body' },
          h('b', null, `Statement ${e.statement + 1}${e.line ? `, line ${e.line + lineOffset}` : ''}${e.kind === 'timeout' ? ' timed out' : ' failed'}`),
          e.sqlstate || e.code ? h('span', { class: 'mono small muted' }, `  ${[e.code, e.sqlstate].filter(Boolean).join(' · ')}`) : null,
          '\n' + lines.join('\n'),
          r.rolled_back ? '\nThe transaction was rolled back: no statement of this run changed anything.'
            : r.ran && !opts.readonly ? (r.ran === 1 ? '\nThe statement before it ran, and its changes stay applied.' : `\nThe ${r.ran} statements before it ran, and their changes stay applied.`) : '')));
      }
      const shown = r.results.length > 60 ? r.results.filter((x) => x.sets.length).slice(-30) : r.results;
      if (shown.length < r.results.length) put(results, h('div', { class: 'alert info' }, icon('info'), h('div', { class: 'a-body' }, `${r.results.length} statements ran; showing the last result sets only.`)));
      for (const x of shown) {
        const meta = [];
        if (x.affected !== null && x.affected !== undefined && x.affected >= 0 && !x.sets.length) meta.push(`${plural(x.affected, 'row')} affected`);
        if (x.insert_id && x.insert_id !== '0' && x.insert_id !== 0) meta.push(`id ${x.insert_id}`);
        const card = h('div', { class: 'result' },
          h('div', { class: 'result-head' }, h('span', { class: 'badge' }, `#${x.i + 1}`), h('code', { title: x.sql }, x.sql), h('span', null, meta.join(' · ')), h('span', { class: 'nowrap' }, msf(x.ms))));
        for (const set of x.sets) put(card, resultSet(set, opts.max_rows));
        if (!x.sets.length && !meta.length) put(card, h('div', { class: 'result-msg muted' }, 'Done.'));
        if (x.warnings && x.warnings.length) {
          put(card, h('div', { class: 'result-msg' }, x.warnings.map((w) => h('div', { class: 'small', style: 'color:oklch(0.65 0.15 70)' }, `${w.level || 'Note'}${w.code ? ' ' + w.code : ''}: ${w.message}`))));
        }
        put(results, card);
      }
    }
  }

  /** A result set as a grid, with copy and download. */
  function resultSet(set, limit) {
    const box = h('div');
    const tools = h('div', { class: 'result-head', style: 'border-top:1px solid var(--border)' },
      h('span', null, set.more ? `First ${num(set.count)} rows — there are more${limit ? ' (raise the row limit or add LIMIT)' : ''}` : plural(set.count, 'row')),
      h('span', { class: 'grow' }),
      iconBtn('copy', 'Copy as tab-separated text', () => copyText(setToCsv(set.columns, set.rows, '\t')), 'ghost sm'),
      iconBtn('download', 'Download as CSV', () => download('﻿' + setToCsv(set.columns, set.rows), 'result.csv', 'text/csv;charset=utf-8'), 'ghost sm'));
    if (!set.columns.length) return box;
    const wrap = h('div', { class: 'grid-wrap' });
    const tbody = h('tbody');
    const table = h('table', { class: 'grid' }, h('thead', null, h('tr', null, set.columns.map((c) => h('th', null, h('div', { class: 'th', style: 'cursor:default' }, h('span', { class: 't' }, c)))))), tbody);
    let drawn = 0;
    const more = () => {
      const end = Math.min(set.rows.length, drawn + 300);
      for (; drawn < end; drawn++) {
        put(tbody, h('tr', null, set.rows[drawn].map((v) => {
          const td = h('td', { class: typeof v === 'string' && /^-?\d+(\.\d+)?$/.test(v) ? 'num' : '' }, cellView(v));
          if (typeof v === 'string' && v.length > 40) td.title = v.length > 1000 ? v.slice(0, 1000) + '…' : v;
          return td;
        })));
      }
    };
    more();
    wrap.addEventListener('scroll', () => {
      if (drawn < set.rows.length && wrap.scrollTop + wrap.clientHeight > wrap.scrollHeight - 200) more();
    });
    put(wrap, table);
    put(box, wrap, tools);
    return box;
  }

  function pushHistory(sql, ok) {
    const list = ls.get(HIST_KEY, []).filter((x) => x.sql !== sql);
    list.unshift({ sql: sql.length > 20000 ? sql.slice(0, 20000) : sql, at: Date.now(), ok });
    ls.set(HIST_KEY, list.slice(0, 50));
  }
  function historyPopover(anchor, ta) {
    const list = ls.get(HIST_KEY, []);
    popover(anchor, list.length ? [
      h('div', { class: 'row', style: 'padding:4px 8px' }, h('span', { class: 'small muted grow' }, 'Recent queries (kept in this browser)'),
        h('button', { type: 'button', class: 'btn ghost sm', onclick: () => {
          ls.set(HIST_KEY, []);
          closePop();
        } }, 'Clear')),
      ...list.map((x) => h('button', { type: 'button', class: 'history-item', title: x.sql.slice(0, 2000), onclick: () => {
        ta.value = x.sql;
        ta.dispatchEvent(new Event('input'));
        closePop();
        ta.focus();
      } }, h('code', null, x.sql.replace(/\s+/g, ' ').slice(0, 200)), h('span', { class: 'small muted' }, `${new Date(x.at).toLocaleString()}${x.ok ? '' : ' · failed'}`))),
    ] : h('div', { class: 'small muted', style: 'padding:12px' }, 'Queries you run appear here.'), { align: 'end', width: 460 });
  }

  // ── processes ───────────────────────────────────────────────────────────────────────────
  function renderMonitor() {
    let auto = ls.get(STORE + ':monauto', true);
    let hideIdle = ls.get(STORE + ':monidle', true);
    let last = null;
    const statsEl = h('div', { class: 'stats' });
    const listEl = h('div', { class: 'card' });
    const errEl = h('div');
    const page = h('div', { class: 'page' },
      h('div', { class: 'page-head' }, h('div', { class: 'grow' }, h('h1', null, 'Processes'), h('div', { class: 'sub' }, `Connections and queries running on the ${ENGINE} server`)),
        h('div', { class: 'row' },
          toggle('Hide idle', hideIdle, (v) => {
            hideIdle = v;
            ls.set(STORE + ':monidle', v);
            draw();
          }),
          toggle('Refresh every 3 s', auto, (v) => {
            auto = v;
            ls.set(STORE + ':monauto', v);
            schedule();
          }),
          btn('Refresh', (e) => busy(e.currentTarget, load), '', 'refresh'))),
      errEl, statsEl, listEl);
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
      if (auto) S.monitorTimer = setInterval(() => !document.hidden && S.route.view === 'monitor' && load(), 3000);
    }
    function draw() {
      if (!last) {
        fill(listEl, h('div', { class: 'empty' }, spinner()));
        return;
      }
      const s = last.stats;
      fill(statsEl, 
        stat('Uptime', duration(s.uptime)),
        stat('Connections', `${num(s.connections)}${s.max_connections ? ' / ' + num(s.max_connections) : ''}`, s.max_used_connections !== null && s.max_used_connections !== undefined ? `peak ${num(s.max_used_connections)}` : null),
        stat('Running now', num(s.running)),
        stat('Queries', compact(s.queries), s.slow_queries !== null && s.slow_queries !== undefined ? `${num(s.slow_queries)} slow` : null),
        stat('Cache hit rate', s.cache_hit !== null && s.cache_hit !== undefined ? (s.cache_hit * 100).toFixed(2) + '%' : '—', s.buffer_pool ? `buffer ${bytes(s.buffer_pool)}` : null),
        stat('Commits', compact(s.commits), s.rollbacks !== null && s.rollbacks !== undefined ? `${compact(s.rollbacks)} rollbacks` : null),
        stat(PG ? 'Deadlocks' : 'Lock waits', num(PG ? s.deadlocks : s.lock_waits)),
        s.received !== null && s.received !== undefined ? stat('Traffic', `${bytes(s.received)} in`, `${bytes(s.sent)} out`) : null);
      const idle = (p) => /^(sleep|idle)$/i.test(String(p.command || p.state || '')) || /^idle$/i.test(String(p.state || ''));
      const procs = last.processes.filter((p) => !hideIdle || !idle(p) || p.self);
      fill(listEl, 
        h('div', { class: 'card-head' }, h('h3', null, `${plural(procs.length, 'connection')}`), hideIdle ? h('span', { class: 'small muted' }, `${last.processes.length - procs.length} idle hidden`) : null),
        h('div', { style: 'overflow:auto' }, h('table', { class: 'list' },
          h('thead', null, h('tr', null, h('th', null, 'ID'), h('th', null, 'User'), h('th', null, PG ? 'Client' : 'Host'), h('th', null, 'Database'), h('th', null, 'State'), h('th', { class: 'num' }, 'Time'), h('th', null, 'Query'), h('th'))),
          h('tbody', null, procs.map((p) => h('tr', null,
            h('td', { class: 'mono' }, String(p.id)),
            h('td', null, p.user || ''),
            h('td', { class: 'muted small' }, [p.app, p.host].filter(Boolean).join(' · ')),
            h('td', null, p.db || ''),
            h('td', null, h('span', { class: 'badge' }, p.command || ''), p.state ? h('div', { class: 'small muted' }, p.state) : null),
            h('td', { class: 'num' }, p.time !== null ? duration(p.time) : ''),
            h('td', { class: 'mono small', style: 'max-width:440px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap', title: p.info || '' }, p.info || ''),
            h('td', { class: 'actions' }, p.self ? h('span', { class: 'badge solid' }, 'this page') : [
              p.info ? btn(PG ? 'Cancel' : 'Kill query', () => kill(p, false), 'sm') : null,
              btn(PG ? 'Terminate' : 'Kill', () => kill(p, true), 'danger sm'),
            ])))))));
    }
    async function kill(p, connection) {
      const ok = await confirmBox({
        title: connection ? `End connection ${p.id}?` : `Stop the query of connection ${p.id}?`,
        message: connection ? 'The connection is closed; an open transaction on it is rolled back.' : 'The running statement is cancelled; the connection stays open.',
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

  // ── start ───────────────────────────────────────────────────────────────────────────────
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.ctrlKey && !e.metaKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '') && !layers.length) {
      const s = sideEl.querySelector('.side-head input');
      if (s) {
        e.preventDefault();
        s.focus();
      }
    }
  });
  render();
  if (BOOT.database) {
    loadOverview().then(() => {
      renderShell();
      const r = S.route;
      if (r.view === 'overview' || r.view === 'table') render();
    });
  }
})();
