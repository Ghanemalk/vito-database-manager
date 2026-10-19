# Contributing

Thanks for helping make the Database Manager better. Bug reports, ideas, documentation fixes and code are all welcome.

## Before you start

- **Bugs:** search the [existing issues](https://github.com/Ghanemalk/vito-database-manager/issues) first, then open one
  with the bug template. Include the database engine and version, because most behaviour differs between them.
- **Features:** open a feature request before writing a large change, so we can agree on the approach first.
- **Security problems:** do not open an issue. Follow [SECURITY.md](SECURITY.md).

## Development setup

You need PHP 8.3 or newer, Node.js 22 or newer (only for the JavaScript checks) and Docker for the integration
tests. There is nothing to install or build.

```bash
git clone https://github.com/Ghanemalk/vito-database-manager
cd vito-database-manager
php tests/unit.php
sh tests/run-docker.sh mariadb:11.8 postgres:18
```

[ARCHITECTURE.md](ARCHITECTURE.md) is the map of the code: what each file holds, how a request travels, and where to
look for a given change. Read it before your first change.

To work on the UI, run the manager in a browser without Vito, against a scratch database on your machine. The
settings are in the comment at the top of `tests/dev/server.php`:

```bash
VDBM_FLAVOR=postgresql VDBM_CLIENT="psql -h 127.0.0.1 -U postgres" VDBM_DATABASE=scratch \
    php -S 127.0.0.1:8080 tests/dev/server.php
```

The browser loads the JavaScript modules as they are, so a reload shows your change. To try your changes in a real
panel, install the folder into a development copy of Vito as described in the [README](README.md#manually). Reload
PHP-FPM after changing PHP files.

## Project rules

These rules keep the plugin safe and easy to install.

1. **No dependencies.** Don't add Composer packages, npm packages, a build step or anything loaded from a CDN. The
   plugin must work by copying a folder.
2. **Never put a raw value into SQL.**
   - Values go through the engine's literal encoding.
   - Names go through `quoteIdent()`.
   - Anything that chooses SQL syntax (operators, types, functions, options) comes from an allow-list.
3. **Every engine change is tested on every engine.** Add or extend checks in `tests/integration.php`, then run
   `tests/run-docker.sh` with all ten images listed in the README. Pull requests run them in CI too.
4. **Every write is audited.** A new operation that changes data or structure must be added to `LOGGED` in
   `ManagerController`.
5. **Ask before anything destructive.** Show the SQL before structure changes, and ask the user to type the name before
   dropping or emptying something big.
6. **Respect the request budget.** One action should be one script over one SSH connection, and it must finish well
   within 55 seconds.
7. **Match the surrounding code.**
   - PHP follows Laravel's style (`! $x`, `new Foo` without parentheses, short closures where they read well).
   - JavaScript uses the small `h()` DOM helper with no framework. It is formatted with
     [Prettier](https://prettier.io) using the settings in `.prettierrc.json`
     (`npx prettier --write resources/assets`). Prettier is a tool for your editor, not something the plugin
     needs.
   - Put new code where its feature lives: a module in the folder of its page (`pages/`, `rows/`, `structure/`),
     and a trait in `Concerns/`, `Mysql/` or `Postgres/` for its area. Start every new file with a comment that says
     what it holds.
   - Comments explain *why*, not *what*.
8. **UI text is plain and specific.** Say what will happen ("Drop the index orders_status_idx?"), not "Are you sure?".

## Pull requests

- Keep each pull request to one topic.
- Fill in the template. List the engines you tested on, and add before and after screenshots for UI changes.
- Add a line under **Unreleased** in [CHANGELOG.md](CHANGELOG.md).
- Make sure all of these pass:

  ```bash
  find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
  node --check resources/assets/launcher.js
  node --experimental-vm-modules tests/modules.mjs
  php tests/unit.php
  php tests/plugin.php
  ```

  `tests/modules.mjs` is there because `node --check` cannot check a module's imports. It makes sure every module
  parses, every import names a file that exists and a name that file exports, and no module is left unused.

## Releasing (maintainers)

1. Update `VERSION` in `src/Vito/ManagerController.php` and move the **Unreleased** notes in `CHANGELOG.md` under the
   new version.
2. Commit, then tag `vX.Y.Z` and publish a GitHub release from the tag. Vito installs and updates from the latest
   release.
3. Keep the `vitodeploy-plugin` topic on the repository so the plugin appears in Vito's community plugin list.

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
