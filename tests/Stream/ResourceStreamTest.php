<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Stream;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Stream\ResourceStream;
use Psr\Http\Message\StreamInterface;

class ResourceStreamTest extends TestCase
{
    public function test_it_is_a_psr7_stream(): void
    {
        $this->assertInstanceOf(StreamInterface::class, ResourceStream::fromString(''));
    }

    public function test_from_string_is_readable_seekable_and_writable(): void
    {
        $stream = ResourceStream::fromString('hello');

        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());
        $this->assertTrue($stream->isWritable());
        $this->assertSame(5, $stream->getSize());
        $this->assertSame(0, $stream->tell());
        $this->assertSame('he', $stream->read(2));
        $this->assertSame(2, $stream->tell());
        $this->assertSame('llo', $stream->getContents());
        $this->assertTrue($stream->eof());

        $stream->rewind();
        $this->assertSame('hello', $stream->getContents());

        $stream->seek(1);
        $this->assertSame('ello', (string) $stream->read(10));

        $stream->seek(-2, SEEK_END);
        $this->assertSame('lo', $stream->getContents());
    }

    public function test_to_string_returns_the_whole_content_from_the_start(): void
    {
        $stream = ResourceStream::fromString('hello');
        $stream->read(3);

        $this->assertSame('hello', (string) $stream);
    }

    public function test_write_appends_and_reports_bytes_written(): void
    {
        $stream = ResourceStream::fromString('');

        $this->assertSame(5, $stream->write('hello'));
        $this->assertSame(5, $stream->getSize());
        $this->assertSame('hello', (string) $stream);
    }

    public function test_open_a_file_read_only(): void
    {
        $path = sys_get_temp_dir().'/proofage-stream-'.uniqid();
        file_put_contents($path, 'on disk');

        $stream = ResourceStream::open($path, 'rb');

        $this->assertTrue($stream->isReadable());
        $this->assertFalse($stream->isWritable());
        $this->assertSame(7, $stream->getSize());
        $this->assertSame('on disk', $stream->getContents());
        $this->assertSame($path, $stream->getMetadata('uri'));
        $this->assertIsArray($stream->getMetadata());

        $stream->close();
        unlink($path);
    }

    public function test_writing_to_a_read_only_stream_throws(): void
    {
        $path = sys_get_temp_dir().'/proofage-stream-'.uniqid();
        file_put_contents($path, '');
        $stream = ResourceStream::open($path, 'rb');

        try {
            $this->expectException(\RuntimeException::class);
            $stream->write('x');
        } finally {
            $stream->close();
            unlink($path);
        }
    }

    public function test_detach_returns_the_resource_and_leaves_the_stream_unusable(): void
    {
        $stream = ResourceStream::fromString('x');

        $resource = $stream->detach();

        $this->assertIsResource($resource);
        $this->assertNull($stream->detach());
        $this->assertNull($stream->getSize());
        $this->assertFalse($stream->isReadable());
        $this->assertFalse($stream->isSeekable());
        $this->assertTrue($stream->eof());
        $this->assertSame('', (string) $stream);
        $this->assertNull($stream->getMetadata('uri'));

        $this->expectException(\RuntimeException::class);
        $stream->read(1);
    }

    public function test_close_then_use_throws(): void
    {
        $stream = ResourceStream::fromString('x');
        $stream->close();

        $this->expectException(\RuntimeException::class);
        $stream->tell();
    }

    public function test_constructor_rejects_a_non_resource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new ResourceStream('not a resource');
    }
}
