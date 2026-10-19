<?php

namespace VitoDbManager\Engines;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;
use VitoDbManager\Support\MetaStore;
use VitoDbManager\Transport\Transport;

/**
 * One database on one server, driven through the database's own command-line client (mysql/mariadb or psql)
 * over the transport. Every operation is a single script: the client runs it in one session and the engine
 * reads the statements' results back from the client's output, between marker rows it adds to the script.
 *
 * Values never travel as bound parameters, so every value is written as a literal by the dialect
 * (literal(), binaryLiteral()) and every name by quoteIdent(); nothing the browser sends reaches the SQL
 * without going through one of the two.
 */
abstract class Engine
{
    public const OUTPUT_LIMIT = 64 * 1024 * 1024;

    /** Seconds one request may take; Vito's nginx gives up after 60. */
    public const WALL_TIME = 55;

    protected string $tag;

    public function __construct(
        protected readonly Transport $transport,
        protected readonly string $database,
        protected readonly string $flavor,
        protected readonly string $client,
        protected readonly MetaStore $store,
    ) {
        $this->tag = bin2hex(random_bytes(6));
    }

    /**
     * @param  string  $flavor  the Vito service name: mysql, mariadb or postgresql
     * @param  string|null  $client  the client command, without arguments (default: what Vito itself runs)
     */
    public static function make(string $flavor, Transport $transport, string $database, MetaStore $store, ?string $client = null): self
    {
        return match ($flavor) {
            'mysql', 'mariadb' => new MysqlEngine($transport, $database, $flavor, $client ?? 'sudo -n '.($flavor === 'mariadb' ? 'mariadb' : 'mysql'), $store),
            'postgresql' => new PostgresEngine($transport, $database, $flavor, $client ?? 'sudo -n -u postgres psql', $store),
            default => throw new DbmException("The {$flavor} database is not supported.", 'unsupported'),
        };
    }

    // ── dialect ────────────────────────────────────────────────────────────────────────────

    abstract public function dialect(): string;

    abstract public function quoteIdent(string $name): string;

    abstract public function literal(string $value): string;

    abstract public function binaryLiteral(string $raw): string;

    /**
     * @param  array{schema: ?string, name: string}  $table
     */
    abstract public function tableRef(array $table): string;

    /**
     * @param  list<string|array{sql: string, timeout?: int}>  $statements
     * @param  array<string, mixed>  $o
     */
    abstract protected function script(array $statements, array $o): string;

    /**
     * @param  array<string, mixed>  $o
     */
    abstract protected function command(array $o): string;

    /**
     * @param  array<string, mixed>  $o
     * @return array{results: array<int, array<string, mixed>>, error: ?array<string, mixed>, completed: bool, current: ?int}
     */
    abstract protected function parse(string $stdout, string $stderr, array $o): array;

    /**
     * @param  array{schema: ?string, name: string}  $table
     * @return array{table: array<string, mixed>, columns: list<array<string, mixed>>, key: array<string, mixed>}
     */
    abstract protected function loadMeta(array $table): array;

    /** @return array<string, mixed> */
    abstract public function overview(): array;

    /**
     * @param  array{schema: ?string, name: string}  $table
     * @return array<string, mixed>
     */
    abstract public function structure(array $table): array;

    /** @return array<string, mixed> */
    abstract public function monitor(): array;

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    abstract public function kill(array $a): array;

    /** SELECT expression for a grid cell; $max = 0 means the full value. */
    abstract protected function gridExpr(array $c, int $max): string;

    /** SELECT expression for the full value of a cell, as exported. */
    abstract protected function exportExpr(array $c): string;

    abstract protected function lengthExpr(array $c): string;

    /** SELECT expression of the row key for tables without a usable unique key, or null. */
    abstract protected function rowKeyExpr(array $meta): ?string;

    abstract protected function rowKeyMatch(array $meta, string $key): string;

    /** Text-search condition for one column, or null when the column can't match $term. */
    abstract protected function searchCondition(array $c, string $term): ?string;

    abstract protected function likeCondition(array $c, string $pattern, bool $not): string;

    abstract protected function regexpCondition(array $c, string $pattern): string;

    abstract protected function typedLiteral(array $c, string $value): string;

    abstract protected function expression(string $expr): string;

    /**
     * @param  list<string>  $names  quoted column names
     * @param  list<string>  $tuples
     */
    abstract protected function insertManySql(array $table, array $meta, array $names, array $tuples, string $mode): string;

    abstract protected function insertDefaultsSql(array $table): string;

    /** " LIMIT 1" where the dialect allows it on UPDATE/DELETE. */
    abstract protected function limitOne(array $meta): string;

    /**
     * @param  list<array{sql: string, line: int}>  $statements
     */
    protected function guardStatements(array $statements): void {}

    /** True when an SQL error means our cached table metadata is out of date. */
    abstract protected function isStaleError(DbmException $e): bool;

    /**
     * Statements for one structure change (see ddl()).
     *
     * @param  array<string, mixed>  $a
     * @return array{0: list<string>, 1: bool} the statements, and whether to run them in one transaction
     */
    abstract protected function ddlStatements(string $action, array $a): array;

    /**
     * The SQL functions a cell can be set to from the row editor.
     *
     * @return list<string>
     */
    abstract public function functions(): array;

    /**
     * Text placed between an INSERT's column list and VALUES (PostgreSQL: OVERRIDING SYSTEM VALUE).
     *
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $columns
     */
    protected function overriding(array $meta, array $columns): string
    {
        return '';
    }

    // ── entry point ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function handle(string $op, array $args): array
    {
        return match ($op) {
            'overview' => $this->overview(),
            'structure' => $this->structure($this->tableArg($args)),
            'rows' => $this->rows($args),
            'row' => $this->row($args),
            'insert' => $this->insert($args),
            'update' => $this->update($args),
            'delete' => $this->delete($args),
            'insert_many' => $this->insertMany($args),
            'sql' => $this->sql($args),
            'monitor' => $this->monitor(),
            'kill' => $this->kill($args),
            'export' => $this->export($args),
            'ddl' => $this->ddl($args),
            default => throw new DbmException('Unknown operation.', 'input'),
        };
    }

