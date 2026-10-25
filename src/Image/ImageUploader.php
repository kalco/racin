<?php

declare(strict_types=1);

namespace Racin\Image;

use Racin\Http\UploadedFile;
use Racin\Storage\Storage;
use Racin\Validation\ValidationException;

/**
 * Stores an uploaded image safely: the content is decoded (whatever the file name
 * claims), re-encoded, resized and saved under a random name, with a thumbnail.
 *
 *     $stored = $uploader->store($request->file('image'), 'products/12');
 *     // ['path' => 'products/12/3f9c….webp', 'thumb_path' => 'products/12/3f9c…_thumb.webp',
 *     //  'width' => 1600, 'height' => 1200, 'size' => 183421, 'mime' => 'image/webp']
 */
final class ImageUploader
{
    public function __construct(
        private readonly Storage $disk,
        private readonly int $maxWidth = 1600,
        private readonly int $maxHeight = 1600,
        private readonly int $thumbWidth = 400,
        private readonly int $thumbHeight = 400,
        private readonly string $format = 'webp',
        private readonly int $quality = 82,
    ) {
    }

    public function disk(): Storage
    {
        return $this->disk;
    }

    /**
     * @param string $directory relative folder on the disk, e.g. "products/12"
     * @return array{path: string, thumb_path: string, width: int, height: int, size: int, mime: string}
     */
    public function store(UploadedFile $file, string $directory, string $field = 'image'): array
    {
        if (!$file->isValid()) {
            throw ValidationException::withMessages([$field => 'The upload failed.']);
        }

        try {
            $image = Image::fromFile($file->path);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }

        $main = $image->fit($this->maxWidth, $this->maxHeight);
        $name = bin2hex(random_bytes(16));
        $extension = $this->format === 'jpeg' ? 'jpg' : $this->format;
        $directory = trim($directory, '/');

        $path = $this->disk->put("{$directory}/{$name}.{$extension}", $main->encode($this->format, $this->quality));
        $thumb = $this->disk->put("{$directory}/{$name}_thumb.{$extension}", $image->cover($this->thumbWidth, $this->thumbHeight)->encode($this->format, $this->quality));

        return [
            'path' => $path,
            'thumb_path' => $thumb,
            'width' => $main->width(),
            'height' => $main->height(),
            'size' => $this->disk->size($path),
            'mime' => 'image/' . ($this->format === 'jpg' ? 'jpeg' : $this->format),
        ];
    }

    /**
     * Delete a stored image and its thumbnail.
     */
    public function delete(?string $path, ?string $thumbPath = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->disk->delete([$path, $thumbPath ?? self::thumbPath($path)]);
    }

    /**
     * Thumbnail path for an image path stored by this uploader ("a/b.webp" → "a/b_thumb.webp").
     */
    public static function thumbPath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $dot = strrpos($path, '.');

        return $dot === false ? $path . '_thumb' : substr($path, 0, $dot) . '_thumb' . substr($path, $dot);
    }
}
