<?php

namespace MohamedSamy902\AdvancedFileUpload\Facades;

use Illuminate\Support\Facades\Facade;

class FileUpload extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \MohamedSamy902\AdvancedFileUpload\Services\FileUploadService::class;
    }
}