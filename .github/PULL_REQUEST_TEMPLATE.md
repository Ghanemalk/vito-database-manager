## What does this change?

<!-- One or two sentences, and the issue it closes if there is one: "Closes #12". -->

## Type

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] Refactor or tests only

## Tested on

<!-- Tick every engine you ran tests/run-docker.sh against. Engine changes need all of them; CI runs them too. -->

- [ ] MySQL 8.4
- [ ] MySQL 9.7
- [ ] MariaDB 10.11
- [ ] MariaDB 11.4
- [ ] MariaDB 11.8
- [ ] MariaDB 12.3
- [ ] PostgreSQL 15
- [ ] PostgreSQL 16
- [ ] PostgreSQL 17
- [ ] PostgreSQL 18
- [ ] Tried in a real Vito panel (version: …)

## Screenshots

<!-- For UI changes: before and after, in dark mode and, if layout changed, on a phone-sized screen. -->

## Checklist

- [ ] `php -l`, `node --check` and `php tests/unit.php` pass
- [ ] New SQL uses literals and `quoteIdent()`, never raw input
- [ ] New operations that write are added to `LOGGED` (audit log)
- [ ] `CHANGELOG.md` has a line under **Unreleased**
- [ ] README updated if behaviour or limits changed
