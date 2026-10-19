<?php

namespace VitoDbManager\Transport;

use phpseclib3\Exception\InsufficientSetupException;
use phpseclib3\Exception\TimeoutException;
use phpseclib3\Net\SSH2;

/**
 * Runs the command on the database server over SSH (phpseclib 3), feeding the script to its standard input.
 * One connection is opened lazily and reused for the commands of a request.
 */
final class SshTransport implements Transport
{
    private ?SSH2 $ssh = null;

    /**
     * @param  \Closure(): SSH2  $connect  opens an authenticated connection
     */
    public function __construct(private readonly \Closure $connect) {}

    public function run(string $command, string $stdin, int $timeout, int $maxBytes): TransportResult
    {
        $timeout = max(1, $timeout);
        $deadline = microtime(true) + $timeout;
        $ssh = $this->ssh ??= ($this->connect)();
        $ssh->enableQuietMode(); // standard error apart from standard output
        $ssh->setTimeout($timeout);

        // the server stops the command on its own too, in case this side goes away first
        if ($ssh->exec('timeout -k 5 '.($timeout + 5).' sh -c '.escapeshellarg($command), false) !== true) {
            $this->drop();

            return new TransportResult('', 'The command could not be started over SSH.', null, false, false);
        }

        $stdout = '';
        $truncated = false;
        $timedOut = false;
        try {
            if ($stdin !== '') {
                $ssh->write($stdin, SSH2::CHANNEL_EXEC);
            }
            $ssh->sendEOF(SSH2::CHANNEL_EXEC);
            while (true) {
                $left = $deadline - microtime(true);
                if ($left <= 0) {
                    $timedOut = true;
                    break;
                }
                $ssh->setTimeout($left);
                $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
                if (is_string($chunk)) {
                    $stdout .= $chunk;
                    if (strlen($stdout) > $maxBytes) {
                        $truncated = true;
                        break;
                    }

                    continue;
                }
                // true: the channel closed, or the wait timed out
                $timedOut = $ssh->isTimeout();
                break;
            }
        } catch (TimeoutException) {
            $timedOut = true;
        } catch (InsufficientSetupException) {
            // the channel had already closed
        }

        $stderr = (string) $ssh->getStdError();
        $exit = $ssh->getExitStatus();
        if ($truncated || $timedOut) {
            // the channel is still open: a fresh connection is simpler than draining it
            $this->drop();
        }

        return new TransportResult($stdout, $stderr, is_int($exit) ? $exit : null, $truncated, $timedOut);
    }

    private function drop(): void
    {
        try {
            $this->ssh?->disconnect();
        } catch (\Throwable) {
        }
        $this->ssh = null;
    }

    public function __destruct()
    {
        $this->drop();
    }
}
