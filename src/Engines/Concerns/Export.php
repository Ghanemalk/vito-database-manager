<?php

namespace VitoDbManager\Engines\Concerns;

/**
 * Exporting rows as CSV, JSON or SQL.
 *
 * Part of Engine, which is split by area (see there).
 */
trait Export
{
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

        $base = preg_replace(
            '/[^A-Za-z0-9_.-]+/',
            '_',
            $this->database.'.'.($table['schema'] !== null && $table['schema'] !== 'public' ? $table['schema'].'.' : '').$table['name'],
        );
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
}
