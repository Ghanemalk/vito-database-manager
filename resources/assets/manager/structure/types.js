// Column types: the suggestions in type fields, and how a column's type is shown.

import { BOOT, MARIA, PG } from '../boot.js';
import { h, put } from '../ui/dom.js';

/** MariaDB keeps JSON as LONGTEXT with a json_valid() check: call it json, as it was declared. */
export const typeName = (c) => (MARIA && c.cat === 'json' && /^longtext\b/i.test(c.type) ? 'json' : String(c.type));

/** A column type short enough for a grid heading. */
export function shortType(c) {
  const t = c.cat === 'enum' || c.cat === 'set' ? c.cat : typeName(c);
  return t.length > 22 ? t.slice(0, 21) + '…' : t;
}

const TYPES = PG
  ? [
      'bigint',
      'integer',
      'smallint',
      'numeric(10,2)',
      'real',
      'double precision',
      'boolean',
      'text',
      'varchar(255)',
      'char(1)',
      'uuid',
      'jsonb',
      'json',
      'date',
      'timestamp',
      'timestamptz',
      'time',
      'interval',
      'bytea',
      'inet',
      'cidr',
      'macaddr',
      'text[]',
      'integer[]',
      'tsvector',
      'xml',
      'money',
    ]
  : [
      'bigint unsigned',
      'bigint',
      'int',
      'int unsigned',
      'smallint',
      'tinyint',
      'tinyint(1)',
      'decimal(10,2)',
      'double',
      'float',
      'varchar(255)',
      'char(36)',
      'text',
      'mediumtext',
      'longtext',
      'json',
      'date',
      'datetime',
      'datetime(3)',
      'timestamp',
      'time',
      'year',
      'enum',
      'set',
      'blob',
      'longblob',
      'varbinary(255)',
      'binary(16)',
      'bit(1)',
      'point',
      'geometry',
      ...(MARIA ? ['uuid', 'inet6', 'vector(3)'] : ['vector(3)']),
    ];

let typeListId = null;

/**
 * The id of the datalist that suggests column types; it is added to the page the first time. A second list,
 * vdbm-funcs, suggests the server's functions for defaults.
 */
export function typeList() {
  if (!typeListId) {
    typeListId = 'vdbm-types';
    put(
      document.body,
      h(
        'datalist',
        { id: typeListId },
        TYPES.map((t) => h('option', { value: t })),
      ),
    );
    put(
      document.body,
      h(
        'datalist',
        { id: 'vdbm-funcs' },
        BOOT.functions.map((t) => h('option', { value: t })),
      ),
    );
  }
  return typeListId;
}

/** isIntType, isTimeType and isTextType tell what kind of type was typed, for the options the column form offers. */
export const isIntType = (t) => /^(tiny|small|medium|big)?int(eger)?\b|^serial|^bigserial|^int[248]\b/i.test(t.trim());
export const isTimeType = (t) => /^(datetime|timestamp)\b/i.test(t.trim());
export const isTextType = (t) => /^(var)?char|^(tiny|medium|long)?text|^enum|^set|^character/i.test(t.trim());
