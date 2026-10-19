<?php

namespace VitoDbManager\Engines;

use VitoDbManager\Engines\Postgres\Expressions;
use VitoDbManager\Engines\Postgres\Metadata;
use VitoDbManager\Engines\Postgres\Scripts;
use VitoDbManager\Engines\Postgres\Structure;
use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * PostgreSQL (15 to 18) through psql as the postgres superuser, the way Vito itself manages PostgreSQL.
 *
 * psql prints each result as CSV with a random NULL marker. The script echoes a marker line before and after
 * every statement and, after each one, checks :ERROR and stops at the first failure, echoing the SQLSTATE and
 * the message, so a failed statement never lets the ones after it run.
 *
 * This file holds the dialect: quoting, literals and names. The rest is split by area into the traits in
 * Postgres/: Scripts, Metadata and Structure are the PostgreSQL side of Engine's parts of the same name, and
 * Expressions holds the SQL fragments that Engine's Rows, Filters and Writes ask for.
 */
final class PostgresEngine extends Engine
{
    use Scripts;
    use Metadata;
    use Expressions;
    use Structure;

    /** @var array<int, array{0: int, 1: int}> script lines [first, last] of each statement, for placing notices */
    private array $lines = [];

    /** @var array<int, bool> statements wrapped in SELECT * FROM (…) LIMIT n */
    private array $wrapped = [];

    public function dialect(): string
    {
        return Splitter::PGSQL;
    }

    private function marker(): string
    {
        return '__VDBM_'.$this->tag.'__';
    }

    private function nullToken(): string
    {
        return 'vdbm_null_'.$this->tag;
    }

    public function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * A standard string when it holds no backslash or control character, else an E'' string with those escaped,
     * so the value reads back the same whatever standard_conforming_strings says.
     */
    public function literal(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new DbmException('This value is not valid UTF-8 text.', 'input');
        }
        if (str_contains($value, "\0")) {
            throw new DbmException('PostgreSQL text cannot contain NUL characters.', 'input');
        }
        if (preg_match('/[\\\\\x01-\x08\x0b-\x1f\x7f]/', $value) !== 1) {
            return "'".str_replace("'", "''", $value)."'";
        }

        return "E'".preg_replace_callback('/[\\\\\'\x01-\x08\x0b-\x1f\x7f]/', static fn ($m) => match ($m[0]) {
            '\\' => '\\\\',
            "'" => "''",
            "\r" => '\\r',
            default => sprintf('\\x%02X', ord($m[0])),
        }, $value)."'";
    }

    public function binaryLiteral(string $raw): string
    {
        return "decode('".bin2hex($raw)."', 'hex')";
    }

    public function tableRef(array $table): string
    {
        return $this->quoteIdent($table['schema'] ?? 'public').'.'.$this->quoteIdent($table['name']);
    }

    private function regclass(array $table): string
    {
        return 'to_regclass('.$this->literal($this->tableRef($table)).')';
    }

    public function functions(): array
    {
        return ['now()', 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME', 'LOCALTIMESTAMP', 'clock_timestamp()',
            'gen_random_uuid()', 'uuidv7()', 'CURRENT_USER'];
    }
}
