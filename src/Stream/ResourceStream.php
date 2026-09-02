<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Stream;

use Psr\Http\Message\StreamInterface;

/**
 * Psr\Http\Message\StreamInterface over a PHP stream resource (php://temp, or a file).
 *
 * Parameters are left untyped on purpose: psr/http-message 1.x declares none and 2.x
 * declares them, and a parameter type may only widen in an implementation, so untyped
 * parameters with typed returns satisfy both.
 */
final class ResourceStream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    private bool $seekable = false;

    private bool $readable = false;

    private bool $writable = false;

    /**
     * @param  resource  $resource
     */
    public function __construct($resource)
    {
        if (! is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new \InvalidArgumentException('ResourceStream expects an open stream resource');
        }

        $this->resource = $resource;

        $meta = stream_get_meta_data($resource);
        $mode = $meta['mode'];

        $this->seekable = $meta['seekable'];
        $this->readable = str_contains($mode, 'r') || str_contains($mode, '+');
        $this->writable = str_contains($mode, '+') || strpbrk($mode, 'waxc') !== false;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** A php://temp stream holding $contents, rewound; spills to disk past 2 MB. */
    public static function fromString(string $contents = ''): self
    {
        $stream = new self(self::temp());

        if ($contents !== '') {
            $stream->write($contents);
            $stream->rewind();
        }

        return $stream;
    }

    public static function open(string $path, string $mode = 'rb'): self
    {
        $resource = @fopen($path, $mode);

        if ($resource === false) {
            throw new \RuntimeException("Could not open {$path} with mode {$mode}");
        }

        return new self($resource);
    }

    /** @return resource */
    public static function temp()
    {
        $resource = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($resource === false) {
            throw new \RuntimeException('Could not open php://temp');
        }

        return $resource;
    }

    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $resource = $this->detach();

        if ($resource !== null) {
            fclose($resource);
        }
    }

    /** @return resource|null */
    public function detach()
    {
        $resource = $this->resource;

        $this->resource = null;
        $this->seekable = $this->readable = $this->writable = false;

        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);

        return $stats === false ? null : $stats['size'];
    }

    public function tell(): int
    {
        $position = ftell($this->resourceOrFail());

        if ($position === false) {
            throw new \RuntimeException('Unable to determine stream position');
        }

        return $position;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * @param  int  $offset
     * @param  int  $whence
     */
    public function seek($offset, $whence = SEEK_SET): void
    {
        if (! $this->seekable) {
            throw new \RuntimeException('Stream is not seekable');
        }

        if (fseek($this->resourceOrFail(), $offset, $whence) === -1) {
            throw new \RuntimeException("Unable to seek to stream position {$offset} with whence {$whence}");
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    /**
     * @param  string  $string
     */
    public function write($string): int
    {
        if (! $this->writable) {
            throw new \RuntimeException('Cannot write to a non-writable stream');
        }

        $written = fwrite($this->resourceOrFail(), $string);

        if ($written === false) {
            throw new \RuntimeException('Unable to write to stream');
        }

        return $written;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * @param  int  $length
     */
    public function read($length): string
    {
        if (! $this->readable) {
            throw new \RuntimeException('Cannot read from a non-readable stream');
        }

        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        $data = fread($this->resourceOrFail(), $length);

        if ($data === false) {
            throw new \RuntimeException('Unable to read from stream');
        }

        return $data;
    }

    public function getContents(): string
    {
        if (! $this->readable) {
            throw new \RuntimeException('Cannot read from a non-readable stream');
        }

        $contents = stream_get_contents($this->resourceOrFail());

        if ($contents === false) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $contents;
    }

    /**
     * @param  string|null  $key
     */
    public function getMetadata($key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }

        $meta = stream_get_meta_data($this->resource);

        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }

    /** @return resource */
    private function resourceOrFail()
    {
        if ($this->resource === null) {
            throw new \RuntimeException('Stream is detached');
        }

        return $this->resource;
    }
}
