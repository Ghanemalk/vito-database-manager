<?php

namespace VitoDbManager\Engines;

use VitoDbManager\Engines\Mysql\Expressions;
use VitoDbManager\Engines\Mysql\Metadata;
use VitoDbManager\Engines\Mysql\Scripts;
use VitoDbManager\Engines\Mysql\Structure;
use VitoDbManager\Sql\Splitter;

/**
 * MySQL (8.4, 9.x) and MariaDB (10.11 and later), through the server's own mysql / mariadb client.
 *
 * The client reads the script in batch mode and prints every result set as XML (--xml), which keeps NULL apart
 * from the text "NULL" and carries any value. An error goes to stderr and ends the script (there is no --force),
 * so the statement that failed is the one that began and never ended.
 *
 * This file holds the dialect: quoting, literals and names. The rest is split by area into the traits in
 * Mysql/: Scripts, Metadata and Structure are the MySQL side of Engine's parts of the same name, and
 * Expressions holds the SQL fragments that Engine's Rows, Filters and Writes ask for.
 */
final class MysqlEngine extends Engine
{
    use Scripts;
    use Metadata;
    use Expressions;
    use Structure;

    private const LONG = ['tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'json'];

    private const GEOMETRY = [
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
        'geomcollection',
    ];

    /** Turns NO_BACKSLASH_ESCAPES off: the client has to read backslashes in strings the way the splitter does. */
    private const KEEP_ESCAPES = "SET SESSION sql_mode = TRIM(BOTH ',' FROM"
        ." REPLACE(CONCAT(',', @@SESSION.sql_mode, ','), ',NO_BACKSLASH_ESCAPES,', ','))";

    public function dialect(): string
    {
        return Splitter::MYSQL;
    }

    private function maria(): bool
    {
        return $this->flavor === 'mariadb';
    }

    public function quoteIdent(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * Plain quoted text when it reads back character for character; otherwise hexadecimal, so a backslash,
     * a control character or invalid UTF-8 never depends on sql_mode or on how the client reads lines.
     */
    public function literal(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            return $this->binaryLiteral($value);
        }
        if (preg_match('/[\\\\\x00-\x08\x0b-\x1f\x7f]/', $value) !== 1) {
            return "'".str_replace("'", "''", $value)."'";
        }

        return "_utf8mb4 X'".bin2hex($value)."'";
    }

    public function binaryLiteral(string $raw): string
    {
        return "X'".bin2hex($raw)."'";
    }

    public function tableRef(array $table): string
    {
        return $this->quoteIdent($table['name']);
    }

    public function functions(): array
    {
        $common = ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP(3)', 'CURRENT_TIMESTAMP(6)', 'NOW()', 'CURRENT_DATE', 'CURRENT_TIME',
            'UTC_TIMESTAMP()', 'UTC_DATE()', 'UNIX_TIMESTAMP()', 'UUID()'];

        return $this->maria()
            ? [...$common, 'SYS_GUID()', 'UUID_v4()', 'UUID_v7()']
            : [...$common, 'UUID_TO_BIN(UUID())', 'UUID_TO_BIN(UUID(), 1)'];
    }
}
