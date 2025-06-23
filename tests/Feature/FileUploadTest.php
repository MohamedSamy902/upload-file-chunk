<?php

namespace MohamedSamy902\AdvancedFileUpload\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use MohamedSamy902\AdvancedFileUpload\AdvancedFileUploadServiceProvider;
use MohamedSamy902\AdvancedFileUpload\Facades\FileUpload;

class FileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [AdvancedFileUploadServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return [
            'FileUpload' => \MohamedSamy902\AdvancedFileUpload\Facades\FileUpload::class,
        ];
    }

    public function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['file-upload.storage.disk' => 'local']);
    }

    /** @test */
    public function it_can_upload_an_image()
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $response = FileUpload::upload(new \Illuminate\Http\Request(['file' => $file]), 'file');

        $this->assertArrayHasKey('url', $response);
        $this->assertTrue(Storage::disk('local')->exists($response['path']));
    }

    /** @test */
    public function it_can_generate_thumbnails()
    {
        config(['file-upload.thumbnails.enabled' => true]);
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $response = FileUpload::upload(new \Illuminate\Http\Request(['file' => $file]), 'file');

        $this->assertArrayHasKey('thumbnail_urls', $response);
        $this->assertArrayHasKey('small', $response['thumbnail_urls']);
        $this->assertTrue(Storage::disk('local')->exists(dirname($response['path']) . '/thumb_small_test.jpg'));
    }

    /** @test */
    public function it_can_delete_a_file()
    {
        $file = UploadedFile::fake()->image('test.jpg', 100, 100);
        $response = FileUpload::upload(new \Illuminate\Http\Request(['file' => $file]), 'file');
        $deleteResponse = FileUpload::delete($response['path']);

        $this->assertEquals(['status' => 'تم حذف الملف بنجاح'], $deleteResponse);
        $this->assertFalse(Storage::disk('local')->exists($response['path']));
    }
}