<?php

declare(strict_types=1);

namespace MohamedSamy902\AdvancedFileUpload\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use MohamedSamy902\AdvancedFileUpload\AdvancedFileUploadServiceProvider;
use MohamedSamy902\AdvancedFileUpload\Contracts\SsrfValidatorContract;
use MohamedSamy902\AdvancedFileUpload\Services\FileUploadService;
use MohamedSamy902\AdvancedFileUpload\Services\FileValidator;
use MohamedSamy902\AdvancedFileUpload\Services\MimeTypeResolver;
use MohamedSamy902\AdvancedFileUpload\Services\StorageManager;
use MohamedSamy902\AdvancedFileUpload\Services\UrlDownloader;
use MohamedSamy902\AdvancedFileUpload\Contracts\QuotaManagerContract;

/**
 * Base test case for the Advanced File Upload package.
 *
 * Provides two factory methods for building a FileUploadService:
 *
 *   - makeService($ssrf)  : builds with the given SSRF validator (useful for
 *     injecting a no-op validator in tests that do not exercise SSRF logic).
 *
 *   - noopSsrf()          : returns a no-op SSRF validator instance suitable
 *     for URL upload tests that must not fail on DNS resolution in CI.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AdvancedFileUploadServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('file-upload', require __DIR__ . '/../config/file-upload.php');

        // Disable features that require unavailable infrastructure in unit tests.
        $app['config']->set('file-upload.processing.image.enabled', false);
        $app['config']->set('file-upload.thumbnails.enabled', false);
        $app['config']->set('file-upload.database.enabled', false);
        $app['config']->set('file-upload.quota.enabled', false);
        $app['config']->set('file-upload.security.rate_limit.enabled', false);
        
        $app['config']->set('app.key', 'base64:JbH1T8/s+cZqKqP7wW9/VbYt9E6mJtKqQ5yQ4oH0Ycw=');
    }

    /**
     * Builds a FileUploadService with the provided SSRF validator.
     *
     * @param SsrfValidatorContract $ssrfValidator
     * @return FileUploadService
     */
    protected function makeService(SsrfValidatorContract $ssrfValidator): FileUploadService
    {
        $mimeResolver = $this->app->make(MimeTypeResolver::class);
        $fileValidator = new FileValidator();

        return new FileUploadService(
            urlDownloader: new UrlDownloader($ssrfValidator, $fileValidator, $mimeResolver),
            fileValidator: $fileValidator,
            storageManager: new StorageManager(
                $this->app->make(\MohamedSamy902\AdvancedFileUpload\Contracts\ImageProcessorContract::class),
                $mimeResolver,
            ),
            quotaManager: $this->app->make(QuotaManagerContract::class),
        );
    }

    /**
     * Returns a no-op SSRF validator that permits any URL without DNS checks.
     *
     * Use this in URL upload tests to prevent failures caused by DNS
     * resolution being unavailable in CI or offline environments.
     *
     * @return SsrfValidatorContract
     */
    protected function noopSsrf(): SsrfValidatorContract
    {
        return new class implements SsrfValidatorContract {
            public function validate(string $url): void {}
        };
    }
}
