<?php

namespace MohamedSamy902\AdvancedFileUpload\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \MohamedSamy902\AdvancedFileUpload\ValueObjects\UploadResult|array upload(mixed $source, array $options = [])
 * @method static array delete(int|string|array $idOrPath)
 *
 * @see \MohamedSamy902\AdvancedFileUpload\Services\FileUploadService
 */
class FileUpload extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return \MohamedSamy902\AdvancedFileUpload\Services\FileUploadService::class;
    }
}