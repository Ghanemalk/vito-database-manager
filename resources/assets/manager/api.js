// Talking to the plugin's API (ManagerController::api()): one POST per operation, answered with { ok, data | error }.

import { BOOT, CSRF, EMBED } from './boot.js';
import { btn, h } from './ui/dom.js';
import { modal } from './ui/modals.js';

export class ApiError extends Error {
  constructor(info) {
    super(info.message || 'Something went wrong.');
    this.info = info;
    this.kind = info.kind || 'error';
  }
}

/** One request to the API. Errors come back as ApiError, with a message for people to read. */
async function post(body) {
  let res;
  try {
    res = await fetch(BOOT.api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': CSRF,
        'X-Requested-With': 'XMLHttpRequest',
      },
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
  if (res.status === 403)
    throw new ApiError({ kind: 'forbidden', message: 'You do not have permission to manage this database.' });
  if (res.status === 413)
    throw new ApiError({ kind: 'too_large', message: 'The request is too large for the server.' });
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

/** Runs one operation (see ManagerController::OPS) and returns its data; see the engine's handle() for each one. */
export async function api(op, args = {}) {
  let body = JSON.stringify({ op, args });
  if (op === 'sql' && body.length > 3500000) {
    const id = await stash(args.sql);
    body = JSON.stringify({ op, args: { ...args, sql: undefined, stash: id } });
  }
  return post(body);
}

let expiredShown = false;

/** Vito signed the user out: ask for a reload, once. */
function sessionExpired() {
  if (expiredShown) return;
  expiredShown = true;
  const m = modal({
    title: 'Session expired',
    body: h(
      'p',
      { class: 'lead' },
      'You were signed out of Vito. Reload the page to sign in again — nothing has been lost on the server.',
    ),
    foot: [
      h('span', { class: 'spacer' }),
      btn('Reload', () => (EMBED ? window.top : window).location.reload(), 'primary', 'refresh'),
    ],
    sticky: true,
    onClose: () => (expiredShown = false),
  });
  return m;
}
