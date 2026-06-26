<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MohamedSamy902\AdvancedFileUpload\Models\FileUpload;
use MohamedSamy902\AdvancedFileUpload\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;

class MediaManagerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        
        // Ensure the database is enabled for these tests
        $app['config']->set('file-upload.database.enabled', true);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
        ])->run();

        Storage::fake('public');
        Storage::fake('s3');
    }

    public function test_dashboard_index_loads_with_correct_stats()
    {
        FileUpload::create([
            'name' => 'image1.jpg',
            'path' => 'image1.jpg',
            'original_name' => 'image1.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 1024,
            'is_used' => true,
        ]);

        FileUpload::create([
            'name' => 'doc1.pdf',
            'path' => 'doc1.pdf',
            'original_name' => 'doc1.pdf',
            'disk' => 'public',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 2048,
            'is_used' => false,
        ]);

        $response = $this->get(route('advanced-file-upload.index'));

        $response->assertStatus(200);
        $response->assertViewIs('advanced-file-upload::dashboard.index');
        
        $stats = $response->viewData('stats');
        $this->assertEquals(2, $stats['total_files']);
        $this->assertEquals(3072, $stats['total_size']);
        $this->assertEquals(1, $stats['used_files']);
        $this->assertEquals(1, $stats['unused_files']);
        $this->assertEquals(1, $stats['images']);
        $this->assertEquals(1, $stats['documents']);
    }

    public function test_media_library_filters_results_correctly()
    {
        $usedImage = FileUpload::create([
            'name' => 'used.jpg',
            'path' => 'used.jpg',
            'original_name' => 'used.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 1000,
            'is_used' => true,
            'model_type' => 'App\Models\User',
        ]);

        $unusedDoc = FileUpload::create([
            'name' => 'unused.pdf',
            'path' => 'unused.pdf',
            'original_name' => 'unused.pdf',
            'disk' => 'public',
            'mime_type' => 'application/pdf',
            'type' => 'document',
            'size' => 2000,
            'is_used' => false,
            'model_type' => 'App\Models\Article',
        ]);

        // Test All filter
        $response = $this->get(route('advanced-file-upload.media'));
        $response->assertStatus(200);
        $this->assertCount(2, $response->viewData('files'));

        // Test Used filter
        $response = $this->get(route('advanced-file-upload.media', ['filter' => 'used']));
        $this->assertCount(1, $response->viewData('files'));
        $this->assertEquals('used.jpg', $response->viewData('files')->first()->original_name);

        // Test Type filter
        $response = $this->get(route('advanced-file-upload.media', ['filter' => 'documents']));
        $this->assertCount(1, $response->viewData('files'));
        $this->assertEquals('unused.pdf', $response->viewData('files')->first()->original_name);

        // Test Model Type filter
        $response = $this->get(route('advanced-file-upload.media', ['model_type' => 'App\Models\Article']));
        $this->assertCount(1, $response->viewData('files'));
        $this->assertEquals('unused.pdf', $response->viewData('files')->first()->original_name);
    }

    public function test_media_library_search_returns_correct_file()
    {
        FileUpload::create([
            'name' => 'apple.jpg',
            'path' => 'apple.jpg',
            'original_name' => 'apple.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 1000,
        ]);

        FileUpload::create([
            'name' => 'banana.jpg',
            'path' => 'banana.jpg',
            'original_name' => 'banana.jpg',
            'disk' => 'public',
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'size' => 1000,
        ]);

        $response = $this->get(route('advanced-file-upload.media', ['search' => 'apple']));
        $response->assertStatus(200);
        $this->assertCount(1, $response->viewData('files'));
        $this->assertEquals('apple.jpg', $response->viewData('files')->first()->original_name);
    }

    public function test_media_library_calculates_dynamic_stats_based_on_filter()
    {
        FileUpload::create([
            'name' => 'image.jpg', 'path' => 'image.jpg', 'original_name' => 'image.jpg', 'disk' => 'public',
            'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 1048576, // 1 MB
            'is_used' => true, 'model_type' => 'User',
        ]);

        FileUpload::create([
            'name' => 'doc.pdf', 'path' => 'doc.pdf', 'original_name' => 'doc.pdf', 'disk' => 'public',
            'mime_type' => 'application/pdf', 'type' => 'document', 'size' => 2097152, // 2 MB
            'is_used' => false, 'model_type' => 'Article',
        ]);

        $response = $this->get(route('advanced-file-upload.media', ['model_type' => 'Article']));
        $response->assertStatus(200);

        $stats = $response->viewData('stats');
        $this->assertEquals(1, $stats['total']);
        $this->assertEquals('2 MB', $stats['size']);
        $this->assertEquals(0, $stats['used']);
        $this->assertEquals(1, $stats['unused']);
    }

    public function test_api_bulk_destroy_soft_deletes_files()
    {
        $file1 = FileUpload::create(['name' => 'f1', 'path' => 'f1.jpg', 'original_name' => 'f1', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);
        $file2 = FileUpload::create(['name' => 'f2', 'path' => 'f2.jpg', 'original_name' => 'f2', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);

        $response = $this->postJson(route('advanced-file-upload.media.bulk-destroy'), [
            'ids' => [$file1->id, $file2->id]
        ]);

        $response->assertStatus(200);
        $this->assertSoftDeleted('file_uploads', ['id' => $file1->id]);
        $this->assertSoftDeleted('file_uploads', ['id' => $file2->id]);
    }

    public function test_api_bulk_force_destroy_removes_physical_files_and_db_records()
    {
        Storage::disk('public')->put('f1.jpg', 'fake content');
        $file1 = FileUpload::create([
            'name' => 'f1', 'path' => 'f1.jpg', 'original_name' => 'f1', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100,
            'metadata' => ['thumbnails' => ['small' => '/storage/f1_small.jpg']]
        ]);
        Storage::disk('public')->put('f1_small.jpg', 'fake thumb');

        $this->assertTrue(Storage::disk('public')->exists('f1.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('f1_small.jpg'));

        $response = $this->postJson(route('advanced-file-upload.media.bulk-force-destroy'), [
            'ids' => [$file1->id]
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('file_uploads', ['id' => $file1->id]);
        $this->assertFalse(Storage::disk('public')->exists('f1.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('f1_small.jpg'));
    }

    public function test_api_scan_detects_orphaned_database_records()
    {
        // Exists on disk
        Storage::disk('public')->put('exists.jpg', 'content');
        FileUpload::create(['name' => 'exists', 'path' => 'exists.jpg', 'original_name' => 'exists', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);

        // Missing from disk
        $orphaned = FileUpload::create(['name' => 'missing', 'path' => 'missing.jpg', 'original_name' => 'missing', 'disk' => 'public', 'mime_type' => 'image/jpeg', 'type' => 'image', 'size' => 100]);

        $response = $this->postJson(route('advanced-file-upload.scan'));
        
        $response->assertStatus(200)
                 ->assertJsonPath('orphaned_count', 1)
                 ->assertJsonPath('orphaned_ids.0', $orphaned->id);
    }
}
