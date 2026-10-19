<?php

namespace VitoDbManager\Engines\Concerns;

use VitoDbManager\Sql\Splitter;
use VitoDbManager\Support\DbmException;

/**
 * The SQL console: the script the user wrote, run statement by statement, with each result.
 *
 * Part of Engine, which is split by area (see there).
 */
trait Console
{
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
}
