<?php

declare(strict_types=1);

namespace Racin\Http;

use RuntimeException;

final class UploadedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $clientName,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
        private readonly bool $test = false,
    ) {
    }

    /**
     * @param array{tmp_name: string, name: string, size: int, error: int} $file
     */
    public static function fromArray(array $file): self
    {
        return new self((string) $file['tmp_name'], (string) $file['name'], (int) $file['size'], (int) $file['error']);
    }

    /**
     * Create an instance for an existing file (tests, CLI imports).
     */
    public static function fake(string $path, ?string $clientName = null): self
    {
        return new self($path, $clientName ?? basename($path), (int) filesize($path), UPLOAD_ERR_OK, true);
    }

    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->test ? is_file($this->path) : is_uploaded_file($this->path);
    }

    /**
     * MIME type detected from the file contents (never trust the client's type).
     */
    public function mimeType(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($this->path);

        return $mime === false ? null : $mime;
    }

    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    /**
     * Move the file into $directory. Returns the new full path.
     */
    public function move(string $directory, ?string $name = null): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException('Cannot move an invalid upload.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        $name = basename($name ?? bin2hex(random_bytes(16)) . ($this->clientExtension() !== '' ? '.' . $this->clientExtension() : ''));
        $target = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;

        $moved = $this->test ? rename($this->path, $target) : move_uploaded_file($this->path, $target);

        if (!$moved) {
            throw new RuntimeException("Unable to move uploaded file to [{$target}].");
        }

        return $target;
    }
}