    // ── running scripts ────────────────────────────────────────────────────────────────────

    /**
     * Runs statements in one client session and returns what each one produced.
     *
     * @param  list<string|array{sql: string, timeout?: int}>  $statements
     * @param  array<string, mixed>  $opts  timeout: per statement (seconds) · limit: max rows kept per result (0 = all)
     *                                      hex: binary values as 0x… text · wall: seconds for the whole run · output: max bytes read
     * @return array{results: array<int, array<string, mixed>>, error: ?array<string, mixed>, completed: bool, truncated: bool, timed_out: bool}
     */
    public function execute(array $statements, array $opts = []): array
    {
        // lock_timeout: seconds to wait for a row/table lock · fresh_stats: up-to-date row counts and sizes (MySQL caches them)
        // console: the statements come from the user, so the session is put back in a known state after each one
        $o = $opts + ['tx' => false, 'readonly' => false, 'timeout' => 30, 'limit' => 0, 'warnings' => false,
            'output' => self::OUTPUT_LIMIT, 'wall' => self::WALL_TIME, 'hex' => false, 'lock_timeout' => 15,
            'fresh_stats' => false, 'console' => false];
        $run = $this->transport->run($this->command($o), $this->script($statements, $o), (int) $o['wall'], (int) $o['output']);
        $parsed = $this->parse($run->stdout, $run->stderr, $o);

        if ($parsed['error'] === null && ! $parsed['completed']) {
            $message = match (true) {
                $run->timedOut => sprintf('No answer within %d seconds. The statement may still be running on the server (see Monitor).', $o['wall']),
                $run->truncated => sprintf('The result is larger than %d MB, so the rest was not read. Select fewer rows or columns.', intdiv((int) $o['output'], 1048576)),
                default => $this->clientFailure($run->stderr, $run->exitCode),
            };
            $parsed['error'] = ['i' => $parsed['current'] ?? -1, 'code' => null, 'sqlstate' => null,
                'message' => $message, 'kind' => $run->timedOut ? 'timeout' : ($run->truncated ? 'too_large' : 'client')];
        }

        return [
            'results' => $parsed['results'],
            'error' => $parsed['error'],
            'completed' => $parsed['completed'],
            'truncated' => $run->truncated,
            'timed_out' => $run->timedOut,
        ];
    }

    /**
     * execute() that throws on the first error.
     *
     * @param  list<string|array{sql: string, timeout?: int}>  $statements
     * @param  array<string, mixed>  $opts
     * @return array<int, array<string, mixed>>
     */
    protected function query(array $statements, array $opts = []): array
    {
        $r = $this->execute($statements, $opts);
        if ($r['error'] !== null) {
            throw $this->sqlException($r['error']);
        }

        return $r['results'];
    }

