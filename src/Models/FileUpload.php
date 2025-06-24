<?php

namespace MohamedSamy902\AdvancedFileUpload\Models;

use Illuminate\Database\Eloquent\Model;

class FileUpload extends Model
{
    protected $table = 'file_uploads';

    protected $fillable = [
        'name',
        'path',
        'mime_type',
        'size',
        'user_id',
    ];
}