<?php

namespace MohamedSamy902\AdvancedFileUpload;

use Illuminate\Support\ServiceProvider;

class AdvancedFileUploadServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(Services\FileUploadService::class, function ($app) {
            return new Services\FileUploadService();
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/file-upload.php' => config_path('file-upload.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations/create_file_uploads_table.php' => database_path('migrations/'.date('Y_m_d_His', time()).'_create_file_uploads_table.php'),
            ], 'migrations');
            $this->publishes([
                __DIR__.'/Models/FileUpload.php' => best_path('Models/FileUpload.php'),
            ], 'migrations');
        }
    }
}