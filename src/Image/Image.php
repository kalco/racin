<?php

declare(strict_types=1);

namespace Racin\Image;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Small GD wrapper: open (JPEG, PNG, GIF, WebP), fix phone rotation, resize, encode.
 *
 * Re-encoding an upload drops metadata (EXIF/GPS) and anything hidden in the
 * file besides the pixels, so only clean images are stored.
 */
final class Image
{
    public const array MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Refuse images that would need more memory than this to decode ("decompression bombs"). */
    public const int MAX_PIXELS = 40_000_000;

    private function __construct(private GdImage $image, private readonly string $mime)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('The "gd" PHP extension is required for image processing.');
        }

        $info = @getimagesize($path);

        if ($info === false || !isset(self::MIME_EXTENSIONS[$info['mime']])) {
            throw new InvalidArgumentException('Not a supported image (JPEG, PNG, GIF or WebP).');
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new InvalidArgumentException("Image dimensions {$width}x{$height} are not allowed.");
        }

        $image = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
        };

        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('The image file is damaged.');
        }

        $self = new self($image, $info['mime']);

        if ($info['mime'] === 'image/jpeg') {
            $self->applyExifOrientation($path);
        }

        return $self;
    }

    public function width(): int
    {
        return imagesx($this->image);
    }

    public function height(): int
    {
        return imagesy($this->image);
    }

    public function mime(): string
    {
        return $this->mime;
    }

    /**
     * Shrink (never enlarge) to fit inside the box, keeping the aspect ratio.
     */
    public function fit(int $maxWidth, int $maxHeight): self
    {
        $scale = min($maxWidth / $this->width(), $maxHeight / $this->height(), 1.0);

        if ($scale >= 1.0) {
            return $this->copy();
        }

        return $this->resampled(max(1, (int) round($this->width() * $scale)), max(1, (int) round($this->height() * $scale)), 0, 0, $this->width(), $this->height());
    }

    /**
     * Scale and center-crop to exactly $width x $height (thumbnails).
     */
    public function cover(int $width, int $height): self
    {
        $scale = max($width / $this->width(), $height / $this->height());
        $sourceWidth = (int) round($width / $scale);
        $sourceHeight = (int) round($height / $scale);

        return $this->resampled(
            $width,
            $height,
            intdiv($this->width() - $sourceWidth, 2),
            intdiv($this->height() - $sourceHeight, 2),
            $sourceWidth,
            $sourceHeight,
        );
    }

    /**
     * Encode to webp | jpeg | png and return the bytes.
     */
    public function encode(string $format = 'webp', int $quality = 82): string
    {
        $image = match ($format) {
            'webp', 'png' => $this->image,
            'jpeg', 'jpg' => $this->flattened(),
            default => throw new InvalidArgumentException("Unsupported output format [{$format}]."),
        };

        ob_start();

        try {
            $ok = match ($format) {
                'webp' => imagewebp($image, null, $quality),
                'png' => imagepng($image, null, 6),
                default => imagejpeg($image, null, $quality),
            };
        } finally {
            $data = (string) ob_get_clean();
        }

        if (!$ok || $data === '') {
            throw new RuntimeException("Unable to encode the image as {$format}.");
        }

        return $data;
    }

    private function resampled(int $width, int $height, int $sourceX, int $sourceY, int $sourceWidth, int $sourceHeight): self
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $this->image, 0, 0, $sourceX, $sourceY, $width, $height, $sourceWidth, $sourceHeight);

        return new self($canvas, $this->mime);
    }

    private function copy(): self
    {
        return $this->resampled($this->width(), $this->height(), 0, 0, $this->width(), $this->height());
    }

    /**
     * JPEG has no transparency: paint transparent pixels white.
     */
    private function flattened(): GdImage
    {
        $canvas = imagecreatetruecolor($this->width(), $this->height());
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $this->image, 0, 0, 0, 0, $this->width(), $this->height());

        return $canvas;
    }

    private function applyExifOrientation(string $path): void
    {
        if (!function_exists('exif_read_data')) {
            return;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($this->image, 180, 0),
            6 => imagerotate($this->image, -90, 0),
            8 => imagerotate($this->image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            $this->image = $rotated;
        }
    }
}
