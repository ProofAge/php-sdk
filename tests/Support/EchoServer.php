<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Support;

/**
 * Runs tests/Support/echo-server.php on PHP's built-in server for the `network-local`
 * test group. A free port is taken from the kernel, the server is started with
 * proc_open() and a few workers (so a deliberately slow request does not stall the
 * rest of the suite), and the port is polled until it accepts connections.
 */
final class EchoServer
{
    /** @param resource $process */
    private function __construct(
        public readonly int $port,
        private $process,
    ) {}

    public static function start(): self
    {
        $port = self::freePort();
        $env = getenv();
        $env['PHP_CLI_SERVER_WORKERS'] = '4';

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/echo-server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            __DIR__,
            $env,
        );

        if ($process === false) {
            throw new \RuntimeException('Could not start the echo server');
        }

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($socket !== false) {
                fclose($socket);

                $server = new self($port, $process);
                register_shutdown_function(static fn () => $server->stop());

                return $server;
            }

            usleep(50_000);
        }

        proc_terminate($process);
        throw new \RuntimeException("Echo server did not accept connections on port {$port}");
    }

    public function url(string $path = '/'): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    /**
     * The built-in server does not reap its workers when the parent is signalled, and an
     * orphaned worker keeps every inherited pipe open, which stalls whatever is waiting on
     * PHPUnit's output. So the workers are killed first, then the parent.
     */
    private bool $stopped = false;

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $status = proc_get_status($this->process);

        if ($status['running']) {
            exec(sprintf('pkill -TERM -P %d 2>/dev/null', $status['pid']));
            proc_terminate($this->process, SIGTERM);
        }

        proc_close($this->process);
    }

    /** A TCP port nothing is listening on. */
    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if ($server === false) {
            throw new \RuntimeException('Could not bind a free port');
        }

        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
