// SQL syntax highlighting for the console and the SQL previews.

import { PG } from '../boot.js';
import { fill, h, put } from './dom.js';

const KEYWORDS = new Set(
  (
    'SELECT FROM WHERE AND OR NOT NULL IS IN AS ON JOIN LEFT RIGHT INNER OUTER FULL CROSS NATURAL GROUP BY ORDER HAVING LIMIT ' +
    'OFFSET INSERT INTO VALUES UPDATE SET DELETE CREATE TABLE ALTER DROP INDEX VIEW PRIMARY KEY FOREIGN REFERENCES UNIQUE CHECK DEFAULT ' +
    'CONSTRAINT DISTINCT UNION ALL EXISTS BETWEEN LIKE ILIKE CASE WHEN THEN ELSE END ASC DESC WITH RECURSIVE RETURNING TRUNCATE BEGIN ' +
    'COMMIT ROLLBACK START TRANSACTION IF REPLACE PROCEDURE FUNCTION TRIGGER DATABASE SCHEMA GRANT REVOKE EXPLAIN ANALYZE SHOW DESCRIBE ' +
    'USE CALL DECLARE RETURN RETURNS LANGUAGE COLUMN ADD RENAME TO MODIFY CHANGE AUTO_INCREMENT ENGINE CHARSET COLLATE TRUE FALSE INTERVAL ' +
    'CAST OVER PARTITION WINDOW FOR LOCK SHARE NOWAIT SKIP LOCKED DELIMITER DO LATERAL USING CASCADE RESTRICT TEMPORARY TEMP EXCEPT ' +
    'INTERSECT ANY SOME FETCH FIRST NEXT ROWS ONLY ROW OF IGNORE DUPLICATE CONFLICT NOTHING VACUUM OPTIMIZE SEQUENCE OWNER GENERATED ' +
    'ALWAYS IDENTITY STORED VIRTUAL COMMENT EXTENSION TYPE ENUM INT INTEGER BIGINT SMALLINT TINYINT MEDIUMINT VARCHAR CHAR TEXT BOOLEAN ' +
    'BOOL DATE DATETIME TIMESTAMP TIMESTAMPTZ TIME DECIMAL NUMERIC FLOAT DOUBLE PRECISION REAL JSON JSONB BLOB LONGTEXT MEDIUMTEXT UUID ' +
    'SERIAL BIGSERIAL BYTEA UNSIGNED VARBINARY BINARY ZONE AT LOOP WHILE ELSIF RAISE NOTICE EXCEPTION PERFORM EXECUTE'
  ).split(' '),
);

/** [type, text] pairs: k keyword, s string, n number, c comment, i quoted identifier, f function, '' anything else. */
function tokenize(src) {
  const out = [];
  const n = src.length;
  let i = 0;
  while (i < n) {
    const c = src[i],
      d = src[i + 1];
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
        if (!PG && src[j] === '\\') {
          j += 2;
          continue;
        }
        if (src[j] === c) {
          if (src[j + 1] === c) {
            j += 2;
            continue;
          }
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
          if (src[j + 1] === c) {
            j += 2;
            continue;
          }
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

/** Puts the SQL in into, coloured. Very large scripts stay plain, to keep the page responsive. */
export function highlight(src, into) {
  const frag = document.createDocumentFragment();
  if (src.length > 400000) {
    put(frag, src);
  } else {
    for (const [t, s] of tokenize(src)) put(frag, t ? h('span', { class: 'tk-' + t }, s) : document.createTextNode(s));
  }
  fill(into, frag);
  return into;
}

/** A block of coloured SQL. */
export const codeBlock = (sql) => highlight(sql, h('pre', { class: 'code' }));
