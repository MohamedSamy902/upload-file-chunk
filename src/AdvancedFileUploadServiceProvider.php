<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload;

use Illuminate\Support\ServiceProvider;
use MohamedSamy902\AdvancedFileUpload\Contracts\FileUploadContract;
use MohamedSamy902\AdvancedFileUpload\Contracts\ImageProcessorContract;
use MohamedSamy902\AdvancedFileUpload\Contracts\QuotaManagerContract;
use MohamedSamy902\AdvancedFileUpload\Contracts\SsrfValidatorContract;
use MohamedSamy902\AdvancedFileUpload\Security\SsrfValidator;
use MohamedSamy902\AdvancedFileUpload\Services\FileUploadService;
use MohamedSamy902\AdvancedFileUpload\Services\FileValidator;
use MohamedSamy902\AdvancedFileUpload\Services\ImageProcessor;
use MohamedSamy902\AdvancedFileUpload\Services\MimeTypeResolver;
use MohamedSamy902\AdvancedFileUpload\Services\QuotaManager;
use MohamedSamy902\AdvancedFileUpload\Services\ResumableUploadService;
use MohamedSamy902\AdvancedFileUpload\Services\StorageManager;
use MohamedSamy902\AdvancedFileUpload\Services\UrlDownloader;

/**
 * Registers and bootstraps the Advanced File Upload package services.
 *
 * All service bindings use the contract interfaces as keys so that
 * application code can override any implementation by rebinding the
 * contract in the application's own service provider.
 */
class AdvancedFileUploadServiceProvider extends ServiceProvider
{
    /**
     * Registers all package bindings into the service container.
     *
     * Bindings are declared in dependency order: low-level utilities first,
     * then services that depend on them, then the top-level orchestrator.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/file-upload.php', 'file-upload');

        $this->registerInfrastructure();
        $this->registerServices();
        $this->registerOrchestrator();
    }

    /**
     * Publishes package assets and loads view paths.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/file-upload.php' => config_path('file-upload.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->publishMigrations();
            $this->publishAssets();
            $this->publishViews();
        }

        $this->loadViewsFrom(
            __DIR__ . '/../resources/views/advanced-file-upload',
            'advanced-file-upload'
        );
    }

    /**
     * Registers low-level infrastructure: SSRF validator, MIME resolver, image processor.
     *
     * These are stateless utilities with no dependencies on other package services.
     * They are registered as singletons to avoid redundant instantiation.
     */
    private function registerInfrastructure(): void
    {
        $this->app->singleton(MimeTypeResolver::class);

        $this->app->singleton(SsrfValidatorContract::class, SsrfValidator::class);

        $this->app->singleton(ImageProcessorContract::class, fn () => ImageProcessor::fromConfig());
    }

    /**
     * Registers mid-level services: file validator, URL downloader, storage manager, quota manager.
     *
     * Each service depends only on infrastructure bindings registered above.
     */
    private function registerServices(): void
    {
        $this->app->singleton(FileValidator::class);

        $this->app->singleton(UrlDownloader::class, fn ($app) => new UrlDownloader(
            ssrfValidator: $app->make(SsrfValidatorContract::class),
            fileValidator: $app->make(FileValidator::class),
            mimeResolver:  $app->make(MimeTypeResolver::class),
        ));

        $this->app->singleton(StorageManager::class, fn ($app) => new StorageManager(
            imageProcessor: $app->make(ImageProcessorContract::class),
            mimeResolver:   $app->make(MimeTypeResolver::class),
        ));

        $this->app->singleton(QuotaManagerContract::class, QuotaManager::class);

        $this->app->singleton(ResumableUploadService::class, fn ($app) => new ResumableUploadService(
            storageManager: $app->make(StorageManager::class),
            fileValidator:  $app->make(FileValidator::class),
            mimeResolver:   $app->make(MimeTypeResolver::class),
        ));
    }

    /**
     * Registers the top-level FileUploadService orchestrator and its contract alias.
     *
     * The service is bound as a singleton since all its dependencies are
     * themselves singletons and it holds no mutable state.
     */
    private function registerOrchestrator(): void
    {
        $this->app->singleton(FileUploadService::class, fn ($app) => new FileUploadService(
            urlDownloader: $app->make(UrlDownloader::class),
            fileValidator: $app->make(FileValidator::class),
            storageManager: $app->make(StorageManager::class),
            quotaManager:  $app->make(QuotaManagerContract::class),
        ));

        $this->app->bind(FileUploadContract::class, FileUploadService::class);
    }

    /**
     * Publishes the package migration files to the application's migrations directory.
     */
    private function publishMigrations(): void
    {
        $timestamp = date('Y_m_d_His', time());

        $this->publishes([
            __DIR__ . '/../database/migrations/create_file_uploads_table.php' =>
                database_path("migrations/{$timestamp}_create_file_uploads_table.php"),
            __DIR__ . '/../database/migrations/create_upload_sessions_table.php' =>
                database_path("migrations/{$timestamp}_create_upload_sessions_table.php"),
        ], 'migrations');
    }

    /**
     * Publishes front-end JavaScript and CSS assets to the public directory.
     */
    private function publishAssets(): void
    {
        $this->publishes([
            __DIR__ . '/../resources/js/advanced-file-upload.js'   => public_path('vendor/advanced-file-upload/advanced-file-upload.js'),
            __DIR__ . '/../resources/css/advanced-file-upload.css' => public_path('vendor/advanced-file-upload/advanced-file-upload.css'),
        ], 'public');
    }

    /**
     * Publishes Blade view stubs to the application's resources directory.
     */
    private function publishViews(): void
    {
        $this->publishes([
            __DIR__ . '/../resources/views/advanced-file-upload/upload.blade.php' =>
                resource_path('views/vendor/advanced-file-upload/upload.blade.php'),
        ], 'views');
    }
}
