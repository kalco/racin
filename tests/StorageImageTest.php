<?php

declare(strict_types=1);

namespace Racin\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Racin\Http\UploadedFile;
use Racin\Image\Image;
use Racin\Image\ImageUploader;
use Racin\Storage\Storage;
use Racin\Storage\StorageManager;
use Racin\Validation\ValidationException;
use Racin\Validation\Validator;
use RuntimeException;

#[RequiresPhpExtension('gd')]
final class StorageImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'racin-storage-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->dir);
    }

    public function testStorageReadsWritesAndBuildsUrls(): void
    {
        $disk = new Storage($this->dir . '/disk', 'https://cdn.test/files/');

        self::assertSame('docs/a.txt', $disk->put('docs/a.txt', 'hello'));
        self::assertTrue($disk->exists('docs/a.txt'));
        self::assertSame('hello', $disk->get('docs/a.txt'));
        self::assertSame(5, $disk->size('docs/a.txt'));
        self::assertSame('https://cdn.test/files/docs/a.txt', $disk->url('docs/a.txt'));
        self::assertNull($disk->url(null));

        $disk->delete(['docs/a.txt', 'docs/missing.txt']);
        self::assertFalse($disk->exists('docs/a.txt'));

        $this->expectException(RuntimeException::class);
        $disk->get('docs/a.txt');
    }

    public function testStorageRejectsPathsOutsideItsRoot(): void
    {
        $disk = new Storage($this->dir . '/disk');

        foreach (['../escape.txt', 'a/../../escape.txt', './x', 'C:/Windows/x', "a\0b", ''] as $path) {
            try {
                $disk->put($path, 'x');
                self::fail("Accepted unsafe path [{$path}].");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertFileDoesNotExist($this->dir . '/escape.txt');

        // A leading slash is relative to the disk root, never the filesystem root.
        self::assertSame('etc/passwd', $disk->put('/etc/passwd', 'x'));
        self::assertFileExists($this->dir . '/disk/etc/passwd');
    }

    public function testStorageManagerResolvesDisks(): void
    {
        $manager = new StorageManager([
            'default' => 'public',
            'disks' => ['public' => ['root' => $this->dir . '/pub', 'url' => 'http://x.test/u'], 'local' => ['root' => $this->dir . '/priv']],
        ]);

        self::assertSame('http://x.test/u/a.png', $manager->disk()->url('a.png'));
        self::assertNull($manager->disk('local')->url('a.png'));
        self::assertSame($manager->disk('local'), $manager->disk('local'));

        $this->expectException(InvalidArgumentException::class);
        $manager->disk('s3');
    }

    public function testImageResizeCropAndEncode(): void
    {
        $image = Image::fromFile($this->png(300, 100));
        self::assertSame([300, 100, 'image/png'], [$image->width(), $image->height(), $image->mime()]);

        $fitted = $image->fit(150, 150);
        self::assertSame([150, 50], [$fitted->width(), $fitted->height()]);

        $small = Image::fromFile($this->png(40, 30))->fit(150, 150);
        self::assertSame([40, 30], [$small->width(), $small->height()], 'never upscales');

        $cover = $image->cover(50, 50);
        self::assertSame([50, 50], [$cover->width(), $cover->height()]);

        $webp = $fitted->encode('webp', 80);
        self::assertStringStartsWith('RIFF', $webp);
        self::assertSame('WEBP', substr($webp, 8, 4));
        self::assertStringStartsWith("\xFF\xD8", $fitted->encode('jpeg'));
        self::assertStringStartsWith("\x89PNG", $fitted->encode('png'));

        $this->expectException(InvalidArgumentException::class);
        $fitted->encode('bmp');
    }

    public function testImageRejectsFilesThatAreNotImages(): void
    {
        $fake = $this->dir . '/src/fake.jpg';
        file_put_contents($fake, '<?php system($_GET["c"]);');

        $this->expectException(InvalidArgumentException::class);
        Image::fromFile($fake);
    }

    public function testUploaderStoresReencodedImageAndThumbnailAndDeletesBoth(): void
    {
        $disk = new Storage($this->dir . '/pub', 'http://x.test/u');
        $uploader = new ImageUploader($disk, 200, 200, 64, 64, 'webp', 80);

        $stored = $uploader->store(UploadedFile::fake($this->png(800, 400), 'photo.PNG'), 'products/7');

        self::assertMatchesRegularExpression('#^products/7/[0-9a-f]{32}\.webp$#', $stored['path']);
        self::assertSame(ImageUploader::thumbPath($stored['path']), $stored['thumb_path']);
        self::assertStringEndsWith('_thumb.webp', $stored['thumb_path']);
        self::assertSame([200, 100, 'image/webp'], [$stored['width'], $stored['height'], $stored['mime']]);
        self::assertSame($disk->size($stored['path']), $stored['size']);
        self::assertSame([64, 64], array_slice((array) getimagesize($disk->path($stored['thumb_path'])), 0, 2));

        $uploader->delete($stored['path']);
        self::assertFalse($disk->exists($stored['path']));
        self::assertFalse($disk->exists($stored['thumb_path']));
    }

    public function testUploaderTurnsBadFilesIntoValidationErrors(): void
    {
        $fake = $this->dir . '/src/fake.png';
        file_put_contents($fake, 'not an image');
        $uploader = new ImageUploader(new Storage($this->dir . '/pub'), 200, 200, 64, 64, 'webp', 80);

        try {
            $uploader->store(UploadedFile::fake($fake), 'x', 'avatar');
            self::fail('Expected a validation error.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('avatar', $e->errors());
        }

        self::assertDirectoryDoesNotExist($this->dir . '/pub/x');
    }

    public function testImageValidationRules(): void
    {
        $png = UploadedFile::fake($this->png(120, 80), 'a.png');
        $svg = $this->dir . '/src/a.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $svg = UploadedFile::fake($svg);
        $text = $this->dir . '/src/a.jpg';
        file_put_contents($text, 'plain text');
        $text = UploadedFile::fake($text);

        $passes = static fn (mixed $file, string $rules): bool => Validator::make(['f' => $file], ['f' => $rules])->passes();

        self::assertTrue($passes($png, 'image'));
        self::assertFalse($passes($svg, 'image'), 'SVG can carry scripts');
        self::assertFalse($passes($text, 'image'));
        self::assertFalse($passes('a.png', 'image'), 'a string is not a file');

        // mimes checks the detected type, not the client's file name.
        self::assertTrue($passes($png, 'mimes:jpg,png'));
        self::assertFalse($passes($png, 'mimes:jpg,webp'));
        self::assertFalse($passes($text, 'mimes:jpg'));

        self::assertTrue($passes($png, 'dimensions:min_width=100,max_height=80'));
        self::assertFalse($passes($png, 'dimensions:min_width=121'));
        self::assertTrue($passes($png, 'dimensions:width=120,height=80'));
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 30, 120, 200));
        $path = $this->dir . '/src/' . bin2hex(random_bytes(6)) . '.png';
        imagepng($image, $path);

        return $path;
    }
}
