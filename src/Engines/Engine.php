<?php

namespace VitoDbManager\Engines;

use VitoDbManager\Engines\Concerns\Console;
use VitoDbManager\Engines\Concerns\Export;
use VitoDbManager\Engines\Concerns\Filters;
use VitoDbManager\Engines\Concerns\Metadata;
use VitoDbManager\Engines\Concerns\Rows;
use VitoDbManager\Engines\Concerns\Scripts;
use VitoDbManager\Engines\Concerns\Structure;
use VitoDbManager\Engines\Concerns\Writes;
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
 *
 * This file holds what every engine must provide (the dialect section) and handle(), where every operation
 * starts. The shared code is split by area into the traits in Concerns/: Scripts, Metadata, Rows, Filters,
 * Writes, Console, Export and Structure.
 */
abstract class Engine
{
    use Scripts;
    use Metadata;
    use Rows;
    use Filters;
    use Writes;
    use Console;
    use Export;
    use Structure;

    public const OUTPUT_LIMIT = 64 * 1024 * 1024;

    /** Seconds one request may take; Vito's nginx gives up after 60. */
    public const WALL_TIME = 55;

    /**
     * A column type ddl() accepts, one line per part: a name, with a schema in front if any (public.mood); an optional
     * (size) or (precision, scale); more words such as unsigned or "with time zone", each with an optional (size); and
     * [] for arrays.
     */
    protected const TYPE_PATTERN = '/^'
        .'[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?'
        .'(?:\s*\(\s*\d+(?:\s*,\s*-?\d+)?\s*\))?'
        .'(?:\s+[A-Za-z0-9_]+(?:\s*\(\s*\d+\s*\))?)*'
        .'(?:\s*\[\s*\d*\s*\])*'
        .'$/';

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
            'mysql', 'mariadb' => new MysqlEngine(
                $transport,
                $database,
                $flavor,
                $client ?? 'sudo -n '.($flavor === 'mariadb' ? 'mariadb' : 'mysql'),
                $store,
            ),
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
}
