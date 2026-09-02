<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Tests\Http\Body;

use PHPUnit\Framework\TestCase;
use ProofAge\Sdk\Http\Body\FilePart;

class FilePartTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/proofage-filepart-'.uniqid().'.jpg';
        file_put_contents($this->path, 'not-really-a-jpeg');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_sha256_is_the_hash_of_the_contents(): void
    {
        $part = new FilePart('file', 'front.jpg', 'not-really-a-jpeg');

        $this->assertSame(hash('sha256', 'not-really-a-jpeg'), $part->sha256());
        $this->assertSame($part->sha256(), $part->sha256());
    }

    public function test_from_a_path_reads_the_file_once_and_uses_its_basename(): void
    {
        $part = FilePart::from('file', $this->path);

        $this->assertSame('file', $part->name);
        $this->assertSame(basename($this->path), $part->filename);
        $this->assertSame('not-really-a-jpeg', $part->contents);
        $this->assertNull($part->contentType);

        // Bytes read at construction are the bytes signed and sent, even if the file changes after.
        file_put_contents($this->path, 'replaced');
        $this->assertSame(hash('sha256', 'not-really-a-jpeg'), $part->sha256());
    }

    public function test_from_a_missing_path_throws(): void
    {
        $missing = sys_get_temp_dir().'/proofage-does-not-exist-'.uniqid().'.jpg';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("File not found: {$missing}");

        FilePart::from('file', $missing);
    }

    public function test_from_an_spl_file_info_uses_its_filename(): void
    {
        $part = FilePart::from('file', new \SplFileInfo($this->path));

        $this->assertSame(basename($this->path), $part->filename);
        $this->assertSame('not-really-a-jpeg', $part->contents);
    }

    public function test_from_an_uploaded_file_shape_prefers_the_client_original_name(): void
    {
        $uploaded = new class($this->path) extends \SplFileInfo
        {
            public function getClientOriginalName(): string
            {
                return 'selfie from phone.jpg';
            }
        };

        $part = FilePart::from('file', $uploaded);

        $this->assertSame('selfie from phone.jpg', $part->filename);
        $this->assertSame('not-really-a-jpeg', $part->contents);
    }

    public function test_from_a_file_part_returns_it_as_is(): void
    {
        $part = new FilePart('file', 'x.jpg', 'bytes', 'image/jpeg');

        $this->assertSame($part, FilePart::from('file', $part));
    }
}
