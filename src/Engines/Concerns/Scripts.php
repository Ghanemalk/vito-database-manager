<?php

namespace VitoDbManager\Engines\Concerns;

use VitoDbManager\Support\DbmException;

/**
 * Running statements in one client session and reading back what each one produced, or the error.
 *
 * Part of Engine, which is split by area (see there).
 */
trait Scripts
{
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
                $run->timedOut => sprintf(
                    'No answer within %d seconds. The statement may still be running on the server (see Monitor).',
                    $o['wall'],
                ),
                $run->truncated => sprintf(
                    'The result is larger than %d MB, so the rest was not read. Select fewer rows or columns.',
                    intdiv((int) $o['output'], 1048576),
                ),
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
}
