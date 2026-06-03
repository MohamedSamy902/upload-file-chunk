<?php

namespace MohamedSamy902\AdvancedFileUpload\Contracts;

use MohamedSamy902\AdvancedFileUpload\ValueObjects\UploadResult;

interface FileUploadContract
{
    /**
     * Upload a file or multiple files from a Request, direct UploadedFile, URL, or array.
     *
     * @param  mixed  $source
     * @param  array  $options
     * @return UploadResult|array<int,UploadResult|array<string,mixed>>
     */
    public function upload(mixed $source, array $options = []): UploadResult|array;

    /**
     * Delete a file or multiple files by ID, path, or array of IDs/paths.
     *
     * @param  int|string|array<int,int|string>  $idOrPath
     * @return array<string,mixed>|array<int,array<string,mixed>>
     */
    public function delete(int|string|array $idOrPath): array;
}
