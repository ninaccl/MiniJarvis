<?php

declare(strict_types=1);

namespace Tests\Upload;

use App\Http\ApiException;
use App\Upload\FileMover;
use App\Upload\UploadService;
use PHPUnit\Framework\TestCase;

final class UploadServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/jarvis-upload-' . bin2hex(random_bytes(5));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/**/*') ?: [] as $file) {
            if (is_file($file)) unlink($file);
        }
    }

    public function testMimeIsSniffedAndOriginalFilenameIsNeverUsed(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jarvis-png-');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $mover = new RecordingMover();
        $service = new UploadService($this->directory, '/uploads', $mover);

        $result = $service->store(['tmp_name' => $tmp, 'name' => '../../attack.php', 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK]);

        self::assertMatchesRegularExpression('#^/uploads/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $result['url']);
        self::assertStringNotContainsString('attack', $mover->destination);
    }

    public function testRejectsSpoofedMimeOversizeAndTraversalInput(): void
    {
        $text = tempnam(sys_get_temp_dir(), 'jarvis-text-');
        file_put_contents($text, 'not an image');
        $service = new UploadService($this->directory, '/uploads', new RecordingMover());

        $this->assertApiError(fn () => $service->store(['tmp_name' => $text, 'name' => 'fake.jpg', 'size' => 12, 'error' => 0]), 'UPLOAD_INVALID_TYPE');
        $this->assertApiError(fn () => $service->store(['tmp_name' => $text, 'name' => 'x.png', 'size' => 5 * 1024 * 1024 + 1, 'error' => 0]), 'UPLOAD_TOO_LARGE');
        $this->assertApiError(fn () => $service->store(['tmp_name' => '../etc/passwd', 'name' => 'x.png', 'size' => 10, 'error' => 0]), 'UPLOAD_INVALID');
    }

    private function assertApiError(callable $action, string $code): void
    {
        try {
            $action();
            self::fail('Expected ' . $code);
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame($code, $exception->errorCode());
        }
    }
}

final class RecordingMover implements FileMover
{
    public string $destination = '';

    public function move(string $source, string $destination): bool
    {
        $this->destination = $destination;
        return copy($source, $destination);
    }
}
