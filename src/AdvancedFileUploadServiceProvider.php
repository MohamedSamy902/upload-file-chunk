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
        // نشر ملفات الإعدادات
        $this->publishes([
            __DIR__ . '/../config/file-upload.php' => config_path('file-upload.php'),
        ], 'config');

        // نشر المايجريشن
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../database/migrations/create_file_uploads_table.php' => database_path('migrations/' . date('Y_m_d_His', time()) . '_create_file_uploads_table.php'),
            ], 'migrations');

            // نشر ملفات الـ JS/CSS
            $this->publishes([
                __DIR__ . '/../resources/js/advanced-file-upload.js' => public_path('vendor/advanced-file-upload/advanced-file-upload.js'),
                __DIR__ . '/../resources/css/advanced-file-upload.css' => public_path('vendor/advanced-file-upload/advanced-file-upload.css'),
            ], 'public');

            // نشر الـ Blade View
            $this->publishes([
                __DIR__ . '/../resources/views/advanced-file-upload/upload.blade.php' => resource_path('views/vendor/advanced-file-upload/upload.blade.php'),
            ], 'views');
        }

        // تحميل الـ views من الباكدج
        $this->loadViewsFrom(__DIR__ . '/../resources/views/advanced-file-upload', 'advanced-file-upload');
    }
}
