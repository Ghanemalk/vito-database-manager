<?php

namespace VitoDbManager\Sql;

use VitoDbManager\Support\DbmException;

/**
 * Splits an SQL script into statements the way the mysql and psql clients read it.
 *
 * The plugin feeds statements to those clients, so it must never let a script run a client command:
 * a backslash outside quotes and comments (mysql "\G", "\!", psql "\copy", "\!") is refused, and an
 * unterminated quote or comment is refused because it would swallow the statements that follow it.
 * The mysql "DELIMITER" line is understood and dropped.
 */
final class Splitter
{
    public const MYSQL = 'mysql';

    public const PGSQL = 'pgsql';

    /**
     * @return list<array{sql: string, line: int}> line = 1-based line of the statement's first character
     */
    public static function split(string $sql, string $dialect): array
    {
        $pg = $dialect === self::PGSQL;
        $len = strlen($sql);
        $stmts = [];
        $delim = ';';
        $buf = '';
        $hasCode = false;
        $textLine = 0;
        $line = 1;
        $i = 0;

        $push = static function () use (&$stmts, &$buf, &$hasCode, &$textLine): void {
            if ($hasCode) {
                $stmts[] = ['sql' => trim($buf), 'line' => $textLine];
            }
            $buf = '';
            $hasCode = false;
            $textLine = 0;
        };
        $take = static function (string $seg, bool $code) use (&$buf, &$hasCode, &$textLine, &$line): void {
            if ($textLine === 0) {
                $rest = ltrim($seg);
                if ($rest !== '') {
                    $textLine = $line + substr_count($seg, "\n", 0, strlen($seg) - strlen($rest));
                }
            }
            if ($code && trim($seg) !== '') {
                $hasCode = true;
            }
            $buf .= $seg;
            $line += substr_count($seg, "\n");
        };

        while ($i < $len) {
            $c = $sql[$i];

            // mysql client: "DELIMITER x" on its own line between statements
            if (! $pg && ! $hasCode && ($i === 0 || $sql[$i - 1] === "\n")
                && preg_match('/\G[ \t]*delimiter[ \t]+(\S+)[^\n]*(?:\n|$)/Ai', $sql, $m, 0, $i) === 1) {
                if (str_contains($m[1], '\\')) {
                    throw new DbmException(sprintf('Line %d: a delimiter cannot contain a backslash.', $line), 'input', ['line' => $line]);
                }
                $delim = $m[1];
                $line += substr_count($m[0], "\n");
                $i += strlen($m[0]);
                $buf = '';
                $textLine = 0;

                continue;
            }

            $next = $sql[$i + 1] ?? '';

            // line comments
            $lineComment = (! $pg && $c === '#')
                || ($c === '-' && $next === '-' && ($pg || $i + 2 >= $len || ctype_space($sql[$i + 2]) || ctype_cntrl($sql[$i + 2])));
            if ($lineComment) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $len : $end;
                $take(substr($sql, $i, $end - $i), false);
                $i = $end;

                continue;
            }

            // block comments (nested in PostgreSQL; /*! and /*+ are code in MySQL)
            if ($c === '/' && $next === '*') {
                $end = self::commentEnd($sql, $i, $pg);
                if ($end === null) {
                    throw new DbmException(sprintf('Line %d: this comment is never closed (missing */).', $line), 'input', ['line' => $line]);
                }
                $isCode = ! $pg && in_array($sql[$i + 2] ?? '', ['!', '+'], true);
                $take(substr($sql, $i, $end - $i), $isCode);
                $i = $end;

                continue;
            }

            // quoted strings and identifiers
            if ($c === "'" || $c === '"' || (! $pg && $c === '`')) {
                $escapes = $pg
                    ? $c === "'" && $i > 0 && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e') && ! self::identChar($sql[$i - 2] ?? ' ')
                    : $c !== '`';
                $end = self::quoteEnd($sql, $i, $c, $escapes);
                if ($end === null) {
                    throw new DbmException(
                        sprintf('Line %d: this %s is never closed.', $line, $c === "'" ? 'string' : 'quoted name'),
                        'input',
                        ['line' => $line],
                    );
                }
                $take(substr($sql, $i, $end - $i), true);
                $i = $end;

                continue;
            }

            // PostgreSQL dollar quoting: $$...$$, $tag$...$tag$
            if ($pg && $c === '$' && ! self::identChar($sql[$i - 1] ?? ' ')
                && preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/A', $sql, $m, 0, $i) === 1) {
                $close = strpos($sql, $m[0], $i + strlen($m[0]));
                if ($close === false) {
                    throw new DbmException(sprintf('Line %d: this %s block is never closed.', $line, $m[0]), 'input', ['line' => $line]);
                }
                $end = $close + strlen($m[0]);
                $take(substr($sql, $i, $end - $i), true);
                $i = $end;

                continue;
            }

            if ($c === '\\') {
                throw new DbmException(sprintf(
                    'Line %d: a backslash outside quotes is a %s client command, which is not supported here. Write plain SQL.',
                    $line,
                    $pg ? 'psql' : 'mysql'
                ), 'input', ['line' => $line]);
            }

            if ($delim !== '' && substr_compare($sql, $delim, $i, strlen($delim)) === 0) {
                $i += strlen($delim);
                $push();

                continue;
            }

            // MySQL: a line break too, so a DELIMITER line right after a statement is seen
            $stops = ($pg ? "'\"\$-/\\" : "'\"`#-/\\\n").$delim[0];
            $n = strcspn($sql, $stops, $i);
            if ($n === 0) {
                $n = 1;
            }
            $take(substr($sql, $i, $n), true);
            $i += $n;
        }
        $push();

