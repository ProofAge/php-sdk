<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

/**
 * The clock X-Timestamp is read from, shared by one client's SignMiddleware and RetryMiddleware.
 *
 * It is the host clock plus an offset. The offset stays 0 until the API refuses a timestamp
 * with 401 TIMESTAMP_OUT_OF_RANGE and sends a Date header: then it becomes the server's time
 * minus the host's, and every later request of the client is stamped in the server's time.
 * It also remembers the last timestamp it handed out, so a retry can wait for the next second:
 * the API accepts a timestamped non-GET request once, and the same second signs it identically.
 *
 * @internal
 */
final class Clock
{
    /** @var \Closure(): int */
    private readonly \Closure $source;

    private int $offset = 0;

    private ?int $lastStamp = null;

    /** @param  callable(): int|null  $source  Unix seconds; defaults to time() */
    public function __construct(?callable $source = null)
    {
        $this->source = $source === null ? time(...) : \Closure::fromCallable($source);
    }

    public static function from(self|callable|null $clock): self
    {
        return $clock instanceof self ? $clock : new self($clock);
    }

    /** Unix seconds, in the server's time once an offset is known. */
    public function now(): int
    {
        return ($this->source)() + $this->offset;
    }

    /** The timestamp for a request about to be signed; remembered as the last one. */
    public function stamp(): int
    {
        return $this->lastStamp = $this->now();
    }

    public function lastStamp(): ?int
    {
        return $this->lastStamp;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /** Takes the server's time from an HTTP Date header. False when it does not parse. */
    public function adjustToServerDate(?string $date): bool
    {
        if ($date === null || ($serverTime = strtotime($date)) === false) {
            return false;
        }

        $this->offset = $serverTime - ($this->source)();

        return true;
    }
}
