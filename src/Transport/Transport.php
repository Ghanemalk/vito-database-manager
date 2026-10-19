<?php

namespace VitoDbManager\Transport;

/**
 * Runs one shell command on the database server with a script on its standard input.
 */
interface Transport
{
    /**
     * @param  int  $timeout  seconds for the whole command
     * @param  int  $maxBytes  stop reading standard output after this many bytes (the result is marked truncated)
     */
    public function run(string $command, string $stdin, int $timeout, int $maxBytes): TransportResult;
}
