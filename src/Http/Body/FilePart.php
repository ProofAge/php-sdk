<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http\Body;

/**
 * One file in a multipart body.
 *
 * The contents are read once, at construction; the hash that is signed and the bytes
 * that are sent both come from that one buffer, so a file replaced on disk between
 * signing and sending cannot make them disagree.
 */
final class FilePart
{
    private ?string $sha256 = null;

    public function __construct(
        public readonly string $name,
        public readonly string $filename,
        public readonly string $contents,
        public readonly ?string $contentType = null,
    ) {}

    public function sha256(): string
    {
        return $this->sha256 ??= hash('sha256', $this->contents);
    }

    /**
     * string path   -> basename(path)
     * \SplFileInfo  -> getRealPath(); filename from getClientOriginalName() when the object has it
     *                  (Illuminate\Http\UploadedFile and Symfony's), else getFilename()
     * FilePart      -> as is
     *
     * @throws \InvalidArgumentException when a path does not exist or cannot be read
     */
    public static function from(string $name, string|\SplFileInfo|FilePart $file): self
    {
        if ($file instanceof self) {
            return $file;
        }

        if ($file instanceof \SplFileInfo) {
            $path = $file->getRealPath();
            $filename = method_exists($file, 'getClientOriginalName')
                ? (string) $file->getClientOriginalName()
                : $file->getFilename();

            return new self($name, $filename, self::read($path === false ? $file->getPathname() : $path));
        }

        return new self($name, basename($file), self::read($file));
    }

    private static function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        return $contents;
    }
}
