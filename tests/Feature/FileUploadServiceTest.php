<?php

namespace MohamedSamy902\AdvancedFileUpload\Tests\Feature;

use MohamedSamy902\AdvancedFileUpload\Services\FileUploadService;
use MohamedSamy902\AdvancedFileUpload\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Exception;

class FileUploadServiceTest extends TestCase
{
    protected FileUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileUploadService();
        Storage::fake('public');
    }

    // =========================================================================
    // Upload — Direct File
    // =========================================================================

    public function test_uploads_single_image_file(): void
    {
        $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');
        $result = $this->service->upload($file);

        $this->assertTrue($result['status']);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('original_name', $result);
        $this->assertEquals('photo.jpg', $result['original_name']);
        $this->assertEquals('image', $result['type']);
        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_uploads_multiple_files_as_array(): void
    {
        $files = [
            UploadedFile::fake()->create('a.jpg', 50, 'image/jpeg'),
            UploadedFile::fake()->create('b.jpg', 50, 'image/jpeg'),
            UploadedFile::fake()->create('c.jpg', 50, 'image/jpeg'),
        ];

        $results = $this->service->upload($files);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertTrue($result['status']);
            Storage::disk('public')->assertExists($result['path']);
        }
    }

    public function test_upload_uses_uuid_filename_not_original(): void
    {
        $file = UploadedFile::fake()->create('my-secret-name.jpg', 50, 'image/jpeg');
        $result = $this->service->upload($file);

        // stored filename must be a UUID, not the original name
        $storedName = basename($result['path']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z]+$/',
            $storedName
        );
        // but original name must be preserved in result
        $this->assertEquals('my-secret-name.jpg', $result['original_name']);
    }

    // =========================================================================
    // Upload — Custom Options
    // =========================================================================

    public function test_upload_to_custom_disk(): void
    {
        Storage::fake('s3');
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $result = $this->service->upload($file, ['disk' => 's3']);

        Storage::disk('s3')->assertExists($result['path']);
        Storage::disk('public')->assertMissing($result['path']);
    }

    public function test_upload_to_custom_folder(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
        $result = $this->service->upload($file, ['folder_name' => 'reports']);

        $this->assertStringContainsString('reports/', $result['path']);
        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_upload_with_no_folder_uses_base_path_only(): void
    {
        $this->app['config']->set('file-upload.storage.default_folder', null);
        $file = UploadedFile::fake()->create('flat.pdf', 100, 'application/pdf');

        $result = $this->service->upload($file, ['folder_name' => null]);

        // path should not have nested folder
        $this->assertEquals(1, substr_count(trim($result['path'], '/'), '/'));
    }

    // =========================================================================
    // Upload — File Types & MIME Detection
    // =========================================================================

    public function test_detects_pdf_type_correctly(): void
    {
        $file = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');
        $result = $this->service->upload($file);

        $this->assertEquals('pdf', $result['type']);
    }

    public function test_detects_video_type_correctly(): void
    {
        $file = UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4');

        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:mp4,mov,avi'],
        ]);

        $this->assertEquals('video', $result['type']);
    }

    public function test_detects_audio_type_correctly(): void
    {
        $file = UploadedFile::fake()->create('song.mp3', 200, 'audio/mpeg');

        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:mp3,wav,ogg'],
        ]);

        $this->assertEquals('audio', $result['type']);
    }

    public function test_detects_document_type_correctly(): void
    {
        $file = UploadedFile::fake()->create('word.docx', 100,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:doc,docx,pdf'],
        ]);

        $this->assertEquals('document', $result['type']);
    }

    // =========================================================================
    // Upload — Validation
    // =========================================================================

    public function test_rejects_oversized_file(): void
    {
        $this->expectException(Exception::class);

        $this->app['config']->set('file-upload.validation.custom_fields.file', 'required|file|max:1');
        $file = UploadedFile::fake()->create('big.pdf', 5000, 'application/pdf');

        $this->service->upload($file);
    }

    public function test_accepts_file_with_custom_validation_rules(): void
    {
        $file = UploadedFile::fake()->create('data.csv', 50, 'text/csv');

        $result = $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:csv,txt'],
        ]);

        $this->assertTrue($result['status']);
    }

    public function test_rejects_disallowed_mime_from_custom_rules(): void
    {
        $this->expectException(Exception::class);

        $file = UploadedFile::fake()->create('script.sh', 10, 'application/x-sh');
        $this->service->upload($file, [
            'validation_rules' => ['file' => 'required|file|mimes:jpg,png,pdf'],
        ]);
    }

    // =========================================================================
    // Upload — URL Download
    // =========================================================================

    // URL tests use a loose validation rule because Http::fake returns raw bytes
    // that fileinfo cannot detect as a specific MIME type (no GD extension available).
    // In production, real downloaded files will have correct MIME types.
    private function urlTestOptions(array $extra = []): array
    {
        return array_merge([
            'validation_rules' => ['file' => 'required|file|max:51200'],
        ], $extra);
    }

    public function test_downloads_and_uploads_from_url(): void
    {
        Http::fake([
            'https://cdn.example.com/photo.jpg' => Http::response(
                str_repeat('x', 1024), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $result = $this->service->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/photo.jpg']));

        $this->assertTrue($result['status']);
        $this->assertArrayHasKey('path', $result);
        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_url_upload_preserves_original_filename_from_url(): void
    {
        Http::fake([
            'https://cdn.example.com/profile-picture.jpg' => Http::response(
                str_repeat('y', 512), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $result = $this->service->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/profile-picture.jpg']));

        $this->assertEquals('profile-picture.jpg', $result['original_name']);
    }

    public function test_url_upload_uses_fallback_filename_when_url_has_none(): void
    {
        Http::fake([
            'https://cdn.example.com/' => Http::response(
                str_repeat('z', 256), 200, ['Content-Type' => 'image/png']
            ),
        ]);

        $result = $this->service->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/']));

        $this->assertStringEndsWith('.png', $result['original_name']);
    }

    public function test_url_upload_fails_on_404(): void
    {
        $this->expectException(Exception::class);

        Http::fake([
            'https://cdn.example.com/missing.jpg' => Http::response('', 404),
        ]);

        $this->service->upload([], ['url' => 'https://cdn.example.com/missing.jpg']);
    }

    public function test_url_upload_retries_on_429(): void
    {
        Http::fake([
            'https://cdn.example.com/rate-limited.jpg' => Http::sequence()
                ->push('', 429)
                ->push('', 429)
                ->push(str_repeat('a', 512), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $result = $this->service->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/rate-limited.jpg']));

        $this->assertTrue($result['status']);
    }

    public function test_url_upload_fails_when_exceeds_max_size(): void
    {
        $this->expectException(Exception::class);

        $this->app['config']->set('file-upload.url_download.max_size', 100);

        Http::fake([
            'https://cdn.example.com/huge.jpg' => Http::response(
                str_repeat('x', 10000), 200, ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $this->service->upload([], ['url' => 'https://cdn.example.com/huge.jpg']);
    }

    public function test_url_upload_blocks_disallowed_mime_type(): void
    {
        $this->expectException(Exception::class);

        Http::fake([
            'https://cdn.example.com/script.php' => Http::response(
                '<?php echo "hi";', 200, ['Content-Type' => 'application/x-php']
            ),
        ]);

        $this->service->upload([], ['url' => 'https://cdn.example.com/script.php']);
    }

    public function test_url_upload_handles_content_type_with_charset(): void
    {
        // Content-Type header with charset params must be stripped before MIME validation
        Http::fake([
            'https://cdn.example.com/image.jpg' => Http::response(
                str_repeat('b', 512), 200, ['Content-Type' => 'image/jpeg; charset=binary']
            ),
        ]);

        $result = $this->service->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/image.jpg']));

        $this->assertTrue($result['status']);
    }

    public function test_multiple_url_uploads(): void
    {
        Http::fake([
            'https://cdn.example.com/a.jpg' => Http::response(str_repeat('a', 256), 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/b.jpg' => Http::response(str_repeat('b', 256), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $opts = $this->urlTestOptions([
            'url' => [
                'https://cdn.example.com/a.jpg',
                'https://cdn.example.com/b.jpg',
            ],
        ]);

        $results = $this->service->upload([], $opts);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['status']);
        $this->assertTrue($results[1]['status']);
    }

    public function test_multiple_url_uploads_continue_on_partial_failure(): void
    {
        Http::fake([
            'https://cdn.example.com/ok.jpg'   => Http::response(str_repeat('c', 256), 200, ['Content-Type' => 'image/jpeg']),
            'https://cdn.example.com/fail.jpg' => Http::response('', 500),
        ]);

        $opts = $this->urlTestOptions([
            'url' => [
                'https://cdn.example.com/ok.jpg',
                'https://cdn.example.com/fail.jpg',
            ],
        ]);

        $results = $this->service->upload([], $opts);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['status']);
        $this->assertFalse($results[1]['status']);
        $this->assertArrayHasKey('error', $results[1]);
    }

    // =========================================================================
    // Upload — Simple vs Chunked Download Mode
    // =========================================================================

    public function test_simple_download_mode_works(): void
    {
        $this->app['config']->set('file-upload.url_download.chunked', false);

        Http::fake([
            'https://cdn.example.com/photo.jpg' => Http::response(str_repeat('d', 1024), 200, [
                'Content-Type'   => 'image/jpeg',
                'Content-Length' => '1024',
            ]),
        ]);

        $result = $this->service->upload([], $this->urlTestOptions(['url' => 'https://cdn.example.com/photo.jpg']));

        $this->assertTrue($result['status']);
    }

    // =========================================================================
    // Delete
    // =========================================================================

    public function test_deletes_file_by_path_without_database(): void
    {
        $this->app['config']->set('file-upload.database.enabled', false);

        $file = UploadedFile::fake()->create('todelete.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file);

        $this->service->delete($result['path']);

        Storage::disk('public')->assertMissing($result['path']);
    }

    public function test_delete_returns_success_status(): void
    {
        $this->app['config']->set('file-upload.database.enabled', false);

        $file = UploadedFile::fake()->create('todelete2.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file);

        $deleteResult = $this->service->delete($result['path']);

        $this->assertTrue($deleteResult['status']);
        $this->assertArrayHasKey('message', $deleteResult);
    }

    public function test_deletes_multiple_files(): void
    {
        $this->app['config']->set('file-upload.database.enabled', false);

        $paths = [];
        foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
            $file = UploadedFile::fake()->create($name, 50, 'application/pdf');
            $paths[] = $this->service->upload($file)['path'];
        }

        $results = $this->service->delete($paths);

        $this->assertCount(3, $results);
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_delete_throws_on_nonexistent_file_without_database(): void
    {
        $this->expectException(Exception::class);
        $this->app['config']->set('file-upload.database.enabled', false);

        $this->service->delete('uploads/nonexistent/ghost.jpg');
    }

    public function test_delete_without_database_requires_string_path(): void
    {
        $this->expectException(Exception::class);
        $this->app['config']->set('file-upload.database.enabled', false);

        $this->service->delete(999); // numeric ID not allowed without DB
    }

    // =========================================================================
    // CDN URL Generation
    // =========================================================================

    public function test_returns_cdn_url_when_cdn_enabled(): void
    {
        $this->app['config']->set('file-upload.storage.cdn.enabled', true);
        $this->app['config']->set('file-upload.storage.cdn.url', 'https://cdn.myapp.com');

        $file = UploadedFile::fake()->create('asset.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file);

        $this->assertStringStartsWith('https://cdn.myapp.com/', $result['url']);
    }

    public function test_returns_storage_url_when_cdn_disabled(): void
    {
        $this->app['config']->set('file-upload.storage.cdn.enabled', false);

        $file = UploadedFile::fake()->create('asset2.pdf', 50, 'application/pdf');
        $result = $this->service->upload($file);

        $this->assertStringNotContainsString('cdn.myapp.com', $result['url']);
    }

    // =========================================================================
    // Invalid Input Handling
    // =========================================================================

    public function test_invalid_direct_file_throws_exception(): void
    {
        $this->expectException(Exception::class);

        $this->service->upload('this-is-not-a-file');
    }

    public function test_invalid_file_in_array_returns_error_entry(): void
    {
        $files = [
            UploadedFile::fake()->create('valid.jpg', 50, 'image/jpeg'),
            'not-a-file',
        ];

        $results = $this->service->upload($files);

        $this->assertTrue($results[0]['status']);
        $this->assertFalse($results[1]['status']);
        $this->assertArrayHasKey('error', $results[1]);
    }
}
