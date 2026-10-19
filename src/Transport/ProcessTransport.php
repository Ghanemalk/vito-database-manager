<?php

namespace VitoDbManager\Transport;

/**
 * Runs the command on this machine. Used by the test suite (the clients run inside Docker containers) and
 * usable when Vito and the database share a host.
 */
final class ProcessTransport implements Transport
{
    public function run(string $command, string $stdin, int $timeout, int $maxBytes): TransportResult
    {
        $process = proc_open(['/bin/sh', '-c', $command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return new TransportResult('', 'could not start the process', null, false, false);
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $written = 0;
        $truncated = false;
        $timedOut = false;
        $deadline = microtime(true) + max(1, $timeout);
        $stdinOpen = true;
        if ($stdin === '') {
            fclose($pipes[0]);
            $stdinOpen = false;
        }

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $stdinOpen ? [$pipes[0]] : [];
            $except = null;
            $read = array_filter($read, static fn ($p) => ! feof($p));
            if ($read === [] && $write === []) {
                break;
            }
            if (@stream_select($read, $write, $except, 0, 200000) === false) {
                break;
            }
            foreach ($write as $pipe) {
                $n = fwrite($pipe, substr($stdin, $written, 65536));
                $written += $n === false ? 0 : $n;
                if ($written >= strlen($stdin)) {
                    fclose($pipes[0]);
                    $stdinOpen = false;
                }
            }
            foreach ($read as $pipe) {
                $chunk = (string) fread($pipe, 1 << 20);
                if ($pipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            if (strlen($stdout) > $maxBytes) {
                $truncated = true;
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                break;
            }
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if ($truncated || $timedOut) {
            proc_terminate($process);
        }
        $exit = proc_close($process);

        return new TransportResult($stdout, $stderr, $exit, $truncated, $timedOut);
    }
}
