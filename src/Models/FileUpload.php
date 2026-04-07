<?php

namespace MohamedSamy902\AdvancedFileUpload\Models;

use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{
    protected $table = 'file_uploads';

    protected $fillable = [
        'original_name',
        'name',
        'path',
        'disk',
        'mime_type',
        'type',
        'size',
        'user_id',
    ];
}