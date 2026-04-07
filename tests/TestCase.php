<?php

namespace MohamedSamy902\AdvancedFileUpload\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use MohamedSamy902\AdvancedFileUpload\AdvancedFileUploadServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AdvancedFileUploadServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('file-upload', require __DIR__ . '/../config/file-upload.php');

        // Disable features that require unavailable PHP extensions (gd, pdo_sqlite)
        $app['config']->set('file-upload.processing.image.enabled', false);
        $app['config']->set('file-upload.thumbnails.enabled', false);
        $app['config']->set('file-upload.database.enabled', false);
    }
}
