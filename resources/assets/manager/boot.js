// What the server put in the page (see ManagerController::page()), and the values derived from it.

export const BOOT = JSON.parse(document.getElementById('vdbm-boot').textContent);
export const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const FLAVOR = BOOT.engine.flavor;
export const PG = FLAVOR === 'postgresql';
export const MARIA = FLAVOR === 'mariadb';
export const ENGINE = PG ? 'PostgreSQL' : MARIA ? 'MariaDB' : 'MySQL';
export const STORAGE_PREFIX = `vdbm:${BOOT.server.id}:${BOOT.database ? BOOT.database.id : 0}`;
export const SPONSOR = 'https://github.com/sponsors/Ghanemalk';
// Inside Vito's Databases page (see launcher.js): that page owns the address bar and the way back.
export const EMBED = BOOT.embed === true && window.parent !== window;

/** Tells the Databases page that frames the manager (see launcher.js) what it shows, or that it closes. */
export const tellVito = (msg) => {
  if (!EMBED) return;
  try {
    window.parent.postMessage({ vdbm: 1, ...msg }, location.origin);
  } catch {
    // the page that framed us is gone
  }
};