    /**
     * Rows of the first result set of statement $i as name => value arrays.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return list<array<string, ?string>>
     */
    protected function assoc(array $results, int $i): array
    {
        $set = $results[$i]['sets'][0] ?? null;
        if ($set === null) {
            return [];
        }
        $out = [];
        foreach ($set['rows'] as $row) {
            $item = [];
            foreach ($set['columns'] as $k => $name) {
                $item[$name] = $row[$k] ?? null;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $error
     */
    protected function sqlException(array $error): DbmException
    {
        $kind = $error['kind'] ?? ($this->isTimeoutCode($error['code'] ?? null, $error['sqlstate'] ?? null) ? 'timeout' : 'sql');

        return new DbmException((string) $error['message'], $kind, array_filter([
            'code' => $error['code'] ?? null,
            'sqlstate' => $error['sqlstate'] ?? null,
        ], static fn ($v) => $v !== null));
    }

    protected function isTimeoutCode(mixed $code, ?string $sqlstate): bool
    {
        return in_array((int) $code, [1969, 3024], true) || $sqlstate === '57014';
    }

    protected function clientFailure(string $stderr, ?int $exit): string
    {
        $stderr = trim($stderr);
        if ($stderr === '') {
            return 'The database client stopped without an answer'.($exit !== null ? " (exit code {$exit})." : '.');
        }

        return 'The database client failed: '.mb_strimwidth($stderr, 0, 600, '…');
    }

    // ── metadata ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{schema: ?string, name: string}  $table
     * @return array{table: array<string, mixed>, columns: list<array<string, mixed>>, key: array<string, mixed>}
     */
    public function meta(array $table, bool $fresh = false): array
    {
        $cacheKey = 'meta:'.($table['schema'] ?? '').'.'.$table['name'];
        if (! $fresh && ($cached = $this->store->get($cacheKey)) !== null) {
            return $cached;
        }
        $meta = $this->loadMeta($table);
        $this->store->put($cacheKey, $meta);

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{schema: ?string, name: string}
     */
    protected function tableArg(array $a): array
    {
        $t = $a['table'] ?? null;
        $name = is_array($t) ? ($t['name'] ?? null) : $t;
        $schema = is_array($t) ? ($t['schema'] ?? null) : null;
        if (! is_string($name) || $name === '' || strlen($name) > 128 || str_contains($name, "\0")) {
            throw new DbmException('Choose a table.', 'input');
        }
        if ($schema !== null && (! is_string($schema) || $schema === '' || strlen($schema) > 128 || str_contains($schema, "\0"))) {
            throw new DbmException('Invalid schema.', 'input');
        }

        return ['schema' => $this->dialect() === Splitter::PGSQL ? ($schema ?? 'public') : null, 'name' => $name];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, array<string, mixed>>
     */
    protected function byName(array $columns): array
    {
        $by = [];
        foreach ($columns as $c) {
            $by[$c['name']] = $c;
        }

        return $by;
    }

    protected function int(mixed $value, int $min, int $max, int $default = 0): int
    {
        $n = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $n));
    }

    // ── browsing ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function rows(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        try {
            return $this->fetchRows($table, $meta, $a);
        } catch (DbmException $e) {
            if (! $this->isStaleError($e)) {
                throw $e;
            }

            return $this->fetchRows($table, $this->meta($table, true), $a);
        }
    }

    /**
     * @param  array{schema: ?string, name: string}  $table
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function fetchRows(array $table, array $meta, array $a): array
    {
        $cols = $meta['columns'];
        $by = $this->byName($cols);
        $cellMax = $this->int($a['cell_max'] ?? 300, 40, 20000, 300);
        $per = $this->int($a['per'] ?? 25, 1, 500, 25);
        $page = $this->int($a['page'] ?? 1, 1, 10_000_000, 1);
        $where = $this->whereSql($a, $by);
        $order = $this->orderSql($a['sort'] ?? [], $by, $meta['key']);
        $keyCols = in_array($meta['key']['mode'], ['pk', 'unique'], true) ? $meta['key']['cols'] : [];

        $select = [];
        foreach ($cols as $c) {
            $select[] = $this->gridExpr($c, in_array($c['name'], $keyCols, true) ? 0 : $cellMax);
        }
        $lenAt = [];
        foreach ($cols as $i => $c) {
            if ($c['long'] && ! in_array($c['name'], $keyCols, true)) {
                $lenAt[$i] = count($select);
                $select[] = $this->lengthExpr($c);
            }
        }
        $keyAt = null;
        if (($keyExpr = $this->rowKeyExpr($meta)) !== null) {
            $keyAt = count($select);
            $select[] = $keyExpr;
        }

        $from = $this->tableRef($table);
        $statements = [[
            'sql' => 'SELECT '.implode(', ', $select).' FROM '.$from.$where.$order.' LIMIT '.$per.' OFFSET '.(($page - 1) * $per),
            'timeout' => 30,
        ]];
        $estimate = $meta['table']['rows'] ?? null;
        $countIt = $where !== '' || $estimate === null || $estimate < 2_000_000;
        if ($countIt) {
            $statements[] = ['sql' => 'SELECT COUNT(*) FROM '.$from.$where, 'timeout' => 10];
        }

        $r = $this->execute($statements);
        if (! isset($r['results'][0])) {
            throw $this->sqlException($r['error'] ?? ['message' => 'The rows could not be read.']);
        }

        $index = [];
        foreach ($cols as $i => $c) {
            $index[$c['name']] = $i;
        }
        $rows = [];
        $keys = [];
        foreach ($r['results'][0]['sets'][0]['rows'] ?? [] as $raw) {
            $cells = [];
            foreach ($cols as $i => $c) {
                $full = isset($lenAt[$i]) && isset($raw[$lenAt[$i]]) ? (int) $raw[$lenAt[$i]] : null;
                $cells[] = $this->gridCell($c, $raw[$i] ?? null, $full, $cellMax);
            }
            $rows[] = $cells;
            $keys[] = $this->rowKey($meta, $raw, $index, $keyAt);
        }

        $total = $estimate;
        $approx = true;
        $countError = null;
        if (isset($r['results'][1])) {
            $total = (int) ($r['results'][1]['sets'][0]['rows'][0][0] ?? 0);
            $approx = false;
        } elseif ($countIt && $r['error'] !== null) {
            $countError = $r['error']['message'];
        }

        return [
            'table' => $table,
            'columns' => $cols,
            'key' => $meta['key'],
            'type' => $meta['table']['type'] ?? 'table',
            'rows' => $rows,
            'keys' => $keys,
            'total' => $total,
            'approx' => $approx,
            'count_error' => $countError,
            'page' => $page,
            'per' => $per,
        ];
    }

    /**
     * The full values of one row, for the row editor.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function row(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $cols = $meta['columns'];
        $max = 4_000_000;
        $select = [];
        foreach ($cols as $c) {
            $select[] = $this->gridExpr($c, $c['long'] ? $max : 0);
        }
        $lenAt = [];
        foreach ($cols as $i => $c) {
            if ($c['long']) {
                $lenAt[$i] = count($select);
                $select[] = $this->lengthExpr($c);
            }
        }
        $sql = 'SELECT '.implode(', ', $select).' FROM '.$this->tableRef($table).' WHERE '.$this->keyWhere($meta, $a['key'] ?? null).' LIMIT 2';
        $r = $this->query([$sql], ['output' => 128 * 1024 * 1024]);
        $found = $r[0]['sets'][0]['rows'] ?? [];
        if ($found === []) {
            throw new DbmException('This row no longer exists. It may have been changed or deleted; reload the rows.', 'stale');
        }
        $raw = $found[0];
        $cells = [];
        foreach ($cols as $i => $c) {
            $full = isset($lenAt[$i]) && isset($raw[$lenAt[$i]]) ? (int) $raw[$lenAt[$i]] : null;
            $cells[] = $this->gridCell($c, $raw[$i] ?? null, $full, $max);
        }

        return ['table' => $table, 'columns' => $cols, 'key' => $meta['key'], 'row' => $cells, 'ambiguous' => count($found) > 1];
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function gridCell(array $c, ?string $v, ?int $full, int $max): mixed
    {
        if ($v === null) {
            return null;
        }
        if ($c['cat'] === 'bin') {
            $hex = preg_replace('/^0x/i', '', $v) ?? '';
            $raw = ctype_xdigit($hex) && strlen($hex) % 2 === 0 ? (string) hex2bin($hex) : $v;

            return ['b' => base64_encode($raw), 'n' => $full ?? strlen($raw)];
        }
        if (preg_match('//u', $v) !== 1) {
            return ['b' => base64_encode($v), 'n' => strlen($v)];
        }
        if ($full !== null && $max > 0 && $full > $max) {
            return ['t' => $v, 'n' => $full];
        }

        return $v;
    }

    /**
     * A cell of an arbitrary query result.
     */
    protected function genericCell(?string $v, int $max): mixed
    {
        if ($v === null) {
            return null;
        }
        if (preg_match('//u', $v) !== 1) {
            return ['b' => base64_encode(substr($v, 0, $max)), 'n' => strlen($v)];
        }
        if (strlen($v) > $max) {
            $cut = substr($v, 0, $max);
            while ($cut !== '' && preg_match('//u', $cut) !== 1) {
                $cut = substr($cut, 0, -1);
            }

            return ['t' => $cut, 'n' => strlen($v), 'u' => 'bytes'];
        }

        return $v;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<?string>  $raw
     * @param  array<string, int>  $index
     * @return list<mixed>|null
     */
    protected function rowKey(array $meta, array $raw, array $index, ?int $keyAt): ?array
    {
        $mode = $meta['key']['mode'];
        if ($mode === 'pk' || $mode === 'unique') {
            $values = [];
            foreach ($meta['key']['cols'] as $name) {
                $i = $index[$name];
                $v = $raw[$i] ?? null;
                $values[] = $v !== null && $meta['columns'][$i]['cat'] === 'bin'
                    ? ['x' => strtolower((string) preg_replace('/^0x/i', '', $v))]
                    : $v;
            }

            return $values;
        }
        if ($keyAt !== null) {
            return [$raw[$keyAt] ?? null];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function keyWhere(array $meta, mixed $key): string
    {
        $mode = $meta['key']['mode'];
        if (! is_array($key)) {
            throw new DbmException('This row has no key, so it cannot be changed here. Use the SQL console.', 'input');
        }
        if ($mode === 'pk' || $mode === 'unique') {
            $names = $meta['key']['cols'];
            if (count($key) !== count($names)) {
                throw new DbmException('The table changed since the rows were loaded. Reload them.', 'stale');
            }
            $by = $this->byName($meta['columns']);
            $parts = [];
            foreach (array_values($names) as $k => $name) {
                $parts[] = $this->keyPart($by[$name], $key[$k] ?? null);
            }

            return implode(' AND ', $parts);
        }
        if ($mode === 'hash' || $mode === 'ctid') {
            $v = $key[0] ?? null;
            if (! is_string($v) || $v === '') {
                throw new DbmException('The table changed since the rows were loaded. Reload them.', 'stale');
            }

            return $this->rowKeyMatch($meta, $v);
        }

        throw new DbmException('This '.($meta['table']['type'] ?? 'table').' has no key, so its rows cannot be changed here.', 'input');
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function keyPart(array $c, mixed $v): string
    {
        $q = $this->quoteIdent($c['name']);
        if ($v === null) {
            return $q.' IS NULL';
        }
        if (is_array($v) && isset($v['x'])) {
            return $q.' = '.$this->binaryLiteral($this->hex((string) $v['x']));
        }
        if (! is_scalar($v)) {
            throw new DbmException('Invalid row key.', 'input');
        }

        return $q.' = '.$this->typedLiteral($c, (string) $v);
    }

    protected function hex(string $hex): string
    {
        $hex = (string) preg_replace('/^0x/i', '', $hex);
        if ($hex !== '' && (! ctype_xdigit($hex) || strlen($hex) % 2 !== 0)) {
            throw new DbmException('Invalid hexadecimal value.', 'input');
        }

        return (string) hex2bin($hex);
    }

    // ── filters, search, order ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, array<string, mixed>>  $by
     */
    protected function whereSql(array $a, array $by): string
    {
        $parts = [];
        foreach ((array) ($a['filters'] ?? []) as $f) {
            if (! is_array($f)) {
                continue;
            }
            $name = (string) ($f['col'] ?? '');
            $c = $by[$name] ?? throw new DbmException("Unknown column {$name} in a filter.", 'input');
            $parts[] = $this->filterSql($c, (string) ($f['op'] ?? '='), $f['value'] ?? null, $f['value2'] ?? null);
        }
        $term = trim((string) ($a['search'] ?? ''));
        if ($term !== '') {
            $or = [];
            foreach ($by as $c) {
                if (($cond = $this->searchCondition($c, $term)) !== null) {
                    $or[] = $cond;
                }
            }
            $parts[] = $or === [] ? '1 = 0' : '('.implode(' OR ', $or).')';
        }

        return $parts === [] ? '' : ' WHERE '.implode(' AND ', $parts);
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function filterSql(array $c, string $op, mixed $value, mixed $value2): string
    {
        $q = $this->quoteIdent($c['name']);
        $lit = fn (mixed $v): string => $this->filterLiteral($c, $v);

        return match ($op) {
            '=', '!=', '<', '<=', '>', '>=' => $q.' '.($op === '!=' ? '<>' : $op).' '.$lit($value),
            'contains' => $this->likeCondition($c, '%'.$this->likeEscape((string) $value).'%', false),
            'not_contains' => $this->likeCondition($c, '%'.$this->likeEscape((string) $value).'%', true),
            'starts' => $this->likeCondition($c, $this->likeEscape((string) $value).'%', false),
            'ends' => $this->likeCondition($c, '%'.$this->likeEscape((string) $value), false),
            'null' => $q.' IS NULL',
            'not_null' => $q.' IS NOT NULL',
            'empty' => '('.$q.' IS NULL OR '.$this->likeCondition($c, '', false).')',
            'not_empty' => '('.$q.' IS NOT NULL AND '.$this->likeCondition($c, '', true).')',
            'in', 'not_in' => $q.($op === 'not_in' ? ' NOT IN (' : ' IN (').implode(', ', array_map($lit, $this->listValues($value))).')',
            'between' => $q.' BETWEEN '.$lit($value).' AND '.$lit($value2),
            'regexp' => $this->regexpCondition($c, (string) $value),
            default => throw new DbmException("Unknown filter {$op}.", 'input'),
        };
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function filterLiteral(array $c, mixed $v): string
    {
        if (! is_scalar($v)) {
            throw new DbmException('A filter needs a value.', 'input');
        }
        $s = (string) $v;
        if ($c['cat'] === 'bin') {
            return $this->binaryLiteral($this->hex(str_replace(' ', '', $s)));
        }

        return $this->typedLiteral($c, $s);
    }

    /**
     * @return list<string>
     */
    protected function listValues(mixed $value): array
    {
        $items = is_array($value) ? $value : str_getcsv((string) $value, ',', '"', '');
        $items = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $items), static fn ($v) => $v !== ''));
        if ($items === []) {
            throw new DbmException('"in" needs at least one value, separated by commas.', 'input');
        }

        return array_slice($items, 0, 1000);
    }

    protected function likeEscape(string $v): string
    {
        // "!" rather than the default backslash, so patterns read the same whatever the session's escaping rules
        return strtr($v, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $by
     * @param  array<string, mixed>  $key
     */
    protected function orderSql(mixed $sort, array $by, array $key): string
    {
        $parts = [];
        $used = [];
        foreach (is_array($sort) ? $sort : [] as $s) {
            $name = is_array($s) ? (string) ($s[0] ?? $s['col'] ?? '') : '';
            if (! isset($by[$name]) || isset($used[$name])) {
                continue;
            }
            $dir = strtolower((string) ($s[1] ?? $s['dir'] ?? 'asc')) === 'desc' ? ' DESC' : ' ASC';
            $parts[] = $this->quoteIdent($name).$dir;
            $used[$name] = true;
        }
        // a stable order for paging: end with the unique key
        if (in_array($key['mode'], ['pk', 'unique'], true)) {
            $dir = $parts === [] ? ' DESC' : ' ASC';
            foreach ($key['cols'] as $name) {
                if (! isset($used[$name])) {
                    $parts[] = $this->quoteIdent($name).$dir;
                }
            }
        }

        return $parts === [] ? '' : ' ORDER BY '.implode(', ', $parts);
    }

    // ── writing rows ───────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function assertEditable(array $meta): void
    {
        $type = $meta['table']['type'] ?? 'table';
        if (! in_array($type, ['table', 'partitioned'], true)) {
            throw new DbmException("Rows of a {$type} cannot be edited here.", 'input');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<mixed>  $values
     * @return array{0: list<string>, 1: list<string>}
     */
    protected function assignments(array $meta, array $values): array
    {
        $by = $this->byName($meta['columns']);
        $names = [];
        $exprs = [];
        foreach ($values as $name => $v) {
            $c = $by[(string) $name] ?? throw new DbmException("Unknown column {$name}.", 'input');
            if ($c['generated']) {
                continue;
            }
            $names[] = $this->quoteIdent($c['name']);
            $exprs[] = $this->valueSql($c, $v);
        }

        return [$names, $exprs];
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function valueSql(array $c, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            $v = $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        if (is_array($v)) {
            if (! empty($v['default'])) {
                return 'DEFAULT';
            }
            if (isset($v['expr'])) {
                return $this->expression((string) $v['expr']);
            }
            if (isset($v['x'])) {
                return $this->binaryLiteral($this->hex((string) $v['x']));
            }
            if (isset($v['b'])) {
                $raw = base64_decode((string) $v['b'], true);
                if ($raw === false) {
                    throw new DbmException("Invalid file data for {$c['name']}.", 'input');
                }

                return $this->binaryLiteral($raw);
            }
            throw new DbmException("Invalid value for {$c['name']}.", 'input');
        }
        if (! is_string($v)) {
            throw new DbmException("Invalid value for {$c['name']}.", 'input');
        }

        return $this->typedLiteral($c, $v);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function insert(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        $values = (array) ($a['values'] ?? []);
        [$names, $exprs] = $this->assignments($meta, $values);
        $sql = $names === []
            ? $this->insertDefaultsSql($table)
            : 'INSERT INTO '.$this->tableRef($table).' ('.implode(', ', $names).')'
                .$this->overriding($meta, array_map('strval', array_keys($values))).' VALUES ('.implode(', ', $exprs).')';
        $r = $this->query([$sql], ['warnings' => true, 'tx' => true]);

        return ['affected' => $r[0]['affected'], 'insert_id' => $r[0]['insert_id'], 'warnings' => $r[0]['warnings'], 'sql' => $sql];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function update(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        [$names, $exprs] = $this->assignments($meta, (array) ($a['values'] ?? []));
        if ($names === []) {
            return ['affected' => 0, 'warnings' => [], 'sql' => null];
        }
        $where = $this->keyWhere($meta, $a['key'] ?? null);
        $set = [];
        foreach ($names as $k => $n) {
            $set[] = $n.' = '.$exprs[$k];
        }
        $sql = 'UPDATE '.$this->tableRef($table).' SET '.implode(', ', $set).' WHERE '.$where.$this->limitOne($meta);
        $r = $this->query([$sql, 'SELECT COUNT(*) FROM '.$this->tableRef($table).' WHERE '.$where], ['warnings' => true, 'tx' => true]);
        $affected = (int) $r[0]['affected'];
        $exists = (int) ($r[1]['sets'][0]['rows'][0][0] ?? 0);
        if ($affected === 0 && $exists === 0 && ! $this->keyChanged($meta, $a)) {
            throw new DbmException('This row no longer exists. It may have been changed or deleted; reload the rows.', 'stale');
        }

        return ['affected' => $affected, 'warnings' => $r[0]['warnings'], 'sql' => $sql];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $a
     */
    private function keyChanged(array $meta, array $a): bool
    {
        $values = (array) ($a['values'] ?? []);
        foreach ($meta['key']['cols'] ?? [] as $name) {
            if (array_key_exists($name, $values)) {
                return true;
            }
        }

        return in_array($meta['key']['mode'], ['hash', 'ctid'], true);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function delete(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        $keys = array_values((array) ($a['keys'] ?? []));
        if ($keys === []) {
            throw new DbmException('Select rows to delete.', 'input');
        }
        if (count($keys) > 1000) {
            throw new DbmException('Delete at most 1000 rows at a time.', 'input');
        }
        $statements = [];
        foreach ($keys as $key) {
            $statements[] = 'DELETE FROM '.$this->tableRef($table).' WHERE '.$this->keyWhere($meta, $key).$this->limitOne($meta);
        }
        $r = $this->query($statements, ['tx' => true]);
        $deleted = 0;
        foreach ($r as $res) {
            $deleted += (int) ($res['affected'] ?? 0);
        }

        return ['deleted' => $deleted, 'requested' => count($keys), 'sql' => $statements[0].(count($statements) > 1 ? ' …' : '')];
    }

    /**
     * Inserts many rows (CSV import). mode: insert | ignore (skip rows that clash with a unique key) | upsert (update them).
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function insertMany(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $this->assertEditable($meta);
        $by = $this->byName($meta['columns']);
        $columns = array_values(array_map('strval', (array) ($a['columns'] ?? [])));
        if ($columns === []) {
            throw new DbmException('Choose at least one column.', 'input');
        }
        $cols = [];
        foreach ($columns as $name) {
            $c = $by[$name] ?? throw new DbmException("Unknown column {$name}.", 'input');
            if ($c['generated']) {
                throw new DbmException("{$name} is a generated column and cannot be imported.", 'input');
            }
            $cols[] = $c;
        }
        $rows = array_values((array) ($a['rows'] ?? []));
        if ($rows === [] || count($rows) > 2000) {
            throw new DbmException('Send between 1 and 2000 rows at a time.', 'input');
        }
        $tuples = [];
        foreach ($rows as $n => $row) {
            $row = array_values((array) $row);
            if (count($row) !== count($cols)) {
                throw new DbmException(sprintf('Row %d has %d values; %d expected.', $n + 1, count($row), count($cols)), 'input');
            }
            $exprs = [];
            foreach ($cols as $k => $c) {
                $exprs[] = $this->valueSql($c, $row[$k]);
            }
            $tuples[] = '('.implode(', ', $exprs).')';
        }
        $mode = in_array($a['mode'] ?? 'insert', ['insert', 'ignore', 'upsert'], true) ? (string) ($a['mode'] ?? 'insert') : 'insert';
        $sql = $this->insertManySql($table, $meta, array_map(fn ($c) => $this->quoteIdent($c['name']), $cols), $tuples, $mode);
        $r = $this->query([$sql], ['tx' => true, 'warnings' => true]);

        return ['affected' => $r[0]['affected'], 'rows' => count($rows), 'warnings' => array_slice($r[0]['warnings'], 0, 20)];
    }

    // ── SQL console ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function sql(array $a): array
    {
        $text = (string) ($a['sql'] ?? '');
        if (strlen($text) > 50 * 1024 * 1024) {
            throw new DbmException('The script is larger than 50 MB. Restore large dumps from Backups instead.', 'input');
        }
        $statements = Splitter::split($text, $this->dialect());
        if ($statements === []) {
            throw new DbmException('There is no SQL to run.', 'input');
        }
        $readonly = (bool) ($a['readonly'] ?? false);
        if ($readonly) {
            foreach ($statements as $k => $s) {
                if (! Splitter::isRead($s['sql'], $this->dialect())) {
                    throw new DbmException(sprintf(
                        'Read-only mode: statement %d (line %d) can change data or structure. Turn read-only off to run it.',
                        $k + 1,
                        $s['line']
                    ), 'readonly', ['statement' => $k, 'line' => $s['line']]);
                }
            }
        }
        $this->guardStatements($statements);

        $limit = $this->int($a['max_rows'] ?? 1000, 1, 10000, 1000);
        $cellMax = $this->int($a['cell_max'] ?? 4000, 100, 1_000_000, 4000);
        $summary = (bool) ($a['summary'] ?? false) || count($statements) > 500;
        $tx = (bool) ($a['tx'] ?? false);

        $timeout = $this->int($a['timeout'] ?? 30, 1, self::WALL_TIME - 5, 30);
        $r = $this->execute(array_map(static fn ($s) => $s['sql'], $statements), [
            'tx' => $tx,
            'readonly' => $readonly,
            'timeout' => $timeout,
            'lock_timeout' => $timeout,
            'limit' => $limit,
            'warnings' => ! $summary,
            'hex' => true,
            'console' => true,
        ]);

        $results = [];
        foreach ($statements as $k => $s) {
            if (! isset($r['results'][$k])) {
                break;
            }
            $res = $r['results'][$k];
            $sets = [];
            foreach ($res['sets'] as $set) {
                $kept = array_slice($set['rows'], 0, $limit);
                $sets[] = [
                    'columns' => $set['columns'],
                    'rows' => $summary ? [] : array_map(fn ($row) => array_map(fn ($v) => $this->genericCell($v, $cellMax), $row), $kept),
                    'count' => count($kept),
                    'more' => count($set['rows']) > $limit || ! empty($set['more']),
                ];
            }
            $results[] = [
                'i' => $k,
                'line' => $s['line'],
                'sql' => mb_strimwidth($s['sql'], 0, 400, '…'),
                'kind' => Splitter::firstWord($s['sql'], $this->dialect()),
                'sets' => $sets,
                'affected' => $res['affected'],
                'insert_id' => $res['insert_id'],
                'ms' => $res['ms'],
                'warnings' => array_slice($res['warnings'], 0, 50),
            ];
        }

        $error = $r['error'];
        if ($error !== null) {
            $i = (int) ($error['i'] ?? -1);
            $error['statement'] = $i;
            $error['line'] = $statements[$i]['line'] ?? null;
            if (isset($error['line'], $error['stmt_line'])) {
                $error['line'] += $error['stmt_line'] - 1;
            }
            $error['sql'] = isset($statements[$i]) ? mb_strimwidth($statements[$i]['sql'], 0, 400, '…') : null;
            $error['kind'] ??= $this->isTimeoutCode($error['code'] ?? null, $error['sqlstate'] ?? null) ? 'timeout' : 'sql';
        }

        if (! $readonly) {
            foreach ($statements as $s) {
                if (! Splitter::isRead($s['sql'], $this->dialect())) {
                    $this->store->flush();
                    break;
                }
            }
        }

        return [
            'statements' => count($statements),
            'ran' => count($results),
            'results' => $results,
            'error' => $error,
            'rolled_back' => $tx && $error !== null,
            'summary' => $summary,
        ];
    }

    // ── export ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $a
     * @return array{filename: string, mime: string, data: string, rows: int}
     */
    public function export(array $a): array
    {
        $table = $this->tableArg($a);
        $meta = $this->meta($table);
        $cols = $meta['columns'];
        $by = $this->byName($cols);
        $format = in_array($a['format'] ?? 'csv', ['csv', 'json', 'sql'], true) ? (string) ($a['format'] ?? 'csv') : 'csv';
        $limit = $this->int($a['limit'] ?? 0, 0, 5_000_000, 0);
        $where = $this->whereSql($a, $by);
        $order = $this->orderSql($a['sort'] ?? [], $by, $meta['key']);
        $sql = 'SELECT '.implode(', ', array_map(fn ($c) => $this->exportExpr($c), $cols)).' FROM '.$this->tableRef($table).$where.$order
            .($limit > 0 ? ' LIMIT '.$limit : '');
        $r = $this->query([['sql' => $sql, 'timeout' => self::WALL_TIME - 5]], ['output' => 192 * 1024 * 1024]);
        $rows = $r[0]['sets'][0]['rows'] ?? [];

        $base = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $this->database.'.'.($table['schema'] !== null && $table['schema'] !== 'public' ? $table['schema'].'.' : '').$table['name']);
        $data = match ($format) {
            'json' => $this->exportJson($cols, $rows),
            'sql' => $this->exportSql($table, $cols, $rows, (bool) ($a['structure'] ?? false)),
            default => $this->exportCsv($cols, $rows),
        };

        return [
            'filename' => $base.'.'.$format,
            'mime' => ['csv' => 'text/csv', 'json' => 'application/json', 'sql' => 'application/sql'][$format],
            'data' => $data,
            'rows' => count($rows),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cols
     * @param  list<list<?string>>  $rows
     */
    protected function exportCsv(array $cols, array $rows): string
    {
        $field = static function (?string $v): string {
            if ($v === null) {
                return '';
            }
            if ($v === '' || strpbrk($v, ",\"\r\n") !== false || $v !== trim($v)) {
                return '"'.str_replace('"', '""', $v).'"';
            }

            return $v;
        };
        // a BOM so spreadsheet apps read UTF-8 (Arabic, accents) correctly
        $out = "\xEF\xBB\xBF".implode(',', array_map(static fn ($c) => $field($c['name']), $cols))."\r\n";
        foreach ($rows as $row) {
            $line = [];
            foreach ($cols as $i => $c) {
                $v = $row[$i] ?? null;
                if ($v !== null && $c['cat'] === 'bin') {
                    $v = '0x'.strtolower((string) preg_replace('/^0x/i', '', $v));
                }
                $line[] = $field($v);
            }
            $out .= implode(',', $line)."\r\n";
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $cols
     * @param  list<list<?string>>  $rows
     */
    protected function exportJson(array $cols, array $rows): string
    {
        $items = [];
        foreach ($rows as $row) {
            $item = [];
            foreach ($cols as $i => $c) {
                $v = $row[$i] ?? null;
                if ($v !== null) {
                    $v = match (true) {
                        $c['cat'] === 'bin' => ['$hex' => strtolower((string) preg_replace('/^0x/i', '', $v))],
                        $c['cat'] === 'int' && preg_match('/^-?\d{1,15}$/', $v) === 1 => (int) $v,
                        $c['cat'] === 'bool' => in_array(strtolower($v), ['1', 't', 'true'], true),
                        $c['cat'] === 'json' => $this->jsonValue($v),
                        default => $v,
                    };
                }
                $item[$c['name']] = $v;
            }
            $items[] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return "[\n".implode(",\n", $items)."\n]\n";
    }

    /**
     * @param  array{schema: ?string, name: string}  $table
     * @param  list<array<string, mixed>>  $cols
     * @param  list<list<?string>>  $rows
     */
    protected function exportSql(array $table, array $cols, array $rows, bool $structure): string
    {
        $ref = $this->tableRef($table);
        $out = '-- '.$this->tableRef($table).' — '.count($rows).' rows, exported '.gmdate('Y-m-d H:i:s')." UTC\n\n";
        if ($structure) {
            $ddl = rtrim(trim((string) ($this->structure($table)['ddl'] ?? '')), ';');
            $out .= $ddl !== '' ? $ddl.";\n\n" : '';
        }
        $names = implode(', ', array_map(fn ($c) => $this->quoteIdent($c['name']), $cols));
        foreach (array_chunk($rows, 200) as $chunk) {
            $tuples = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($cols as $i => $c) {
                    $v = $row[$i] ?? null;
                    $vals[] = match (true) {
                        $v === null => 'NULL',
                        $c['cat'] === 'bin' => $this->binaryLiteral($this->hex($v)),
                        in_array($c['cat'], ['int', 'num'], true) && is_numeric($v) => $v,
                        default => $this->typedLiteral($c, $v),
                    };
                }
                $tuples[] = '('.implode(', ', $vals).')';
            }
            $out .= 'INSERT INTO '.$ref.' ('.$names.") VALUES\n".implode(",\n", $tuples).";\n";
        }

        return $out;
    }

    // ── structure changes ──────────────────────────────────────────────────────────────────

    /**
     * Builds, and unless "dry" runs, the SQL for one structure change made from the UI: create, rename, duplicate,
     * empty or drop a table; add, change or drop a column, an index, a foreign key or a check; table options and
     * maintenance. The UI first asks with "dry" and shows the SQL, then runs exactly that.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    public function ddl(array $a): array
    {
        [$statements, $tx] = $this->ddlStatements((string) ($a['action'] ?? ''), $a);
        if (! empty($a['dry'])) {
            return ['sql' => $statements];
        }
        $r = $this->execute($statements, ['tx' => $tx, 'timeout' => self::WALL_TIME - 5, 'warnings' => true, 'lock_timeout' => 20]);
        $this->store->flush();
        $results = [];
        foreach ($r['results'] as $k => $res) {
            $results[] = [
                'sql' => $statements[$k],
                'sets' => array_map(fn ($set) => [
                    'columns' => $set['columns'],
                    'rows' => array_map(fn ($row) => array_map(fn ($v) => $this->genericCell($v, 4000), $row), array_slice($set['rows'], 0, 500)),
                    'count' => count($set['rows']),
                    'more' => count($set['rows']) > 500,
                ], $res['sets']),
                'affected' => $res['affected'],
                'warnings' => $res['warnings'],
                'ms' => $res['ms'],
            ];
        }
        if ($r['error'] !== null) {
            $e = $this->sqlException($r['error']);
            throw new DbmException($e->getMessage(), $e->kind, $e->extra + [
                'sql' => $statements[$r['error']['i']] ?? null,
                'done' => count($results),
                'rolled_back' => $tx,
            ]);
        }

        return ['sql' => $statements, 'results' => $results];
    }

    protected const TYPE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?(?:\s*\(\s*\d+(?:\s*,\s*-?\d+)?\s*\))?(?:\s+[A-Za-z0-9_]+(?:\s*\(\s*\d+\s*\))?)*(?:\s*\[\s*\d*\s*\])*$/';

    /**
     * A new name for a table, column, index or constraint.
     */
    protected function nameArg(mixed $name, string $what): string
    {
        $max = $this->dialect() === Splitter::PGSQL ? 63 : 64;
        if (! is_string($name) || $name === '' || $name !== trim($name) || strlen($name) > $max || preg_match('/[\x00-\x1f]/', $name) === 1) {
            throw new DbmException("Enter a valid {$what} (at most {$max} characters, no leading or trailing spaces).", 'input');
        }

        return $name;
    }

    /**
     * A column type as typed in the UI: a type name with an optional size, modifiers and array brackets.
     * MySQL ENUM/SET are built here from their values.
     *
     * @param  array<string, mixed>  $spec
     */
    protected function typeSql(array $spec): string
    {
        $type = trim((string) ($spec['type'] ?? ''));
        if ($this->dialect() === Splitter::MYSQL && preg_match('/^(enum|set)$/i', $type) === 1) {
            $options = array_values(array_filter(array_map('strval', (array) ($spec['options'] ?? [])), static fn ($v) => $v !== ''));
            if ($options === []) {
                throw new DbmException('Give the '.strtoupper($type).' column at least one value.', 'input');
            }

            return strtolower($type).'('.implode(', ', array_map(fn ($v) => $this->literal($v), $options)).')';
        }
        if ($type === '' || strlen($type) > 100 || preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new DbmException(sprintf('"%s" is not a valid column type.', mb_strimwidth($type, 0, 60, '…')), 'input');
        }

        return $type;
    }

    /**
     * An SQL expression typed in the UI (a default, a CHECK, a generated column, a USING clause).
     */
    protected function exprArg(mixed $expr, string $what): string
    {
        $expr = trim(is_scalar($expr) ? (string) $expr : '');
        if ($expr === '' || ! Splitter::isExpression($expr, $this->dialect())) {
            throw new DbmException("{$what} must be one SQL expression, without comments or semicolons.", 'input');
        }

        return $expr;
    }

    protected function ruleSql(mixed $rule): string
    {
        $r = strtoupper(str_replace('_', ' ', trim((string) $rule)));
        if ($r === '') {
            return '';
        }
        if (! in_array($r, ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT'], true)) {
            throw new DbmException("Unknown foreign key action {$r}.", 'input');
        }

        return $r;
    }

    /**
     * Existing column names, checked against the table.
     *
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    protected function columnList(array $meta, mixed $names, string $what = 'columns'): array
    {
        $by = $this->byName($meta['columns']);
        $out = [];
        foreach (is_array($names) ? $names : [$names] as $n) {
            $n = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
            if (! isset($by[$n])) {
                throw new DbmException("Unknown column {$n}.", 'input');
            }
            $out[] = $n;
        }
        if ($out === []) {
            throw new DbmException("Choose the {$what}.", 'input');
        }

        return $out;
    }

    /**
     * Column specs of a create-table request.
     *
     * @param  array<string, mixed>  $a
     * @return list<array<string, mixed>>
     */
    protected function specsArg(array $a): array
    {
        $specs = array_values(array_filter((array) ($a['columns'] ?? []), 'is_array'));
        if ($specs === []) {
            throw new DbmException('Add at least one column.', 'input');
        }
        $seen = [];
        foreach ($specs as $s) {
            $name = $this->nameArg($s['name'] ?? null, 'column name');
            if (isset($seen[strtolower($name)])) {
                throw new DbmException("Column {$name} is listed twice.", 'input');
            }
            $seen[strtolower($name)] = true;
        }

        return $specs;
    }

    /**
     * The default of a column spec: none | null | literal | expr.
     *
     * @param  array<string, mixed>  $spec
     * @return array{kind: string, value: ?string}
     */
    protected function defaultArg(array $spec): array
    {
        $d = $spec['default'] ?? null;
        if (! is_array($d)) {
            return ['kind' => 'none', 'value' => null];
        }
        $kind = (string) ($d['kind'] ?? 'none');

        return match ($kind) {
            'none', 'null' => ['kind' => $kind, 'value' => null],
            'literal' => ['kind' => 'literal', 'value' => is_scalar($d['value'] ?? null) ? (string) $d['value'] : ''],
            'expr' => ['kind' => 'expr', 'value' => $this->exprArg($d['value'] ?? '', 'The default')],
            default => throw new DbmException("Unknown default kind {$kind}.", 'input'),
        };
    }

    /**
     * The canonical spelling when $expr is one of functions(), else null.
     */
    protected function knownFunction(string $expr): ?string
    {
        $norm = static fn (string $s): string => strtoupper((string) preg_replace('/\s+/', '', $s));
        foreach ($this->functions() as $f) {
            if ($norm($f) === $norm($expr)) {
                return $f;
            }
        }

        return null;
    }

    protected static function sh(string $s): string
    {
        return "'".str_replace("'", "'\\''", $s)."'";
    }

    protected function jsonValue(string $v): mixed
    {
        try {
            return json_decode($v, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $v;
        }
    }
}
