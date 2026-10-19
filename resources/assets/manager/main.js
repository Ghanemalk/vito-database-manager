/*
 * Database Manager for Vito — the browser app. No build step and no dependencies: the browser loads these files as
 * ES modules, as they are in the repository. Every value from the database is written with textContent, never as HTML.
 *
 * This file starts the app: it puts the page in place of the server-rendered placeholder, follows the address bar and
 * loads the database overview. Where everything else lives is described in README.md (How the code is organised).
 */

import { BOOT } from './boot.js';
import { app, loadOverview, render, renderShell, sideEl } from './shell.js';
import { state } from './state.js';
import { put } from './ui/dom.js';
import { layers } from './ui/modals.js';
import { toastBox } from './ui/toasts.js';

// the app takes the place of the empty element the server rendered (see manager.blade.php)
document.getElementById('vdbm').replaceWith(app);
put(document.body, toastBox);

// every view has an address, see router.js; going back and forward works like on any site
window.addEventListener('hashchange', render);

// / jumps to the table search, unless the user is typing or a dialog is open
document.addEventListener('keydown', (e) => {
  if (
    e.key === '/' &&
    !e.ctrlKey &&
    !e.metaKey &&
    !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '') &&
    !layers.length
  ) {
    const s = sideEl.querySelector('.side-head input');
    if (s) {
      e.preventDefault();
      s.focus();
    }
  }
});

// draw the page now, and again once the overview (the list of tables) has arrived
render();
if (BOOT.database) {
  loadOverview().then(() => {
    renderShell();
    const r = state.route;
    if (r.view === 'overview' || r.view === 'table') render();
  });
}