        return $stmts;
    }

    /**
     * Upper-cased words of a statement outside strings and comments, each with its parenthesis depth and
     * whether it is called like a function (followed by "(").
     *
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    public static function words(string $stmt, string $dialect): array
    {
        $pg = $dialect === self::PGSQL;
        $len = strlen($stmt);
        $words = [];
        $depth = 0;
        $i = 0;
        while ($i < $len) {
            $c = $stmt[$i];
            $next = $stmt[$i + 1] ?? '';
            if ((! $pg && $c === '#') || ($c === '-' && $next === '-')) {
                $end = strpos($stmt, "\n", $i);
                $i = $end === false ? $len : $end;

                continue;
            }
            if ($c === '/' && $next === '*') {
                $i = self::commentEnd($stmt, $i, $pg) ?? $len;

                continue;
            }
            if ($c === "'" || $c === '"' || (! $pg && $c === '`')) {
                $escapes = $pg ? ($c === "'" && $i > 0 && ($stmt[$i - 1] === 'E' || $stmt[$i - 1] === 'e')) : $c !== '`';
                $i = self::quoteEnd($stmt, $i, $c, $escapes) ?? $len;

                continue;
            }
            if ($pg && $c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/A', $stmt, $m, 0, $i) === 1) {
                $close = strpos($stmt, $m[0], $i + strlen($m[0]));
                $i = $close === false ? $len : $close + strlen($m[0]);

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif (ctype_alpha($c) || $c === '_') {
                $n = strspn($stmt, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$', $i);
                $after = $i + $n + strspn($stmt, " \t\r\n", $i + $n);
                $words[] = [strtoupper(substr($stmt, $i, $n)), $depth, ($stmt[$after] ?? '') === '('];
                $i += $n;

                continue;
            }
            $i++;
        }

        return $words;
    }

    public static function firstWord(string $stmt, string $dialect): string
    {
        return self::words($stmt, $dialect)[0][0] ?? '';
    }

    /**
     * True for statements that only read: SELECT/WITH/SHOW/EXPLAIN/DESCRIBE/VALUES/TABLE without
     * a data-changing keyword anywhere and without a top-level INTO (SELECT ... INTO creates tables in
     * PostgreSQL and writes files or variables in MySQL).
     */
    public static function isRead(string $stmt, string $dialect): bool
    {
        $words = self::words($stmt, $dialect);
        $first = $words[0][0] ?? '';
        if (! in_array($first, ['SELECT', 'WITH', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'VALUES', 'TABLE'], true)) {
            return false;
        }
        foreach ($words as $k => [$word, $depth, $call]) {
            if (in_array($word, [
                'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE', 'TRUNCATE', 'DROP', 'ALTER', 'CREATE', 'GRANT', 'REVOKE', 'CALL',
                'DO', 'COPY', 'LOAD', 'HANDLER',
            ], true)) {
                // REPLACE(), INSERT(), TRUNCATE() are string/number functions; SELECT ... FOR UPDATE only locks
                if ($call && in_array($word, ['REPLACE', 'INSERT', 'TRUNCATE'], true)) {
                    continue;
                }
                if ($word === 'UPDATE' && in_array($words[$k - 1][0] ?? '', ['FOR', 'NO'], true)) {
                    continue;
                }

                return false;
            }
            if ($word === 'SET' && $first !== 'SHOW' && ! in_array($words[$k - 1][0] ?? '', ['CHARACTER', 'CHAR'], true)) {
                return false;
            }
            if ($word === 'INTO' && $depth === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * True when "SELECT * FROM (<stmt>) AS q LIMIT n" returns the same rows: a plain read query.
     */
    public static function isLimitable(string $stmt, string $dialect): bool
    {
        $first = self::firstWord($stmt, $dialect);

        return in_array($first, ['SELECT', 'WITH', 'VALUES', 'TABLE'], true) && self::isRead($stmt, $dialect);
    }

    /**
     * True when $sql can be placed inside a clause as one expression (a default, a CHECK, a generated column):
     * no statement delimiter, no comment that could hide what follows it, and parentheses that never close
     * more than they opened, so the text cannot step out of the clause it is put in.
     */
    public static function isExpression(string $sql, string $dialect): bool
    {
        try {
            $parts = self::split($sql, $dialect);
        } catch (DbmException) {
            return false;
        }
        if (count($parts) !== 1 || $parts[0]['sql'] !== trim($sql)) {
            return false;
        }
        $pg = $dialect === self::PGSQL;
        $len = strlen($sql);
        $depth = 0;
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ((! $pg && $c === '#') || ($c === '-' && $next === '-') || ($c === '/' && $next === '*')) {
                return false;
            }
            if ($c === "'" || $c === '"' || (! $pg && $c === '`')) {
                $escapes = $pg ? ($c === "'" && $i > 0 && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e')) : $c !== '`';
                $i = self::quoteEnd($sql, $i, $c, $escapes) ?? $len;

                continue;
            }
            if ($pg && $c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/A', $sql, $m, 0, $i) === 1) {
                $close = strpos($sql, $m[0], $i + strlen($m[0]));
                $i = $close === false ? $len : $close + strlen($m[0]);

                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')' && --$depth < 0) {
                return false;
            }
            $i++;
        }

        return $depth === 0;
    }

    private static function identChar(string $c): bool
    {
        return $c !== '' && (ctype_alnum($c) || $c === '_' || $c === '$' || ord($c) >= 0x80);
    }

    private static function quoteEnd(string $sql, int $start, string $quote, bool $escapes): ?int
    {
        $len = strlen($sql);
        $j = $start + 1;
        while ($j < $len) {
            $d = $sql[$j];
            if ($escapes && $d === '\\') {
                $j += 2;

                continue;
            }
            if ($d === $quote) {
                if (($sql[$j + 1] ?? '') === $quote) {
                    $j += 2;

                    continue;
                }

                return $j + 1;
            }
            $j++;
        }

        return null;
    }

    private static function commentEnd(string $sql, int $start, bool $nested): ?int
    {
        if (! $nested) {
            $end = strpos($sql, '*/', $start + 2);

            return $end === false ? null : $end + 2;
        }
        $len = strlen($sql);
        $depth = 0;
        $j = $start;
        while ($j < $len - 1) {
            $two = $sql[$j].$sql[$j + 1];
            if ($two === '/*') {
                $depth++;
                $j += 2;
            } elseif ($two === '*/') {
                $depth--;
                $j += 2;
                if ($depth === 0) {
                    return $j;
                }
            } else {
                $j++;
            }
        }

        return null;
    }
}
