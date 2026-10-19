<?php

use VitoDbManager\Transport\Transport;
use VitoDbManager\Transport\TransportResult;

/**
 * Runs the client on this machine for the development server. It passes the script and the output through
 * temporary files instead of pipes, because PHP cannot wait on process pipes on Windows.
 */
final class FileTransport implements Transport
{
    public function __construct(private readonly string $shell = 'sh') {}

    public function run(string $command, string $stdin, int $timeout, int $maxBytes): TransportResult
    {
        $input = tempnam(sys_get_temp_dir(), 'vdbm');
        $output = tempnam(sys_get_temp_dir(), 'vdbm');
        $errors = tempnam(sys_get_temp_dir(), 'vdbm');
        file_put_contents($input, $stdin);

        $process = proc_open(
            [$this->shell, '-c', $command],
            [0 => ['file', $input, 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            return new TransportResult('', 'could not start '.$this->shell, null, false, false);
        }

        $deadline = microtime(true) + max(1, $timeout);
        $truncated = false;
        $timedOut = false;
        $exitCode = null;
        while (true) {
            // the exit code is only reported once, by the first status call after the process ends
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            clearstatcache(true, $output);
            if (filesize($output) > $maxBytes) {
                $truncated = true;
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                break;
            }
            usleep(20000);
        }
        if ($truncated || $timedOut) {
            proc_terminate($process);
        }
        proc_close($process);

        $result = new TransportResult(
            (string) file_get_contents($output, length: $maxBytes + 1),
            (string) file_get_contents($errors),
            $exitCode,
            $truncated,
            $timedOut,
        );
        @unlink($input);
        @unlink($output);
        @unlink($errors);

        return $result;
    }
}
