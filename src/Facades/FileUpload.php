<?php

namespace YourVendor\AdvancedFileUpload\Facades;

use Illuminate\Support\Facades\Facade;

class FileUpload extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \YourVendor\AdvancedFileUpload\Services\FileUploadService::class;
    }
}