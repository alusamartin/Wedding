<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stage 3/4 regression — image-only upload validation across every model
 * that accepts a file upload:
 *
 *   Gallery (upload/gallery), EventWedding (upload/events),
 *   Category (upload/categories) and Users (upload/users).
 *
 * All four set_file() implementations must:
 *  - accept a real image and store it under a randomized 16-hex-char name
 *    (no user-controlled filename survives);
 *  - reject a .php payload;
 *  - reject a file whose extension says image but whose content is not an
 *    image (getimagesize sniff);
 *  - reject an upload that carries a PHP upload error.
 *
 * Pure PHP — no database, no session, no HTTP. Fixture files live in a
 * temp dir that is removed after the suite.
 */
final class ImageUploadValidationTest extends TestCase
{
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DB_Object', false)) {
            require_once __DIR__ . '/../admin/include/db_object.php';
        }
        foreach (['Gallery', 'EventWedding', 'Categories', 'Users'] as $model) {
            if (!class_exists(($model === 'Categories' ? 'Category' : $model), false)) {
                require_once __DIR__ . '/../admin/include/' . $model . '.php';
            }
        }

        self::$dir = sys_get_temp_dir() . '/wpms_upload_test_' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0700, true);

        file_put_contents(self::$dir . '/good.png', base64_decode(self::PNG_1PX, true));
        file_put_contents(self::$dir . '/payload.php', "<?php echo 'pwned';");
        // Extension claims JPEG; content is plain text — must fail getimagesize().
        file_put_contents(self::$dir . '/fake.jpg', 'not really an image');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['good.png', 'payload.php', 'fake.jpg'] as $file) {
            $path = self::$dir . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir(self::$dir)) {
            @rmdir(self::$dir);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function uploadTargets(): array
    {
        return [
            'Gallery'      => ['Gallery', 'filename'],
            'EventWedding' => ['EventWedding', 'preview_image'],
            'Category'     => ['Category', 'preview_image'],
            'Users'        => ['Users', 'profile_picture'],
        ];
    }

    private function fileUploadArray(string $name, string $tmpPath, int $error = 0): array
    {
        return [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $tmpPath,
            'error'    => $error,
            'size'     => is_file($tmpPath) ? filesize($tmpPath) : 0,
        ];
    }

    private function target(string $class): object
    {
        return new $class();
    }

    #[DataProvider('uploadTargets')]
    public function testRealImageIsAcceptedUnderRandomizedName(string $class, string $field): void
    {
        $model = $this->target($class);
        $upload = $this->fileUploadArray('wedding-photo.png', self::$dir . '/good.png');

        // set_file() returns null on success, false on rejection — never true.
        self::assertNotFalse($model->set_file($upload), $class . ' must accept a real PNG');
        self::assertSame([], $model->errors, $class . ' must not record errors for a valid image');
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{16}\.png$/',
            (string) $model->$field,
            $class . ' must store the upload under a randomized 16-hex-char name, not the user filename'
        );
        self::assertNotSame('wedding-photo.png', $model->$field, 'User-controlled filename must never be used');
    }

    #[DataProvider('uploadTargets')]
    public function testPhpPayloadIsRejected(string $class, string $field): void
    {
        $model = $this->target($class);
        $upload = $this->fileUploadArray('shell.php', self::$dir . '/payload.php');

        self::assertFalse($model->set_file($upload), $class . ' must reject a .php payload');
        self::assertNotEmpty($model->errors, $class . ' must explain the rejection');
        self::assertStringContainsString('Only JPG/PNG/GIF/WebP images up to 5 MB are allowed.', implode(' ', $model->errors));
        self::assertNull($model->$field ?? null, $class . ' must not store a name for a rejected file');
    }

    #[DataProvider('uploadTargets')]
    public function testDisguisedNonImageIsRejected(string $class, string $field): void
    {
        $model = $this->target($class);
        // .jpg extension with text content — the getimagesize() sniff must catch it.
        $upload = $this->fileUploadArray('photo.jpg', self::$dir . '/fake.jpg');

        self::assertFalse($model->set_file($upload), $class . ' must reject a non-image disguised with an image extension');
        self::assertNotEmpty($model->errors, $class . ' must explain the rejection');
        self::assertStringContainsString('Only JPG/PNG/GIF/WebP images up to 5 MB are allowed.', implode(' ', $model->errors));
        self::assertNull($model->$field ?? null, $class . ' must not store a name for a rejected file');
    }

    #[DataProvider('uploadTargets')]
    public function testPhpUploadErrorIsRejected(string $class, string $field): void
    {
        $model = $this->target($class);
        $upload = $this->fileUploadArray('photo.png', self::$dir . '/good.png', UPLOAD_ERR_NO_FILE);

        self::assertFalse($model->set_file($upload), $class . ' must reject an upload with a PHP error code');
        self::assertNotEmpty($model->errors, $class . ' must explain the rejection');
        self::assertNull($model->$field ?? null, $class . ' must not store a name for a failed upload');
    }
}
