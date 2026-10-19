# Security policy

The Database Manager gives people who can change a server in Vito superuser access to that server's database, so
security reports are taken seriously and handled before anything else.

## Supported versions

| Version | Supported |
|---|---|
| 1.x | ✅ |

Fixes are released as a new version. Vito shows it as an update in **Admin → Plugins**.

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub's
[private vulnerability reporting](https://github.com/Ghanemalk/vito-database-manager/security/advisories/new). Please
include:

- what an attacker can do, and which Vito role they need (none, **User**, **Admin** or **Owner**);
- the steps or a request that shows it;
- the plugin, Vito and database versions you used.

Please don't include real credentials, customer data or anything else from a production database.

You'll get an acknowledgement within a few days. Once a fix is ready, it is released and the advisory is published,
crediting you unless you'd rather stay anonymous.

## Scope

Things that are in scope include:

- Reaching the manager or its API without Vito's `update` permission on both the server and the database.
- Reaching a database through a server it does not belong to.
- SQL or shell injection through any input, or running anything on the server other than the database client.
- Getting past the read-only console mode with a single script.
- Cross-site scripting or cross-site request forgery against the manager or Vito's Databases page.
- Secrets, SQL or data leaking into places they should not go, such as URLs, logs other than the audit log, or caches
  shared between users.

These are **not** vulnerabilities in this plugin:

- **An Owner or Admin can run any SQL.** That is the purpose of the plugin. See
  [the security model](README.md#security-model).
- Issues in Vito itself, MySQL, MariaDB or PostgreSQL. Please report those to their own projects.
