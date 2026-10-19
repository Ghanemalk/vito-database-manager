// Table names in SQL and on screen. A table is { schema, name }; schema is null on MySQL and MariaDB.

import { PG } from '../boot.js';

/** A name quoted for SQL: "name" on PostgreSQL, `name` on MySQL and MariaDB. */
export const quoteIdent = (n) =>
  PG ? `"${String(n).replace(/"/g, '""')}"` : '`' + String(n).replace(/`/g, '``') + '`';

/** A table in SQL, with its schema on PostgreSQL. */
export const tableSql = (t) => (PG ? `${quoteIdent(t.schema || 'public')}.${quoteIdent(t.name)}` : quoteIdent(t.name));

/** A string that tells tables apart, for maps and storage keys. */
export const tableKey = (t) => `${t.schema ?? ''}\u0001${t.name}`;

/** A table's name for people; the schema shows only when it isn't public. */
export const tableLabel = (t) => (PG && t.schema && t.schema !== 'public' ? `${t.schema}.${t.name}` : t.name);
export const sameTable = (a, b) => !!a && !!b && a.name === b.name && (a.schema ?? null) === (b.schema ?? null);
