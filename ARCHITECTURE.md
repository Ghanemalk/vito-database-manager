# How the code is organised

This is a map for reading and changing the code. The [README](README.md#how-it-works) explains what each part does
for the user. This page explains where the parts live in the code and how they connect.

Every file starts with a comment that says what it holds, so a quick look at a folder is usually enough to find
your way. Nothing is generated or bundled: the files in the repository are the files that run.

## One request, end to end

Take "show page 2 of the `orders` table" as an example:

1. **Browser.** `rows/grid.js` calls `api('rows', { table, page: 2, … })` (in `api.js`). That is one `POST` to
   `/servers/{server}/database-manager/{database}/api`.
2. **Controller.** `ManagerController::api()` checks the permission, builds an engine for the database
   (`engine()`), and calls `$engine->handle('rows', $args)`. Writes are added to the audit log.
3. **Engine.** `handle()` in `Engine.php` sends `rows` to `rows()` in `Concerns/Rows.php`. That method builds the
   `SELECT`. It asks the dialect for the parts that differ between databases, such as `gridExpr()` in
   `Mysql/Expressions.php`. It also asks `Concerns/Filters.php` for the `WHERE` and `ORDER BY`.
4. **Script.** `query()` in `Concerns/Scripts.php` wraps the statements in one script for the server's client:
   `script()`, `command()` and `parse()` in `Mysql/Scripts.php` or `Postgres/Scripts.php`. It sends the script over
   the transport (Vito's SSH connection) and reads the results back.
5. **Back.** The rows return as JSON. `grid.js` stores them in the table's state (`st.data`) and redraws the grid.

## The server side (PHP)

| Path | What it holds |
| --- | --- |
| `Plugin.php` | The entry point Vito loads. It registers the views, the routes and the launcher middleware. |
| `src/Vito/ManagerController.php` | The manager page, the JSON API, the database list for the launcher, and the static files (`asset()`). It handles permissions, the audit log, and stashing large scripts in parts. |
| `src/Vito/InjectLauncher.php` | Adds the launcher's `<script>` tag to every full page Vito sends. |
| `src/Vito/CacheStore.php` | Table metadata kept in Vito's cache. |
| `src/Engines/` | Turns each operation into SQL for one database and runs it (see below). |
| `src/Sql/Splitter.php` | Splits a script into statements the way `mysql` and `psql` read it, and refuses client commands such as `\!`. |
| `src/Transport/` | Runs the client on the server: `SshTransport` (Vito) or `ProcessTransport` (the tests). |
| `src/Support/` | `DbmException`, the error the browser is shown, and the `MetaStore` cache contract. |

### The engines

There is one engine class per database: `MysqlEngine` (MySQL and MariaDB) and `PostgresEngine`. Both extend
`Engine`. Each class is long, so it is split into **traits by area**. A trait here is just a way to spread one
class over several files. At run time `Engine` and its traits are one class, and `$this` in a trait is the
engine.

| Area | Shared code (`Concerns/`) | MySQL and MariaDB (`Mysql/`) | PostgreSQL (`Postgres/`) |
| --- | --- | --- | --- |
| Running a script and reading its output | `Scripts.php` | `Scripts.php` (XML output) | `Scripts.php` (CSV output) |
| Overview, table details, processes | `Metadata.php` (cache, table checks) | `Metadata.php` (`information_schema`) | `Metadata.php` (system catalogs) |
| Reading rows for the grid and the row editor | `Rows.php` | | |
| Filters, search and sort | `Filters.php` | | |
| Insert, update, delete, import | `Writes.php` | | |
| The SQL console | `Console.php` | | |
| Export as CSV, JSON or SQL | `Export.php` | | |
| Structure changes (columns, indexes, keys…) | `Structure.php` (checks the request) | `Structure.php` (the SQL) | `Structure.php` (the SQL) |
| SQL fragments the shared code asks for | | `Expressions.php` | `Expressions.php` |

- `Engine.php` holds the constants and the constructor. It also has `make()`, which picks the class for a database,
  and `handle()`, which maps each operation name to its method. Its **dialect section** lists, as abstract
  methods, everything each engine must provide.
- `MysqlEngine.php` and `PostgresEngine.php` hold the dialect itself: quoting names, writing values as literals,
  and naming tables.
- An empty cell in the table means that area has no database-specific part. All of it is in the shared trait,
  which asks the dialect for small pieces through the abstract methods.

The rule that keeps this safe: **no value from the browser goes into SQL as is.** Values go through `literal()`,
names through `quoteIdent()`, and everything that picks SQL syntax (types, operators, functions, options) is checked
against an allow-list.

## The browser side (JavaScript and CSS)

The UI is plain JavaScript in ES modules, with no framework and no build step. The page loads one file:

```html
<script type="module" src="…/database-manager/assets/<stamp>/manager/main.js"></script>
```

and the browser fetches the files it imports. All of them are served by `ManagerController::asset()`. The
`<stamp>` changes whenever a file under `resources/assets/` changes or the version goes up. This lets browsers
keep the files for a long time without ever mixing old and new ones. It is in the path rather than in a `?v=`
query because relative imports don't carry the query along.

### Modules (`resources/assets/manager/`)

| File | What it holds |
| --- | --- |
| `main.js` | The start: puts the app on the page, listens to the address bar, draws the first page. |
| `boot.js` | What the server put in the page (`BOOT`): the database, the engine, the URLs, the CSRF token. |
| `api.js` | `api(op, args)`, one call per operation. It returns the data or throws an `ApiError`. |
| `state.js` | What the app holds while it runs, and `tableState()`, the state of one table (see below). |
| `router.js` | The address bar: `#/`, `#/table/<schema or ->/<name>[/<tab>]`, `#/sql`, `#/monitor`. `go()` and `openTable()`. |
| `shell.js` | The frame around every page (top bar, table list) and `render()`, which draws the page the route names. |
| `values.js` | How database values look in the browser: text, `null`, binary, and values cut short. |
| `lib/` | Helpers with no UI: `format.js` (numbers, sizes, times), `sql.js` (table names), `storage.js` (settings kept in the browser), `files.js` (downloads, clipboard, reading a file). |
| `ui/` | The building blocks: `dom.js` (`h()` and the common controls), `icons.js`, `modals.js` (dialogs, drawers, the confirm box that shows the SQL), `popovers.js` (popovers, menus), `toasts.js`, `highlight.js` (SQL colours). |
| `pages/` | The pages: `overview.js`, `table.js` (a table's header and tabs), `sql-console.js`, `processes.js`. |
| `rows/` | The Rows tab: `grid.js` (the grid itself), `filters.js`, `inline-edit.js` (double-click a cell), `row-editor.js` (the drawer) with `fields.js` (one field per column type), `import.js` (CSV), `export.js`. |
| `structure/` | The other tabs: `columns.js`, `indexes.js`, `relations.js` (foreign keys and checks), `settings.js`, plus `create-table.js` (the New table dialog), `types.js` (column types) and `common.js` (what the tabs share). |

### Conventions

- **Building the page.** `h(tag, props, ...children)` creates an element, and the UI is made of nested `h()`
  calls. A `null` or `false` child is skipped, so a condition can stand in the list:
  `h('div', null, ok ? 'saved' : null)`. `fill(el, …)` replaces an element's content and `put(el, …)` adds to it.
  `btn()`, `field()`, `select()`, `check()` and the rest of `ui/dom.js` build the common controls.
- **Redrawing.** A page draws itself again from the state rather than patching the DOM piece by piece. `render()`
  draws the current route, and a tab redraws its own part, such as the grid, when its data changes.
- **Table state.** Code that works on one table calls its state `st` (from `tableState(table)`). The fields are
  listed above `tableState()` in `state.js`: `page`, `per`, `sort`, `filters`, `search`, `hidden`, `widths`, `data`,
  `structure`, `selected` and the loading flags. After a change, `invalidateTables()` makes every table load
  again when it is next shown.
- **Tables** are `{ schema, name }` everywhere. `schema` is `null` on MySQL and MariaDB.
- **Every view has an address.** Opening something goes through `go()` or `openTable()`, so Back, Forward and a
  reload always work.
- **Errors.** `api()` throws an `ApiError` with the server's message, and `fail(e)` shows any error as a toast.

### Styles (`resources/assets/manager/styles/`)

`main.css` imports the parts in the order they apply:

1. `theme.css`: Vito's own colour tokens, light and dark.
2. `base.css`
3. `shell.css`
4. `controls.css`
5. `cards.css`
6. `grid.css`
7. `overlays.css`
8. `sql.css`
9. `responsive.css`: narrow screens.

Each part starts with a comment that names what it styles.

### The launcher (`resources/assets/launcher.js`)

This is a separate, ordinary script that runs on Vito's own pages, not in the manager. It adds the **Open** and
**Database Manager** buttons to the Databases page. When you open a database, it shows the manager in a frame
inside Vito's layout. The comment at its top explains how it follows Vito's navigation.

## Where to change…

| You want to change | Look in |
| --- | --- |
| What a button or dialog says or does | the page's module in `pages/`, `rows/` or `structure/` |
| A new filter operator | `rows/filters.js` (browser) and `filterSql()` in `Concerns/Filters.php` (the operators it accepts, and their SQL) |
| How a column type is edited | `rows/fields.js` |
| A new structure change | `structure/<tab>.js`, then `Concerns/Structure.php` (checks) and `ddlStatements()` in `Mysql/Structure.php` and `Postgres/Structure.php` |
| What the overview shows | `pages/overview.js`, and `overview()` in `Mysql/Metadata.php` and `Postgres/Metadata.php` |
| How values are written into SQL | `literal()` and `binaryLiteral()` in `MysqlEngine.php` and `PostgresEngine.php` |
| A new API operation | `OPS` (and `LOGGED` if it writes) in `ManagerController.php`, `handle()` in `Engine.php`, then the method |
| Colours, spacing | `styles/`; colours come from the tokens in `theme.css` |

## Checks and tests

| Command | What it checks |
| --- | --- |
| `php tests/unit.php` | The statement splitter (statements, lines, refused input, read-only detection) and the checks on DDL expressions. No database needed. |
| `php tests/plugin.php` | How `Plugin.php` loads, against stand-ins for Vito and Laravel: its routes and their middleware, and two copies of the plugin in one Vito. |
| `node --experimental-vm-modules tests/modules.mjs` | Every JavaScript module parses, every import points at a file that exists and at a name that file exports, and no module is left unused. It runs nothing. |
| `sh tests/run-docker.sh <images…>` | `tests/integration.php` on real databases in Docker: every operation, on every supported version. |
| `php -S 127.0.0.1:8080 tests/dev/server.php` | Not a test: it runs the manager in a browser without Vito, against a scratch database. The comment at its top shows the settings. |

[CONTRIBUTING.md](CONTRIBUTING.md) has the rules for changes and pull requests.
