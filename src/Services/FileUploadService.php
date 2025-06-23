<?php

namespace YourVendor\AdvancedFileUpload\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Exception;

class FileUploadService
{
    public function upload(Request $request, string $fieldName = 'file', array $options = [])
    {
        $config = config('file-upload');
        $disk = $config['storage']['disk'];
        $path = $this->generatePath($config['storage']['path'], $config['storage']['organize_by']);

        // Check quota if enabled
        if ($config['quota']['enabled']) {
            $this->checkQuota($request->file($fieldName));
        }

        // Handle chunked upload
        $receiver = new FileReceiver($fieldName, $request, HandlerFactory::classFromRequest($request));
        if ($receiver->isUploaded()) {
            $save = $receiver->receive();
            if ($save->isFinished()) {
                return $this->saveFile($save->getFile(), $path, $disk, $options);
            }
            $handler = $save->handler();
            return response()->json(['done' => $handler->getPercentageDone(), 'status' => true]);
        }

        // Handle standard upload
        $file = $request->file($fieldName);
        if (!$file) {
            throw new Exception('لا يوجد ملف مرفوع');
        }
        return $this->saveFile($file, $path, $disk, $options);
    }

    protected function saveFile(UploadedFile $file, string $path, string $disk, array $options)
    {
        $config = config('file-upload');
        $mime = $file->getMimeType();
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . '.' . ($options['convert_to'] ?? $extension);
        $fullPath = $path . '/' . $fileName;

        // Validate file
        $this->validateFile($file, $mime);

        // Process file content
        $content = file_get_contents($file->getRealPath());
        if (str_starts_with($mime, 'image') && $config['processing']['image']['enabled']) {
            $content = $this->processImage($content, $config['processing']['image'], $options);
        } elseif ($config['compression']['enabled'] && in_array($extension, $config['compression']['types'])) {
            $content = $this->compressFile($content, $extension, $config['compression']['quality']);
        }

        // Save file to storage
        Storage::disk($disk)->put($fullPath, $content);

        // Generate thumbnails for images
        $thumbnailUrls = [];
        if (str_starts_with($mime, 'image') && $config['thumbnails']['enabled']) {
            $thumbnailUrls = $this->generateThumbnails($fullPath, $disk, $content, $config['thumbnails']['sizes']);
        }

        // Store in database if enabled
        $fileData = [];
        if ($config['database']['enabled']) {
            $modelClass = $config['database']['model'];
            $fileData = $modelClass::create([
                'name' => $fileName,
                'path' => $fullPath,
                'mime_type' => $mime,
                'size' => $file->getSize(),
                'user_id' => auth()->id(),
            ])->toArray();
        }

        // Prepare response
        $url = $this->getFileUrl($config, $disk, $fullPath);
        return array_merge([
            'path' => $fullPath,
            'url' => $url,
            'thumbnail_urls' => $thumbnailUrls,
            'mime_type' => $mime,
        ], $fileData);
    }

    protected function validateFile(UploadedFile $file, string $mimeType)
    {
        $rules = config('file-upload.validation');
        $type = explode('/', $mimeType)[0];
        $rule = $rules[$type] ?? $rules['other'];
        $validator = Validator::make([$file->getClientOriginalName() => $file], [
            $file->getClientOriginalName() => $rule,
        ]);
        if ($validator->fails()) {
            throw new Exception($validator->errors()->first());
        }
    }

    protected function processImage($content, array $config, array $options)
    {
        $image = Image::make($content);

        // Resize
        if (isset($config['resize']) && $config['resize']['width'] && $config['resize']['height']) {
            $image->resize(
                $config['resize']['width'],
                $config['resize']['height'],
                function ($constraint) use ($config) {
                    if ($config['resize']['maintain_aspect_ratio']) {
                        $constraint->aspectRatio();
                    }
                }
            );
        }

        // Apply watermark
        if (isset($config['watermark']) && $config['watermark']) {
            $image->insert($config['watermark']);
        }

        // Apply filters
        if (isset($config['filters']) && !empty($config['filters'])) {
            foreach ($config['filters'] as $filter => $value) {
                if (method_exists($image, $filter)) {
                    $image->$filter($value);
                }
            }
        }

        // Convert format
        $format = $options['convert_to'] ?? $config['convert_to'];
        if ($format) {
            $image->encode($format);
        }

        return (string) $image->encode();
    }

    protected function generateThumbnails(string $path, string $disk, $content, array $sizes)
    {
        $thumbnailUrls = [];
        $image = Image::make($content);

        foreach ($sizes as $sizeName => $dimensions) {
            $thumbnailName = "thumb_{$sizeName}_" . basename($path);
            $thumbnailPath = dirname($path) . '/' . $thumbnailName;
            $resizedImage = $image->resize($dimensions['width'], $dimensions['height']);
            Storage::disk($disk)->put($thumbnailPath, (string) $resizedImage->encode());
            $thumbnailUrls[$sizeName] = $this->getFileUrl(config('file-upload'), $disk, $thumbnailPath);
        }

        return $thumbnailUrls;
    }

    protected function compressFile($content, string $extension, int $quality)
    {
        // Placeholder for compression logic
        // Requires additional libraries like ZipArchive for documents
        return $content;
    }

    protected function checkQuota(UploadedFile $file)
    {
        $config = config('file-upload.quota');
        if (!$config['enabled'] || !auth()->check()) {
            return;
        }

        $userId = auth()->id();
        $modelClass = config('file-upload.database.model');
        $totalSize = $modelClass::where('user_id', $userId)->sum('size');
        $newSize = $file->getSize();

        if (($totalSize + $newSize) > $config['max_size_per_user']) {
            throw new Exception('تم تجاوز حد التخزين المسموح للمستخدم');
        }
    }

    protected function generatePath(string $basePath, string $organizeBy)
    {
        if ($organizeBy === 'date') {
            return $basePath . '/' . now()->format('Y/m/d');
        } elseif ($organizeBy === 'user' && auth()->check()) {
            return $basePath . '/users/' . auth()->id();
        }
        return $basePath;
    }

    protected function getFileUrl(array $config, string $disk, string $path)
    {
        $url = Storage::disk($disk)->url($path);
        if ($config['storage']['cdn']['enabled'] && $config['storage']['cdn']['url']) {
            $url = $config['storage']['cdn']['url'] . '/' . $path;
        }
        return $url;
    }

    public function delete($idOrPath)
    {
        $config = config('file-upload');
        $disk = $config['storage']['disk'];

        if ($config['database']['enabled']) {
            $modelClass = $config['database']['model'];
            $file = $modelClass::findOrFail($idOrPath);
            Storage::disk($disk)->delete($file->path);

            // Delete thumbnails
            if ($config['thumbnails']['enabled']) {
                foreach ($config['thumbnails']['sizes'] as $sizeName => $size) {
                    $thumbnailPath = dirname($file->path) . "/thumb_{$sizeName}_" . $file->name;
                    Storage::disk($disk)->delete($thumbnailPath);
                }
            }

            $file->delete();
        } else {
            Storage::disk($disk)->delete($idOrPath);
        }

        return ['status' => 'تم حذف الملف بنجاح'];
    }
}