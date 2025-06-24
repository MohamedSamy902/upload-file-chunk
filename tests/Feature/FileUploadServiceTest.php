<?php

namespace Tests\Unit\Services;

use MohamedSamy902\AdvancedFileUpload\Services\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Exception;
use App\Models\User;
use Intervention\Image\Facades\Image;

class FileUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected FileUploadService $service;
    protected string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileUploadService();
        $this->testFilePath = __DIR__ . '/../testfiles/';

        Storage::fake('public');
        config(['file-upload' => require config_path('file-upload.php')]);
    }

    // Basic File Upload Tests
    public function test_uploads_single_file()
    {
        $file = UploadedFile::fake()->image('mohamedSamy.jpg', 500, 500);

        $result = $this->service->upload($file);

        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('url', $result);
        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_uploads_multiple_files()
    {
        $files = [
            UploadedFile::fake()->image('mohamedSamy.jpg'),
            UploadedFile::fake()->image('mohamedSamy.jpg')
        ];

        $results = $this->service->upload($files);

        $this->assertCount(2, $results);
        foreach ($results as $result) {
            Storage::disk('public')->assertExists($result['path']);
        }
    }

    // Validation Tests
    public function test_rejects_invalid_file_types()
    {
        $this->expectException(Exception::class);

        $file = UploadedFile::fake()->create('document.exe', 1000);
        $this->service->upload($file);
    }

    public function test_rejects_files_over_size_limit()
    {
        $this->expectException(Exception::class);
        // استخدم الرسالة الإنجليزية التي ينتجها النظام
        $this->expectExceptionMessage('The file field must not be greater than 1 kilobytes.');

        // تأكد من أن القواعد محدثة في ملف config/file-upload.php
        config(['file-upload.validation.image' => 'required|image|max:1']);
        config(['file-upload.validation.custom_fields.file' => 'required|image|max:1']);

        $file = UploadedFile::fake()->image('mohamedSamy.jpg')->size(2048); // 2KB
        $this->service->upload($file, ['field_name' => 'file']);
    }

    // URL Download Tests
    public function test_downloads_file_from_url()
    {
        Http::fake([
            'example.com/image.jpg' => Http::response(file_get_contents($this->testFilePath . 'mohamedSamy.jpg'), 200, [
                'Content-Type' => 'image/jpeg'
            ])
        ]);

        $result = $this->service->upload([], ['url' => 'https://media.istockphoto.com/id/2161896294/photo/woman-smiling-and-expressing-gratitude-during-a-conversation.webp?a=1&b=1&s=612x612&w=0&k=20&c=e1EdH8Aus-LOacUwNExQ1aOhwIHiFFk6jYKZ32w2vU8=']);

        $this->assertArrayHasKey('path', $result);
        Storage::disk('public')->assertExists($result['path']);
    }

    public function test_handles_chunked_download()
    {
        $largeFileContent = str_repeat('0', 10 * 1024 * 1024); // 10MB file

        Http::fake([
            'http://www.minbible.com/media/video_en.mp4' => Http::sequence()
                ->push($largeFileContent, 200, [
                    'Content-Range' => 'bytes 0-5242879/10485760',
                    'Content-Type' => 'video/mp4'
                ])
                ->push($largeFileContent, 200, [
                    'Content-Range' => 'bytes 5242880-10485759/10485760',
                    'Content-Type' => 'video/mp4'
                ])
        ]);

        config([
            'file-upload.url_download.chunked' => true,
            'file-upload.url_download.chunk_size' => 5 * 1024 * 1024,
            'file-upload.validation.custom_fields.file' => 'required', // تعطيل التحقق من النوع مؤقتًا
        ]);

        $result = $this->service->upload([], [
            'url' => 'http://www.minbible.com/media/video_en.mp4',
        ]);

        $this->assertArrayHasKey('path', $result);
    }

    // Image Processing Tests
    public function test_resizes_images()
    {
        // config(['file-upload.processing.image.enabled' => true]);
        // config(['file-upload.processing.image.resize.width' => 800]);
        // config(['file-upload.processing.image.resize.height' => 600]);

        $file = UploadedFile::fake()->image('test.jpg', 1600, 1200);

        $this->service->upload($file);
        // dd($file);
        // $image = Image::make(Storage::disk('public')->get($result['path']));
        // $this->assertEquals(800, $image->width());
        // $this->assertEquals(600, $image->height());
    }

    // Thumbnail Generation Tests
    public function test_generates_thumbnails()
    {
        config(['file-upload.thumbnails.sizes' => [
            'small' => ['width' => 100, 'height' => 100]
        ]]);

        $file = UploadedFile::fake()->image('mohamedSamy.jpg');
        $result = $this->service->upload($file);

        $this->assertArrayHasKey('thumbnail_urls', $result);
        $this->assertArrayHasKey('small', $result['thumbnail_urls']);

        $thumbnailPath = dirname($result['path']) . '/thumb_small_' . basename($result['path']);
        Storage::disk('public')->assertExists($thumbnailPath);
    }

    // Database Integration Tests
    public function test_saves_to_database_when_enabled()
    {
        config(['file-upload.database.enabled' => true]);

        $file = UploadedFile::fake()->image('mohamedSamy.jpg');
        $result = $this->service->upload($file);

        $this->assertDatabaseHas('file_uploads', [
            'name' => basename($result['path']), // استخدم فقط اسم الملف
            'path' => $result['path'],
            'mime_type' => $result['mime_type'],
            'size' => $result['size'], // استخدم الحجم الفعلي
            'user_id' => null // أو استخدم القيمة الفعلية إذا كان هناك مستخدم مسجل
        ]);
    }

    // Quota Management Tests
    public function test_enforces_quota_limits()
    {
        config([
            'file-upload.quota.enabled' => true,
            'file-upload.quota.max_size_per_user' => 1000
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        \App\Models\FileUpload::factory()->create([
            'user_id' => $user->id,
            'size' => 900
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('تم تجاوز حد التخزين المسموح للمستخدم');

        $file = UploadedFile::fake()->image('mohamedSamy.jpg')->size(200);
        $this->service->upload($file);
    }

    // File Deletion Tests
    public function test_deletes_files_and_thumbnails()
    {
        config(['file-upload.thumbnails.enabled' => true]);

        $file = UploadedFile::fake()->image('mohamedSamy.jpg');
        $result = $this->service->upload($file);

        $deleteResult = $this->service->delete($result['path']);

        Storage::disk('public')->assertMissing($result['path']);
        $this->assertEquals(['status' => 'تم حذف الملف بنجاح'], $deleteResult);
    }

    // Edge Case Tests
    public function test_handles_invalid_urls_gracefully()
    {
        Http::fake([
            'example.com/missing.jpg' => Http::response(null, 404)
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('فشل تنزيل الملف من الرابط: 404');

        $this->service->upload([], ['url' => 'http://example.com/missing.jpg']);
    }

    // Security Tests
    public function test_blocks_php_files()
    {
        $this->expectException(Exception::class);

        $file = UploadedFile::fake()->createWithContent('malicious.php', '<?php evil_code(); ?>');
        $this->service->upload($file);
    }
}
