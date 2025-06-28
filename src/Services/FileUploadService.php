<?php

namespace MohamedSamy902\AdvancedFileUpload\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image; // تأكد إنك عامل composer require intervention/image
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Exception;
use Symfony\Component\Mime\MimeTypes;
use Illuminate\Support\Facades\Log;

class FileUploadService
{
    /**
     * بيرفع ملف أو مجموعة ملفات، سواء كانت من ريكويست، أو من URL، أو ملف مباشر.
     *
     * @param UploadedFile|Request|string|array $fileOrRequest الملف اللي هيترفع أو الريكويست أو الـ URL أو مجموعة URLs.
     * @param array $options مصفوفة فيها خيارات الرفع زي اسم الفولدر وقواعد الفاليديشن المخصصة.
     * @return array|object|null بيانات الملفات اللي اترفعت أو حالة الرفع للـ chunked uploads.
     * @throws Exception لو حصل أي مشكلة في الرفع أو الفاليديشن.
     */
    public function upload($fileOrRequest, array $options = [])
    {
        $config = config('file-upload');
        $disk = $options['disk'] ?? $config['storage']['disk']; // تقدر تحدد الديسك من الأوبشنز دلوقتي
        $basePath = $config['storage']['path'];
        $folderName = $options['folder_name'] ?? $config['storage']['default_folder'];
        $path = $this->generatePath($basePath, $folderName);

        // هنا بنستقبل قواعد الفاليديشن اللي جاية مع الريكويست، لو موجودة.
        $customValidationRules = $options['validation_rules'] ?? [];

        // هنا بنشوف نوع الرفع (URL، ريكويست، أو ملف مباشر) وبنبعت معاه قواعد الفاليديشن.
        if (isset($options['url'])) {
            return $this->handleUrlUpload($options['url'], $path, $disk, $options, $customValidationRules);
        }

        if ($fileOrRequest instanceof Request) {
            return $this->handleRequestUpload($fileOrRequest, $path, $disk, $options, $customValidationRules);
        }
        return $this->handleDirectUpload($fileOrRequest, $path, $disk, $options, $customValidationRules);
    }

    /**
     * بيتعامل مع رفع الملفات من خلال الـ URLs.
     *
     * @param string|array $urls الـ URL أو مجموعة URLs للملفات اللي هتترفع.
     * @param string $path المسار اللي هيتخزن فيه الملف.
     * @param string $disk اسم الـ disk في الـ Storage.
     * @param array $options خيارات إضافية.
     * @param array $customValidationRules قواعد فاليديشن مخصصة.
     * @return array نتائج الرفع لكل URL.
     */
    protected function handleUrlUpload($urls, string $path, string $disk, array $options, array $customValidationRules = [])
    {
        if (is_array($urls)) {
            $results = [];
            foreach ($urls as $url) {
                try {
                    $results[] = $this->processUrlUpload($url, $path, $disk, $options, $customValidationRules);
                } catch (Exception $e) {
                    Log::error("فشل تنزيل ملف من الرابط {$url}: " . $e->getMessage());
                    $results[] = [
                        'error' => $e->getMessage(),
                        'url' => $url,
                        'status' => false
                    ];
                }
            }
            return $results;
        }

        return $this->processUrlUpload($urls, $path, $disk, $options, $customValidationRules);
    }

    /**
     * بيقوم بعملية تنزيل ورفع ملف من URL.
     *
     * @param string $url الـ URL بتاع الملف.
     * @param string $path المسار اللي هيتخزن فيه الملف.
     * @param string $disk اسم الـ disk في الـ Storage.
     * @param array $options خيارات إضافية.
     * @param array $customValidationRules قواعد فاليديشن مخصصة.
     * @return array بيانات الملف اللي اترفع.
     * @throws Exception لو فشل تنزيل أو معالجة الملف.
     */
    protected function processUrlUpload(string $url, string $path, string $disk, array $options, array $customValidationRules = [])
    {
        $config = config('file-upload');
        $file = null; // تهيئة المتغير

        try {
            // استخدام التحميل المجزأ (chunked download) للملفات الكبيرة لو كانت مفعلة.
            if (($config['url_download']['enabled'] ?? true) && ($config['url_download']['chunked'] ?? true)) {
                $file = $this->chunkedDownloadFromUrl($url, $options);
            } else {
                $file = $this->simpleDownloadFromUrl($url, $options);
            }

            // تحقق من أن الملف تم تنزيله وصالح.
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new Exception('فشل تنزيل الملف أو الملف غير صالح من الرابط.');
            }

            // التحقق من حصة التخزين المسموح بها للمستخدم.
            if ($config['quota']['enabled']) {
                $this->checkQuota($file);
            }

            // حفظ الملف بعد التنزيل والمعالجة.
            return $this->saveFile($file, $path, $disk, $options, $customValidationRules);

        } catch (Exception $e) {
            // تنظيف الملف المؤقت لو حصل أي خطأ
            if ($file && file_exists($file->getRealPath())) {
                unlink($file->getRealPath());
            }
            Log::error("خطأ في معالجة URL upload من الرابط {$url}: " . $e->getMessage());
            throw new Exception('فشل معالجة الملف من الرابط: ' . $e->getMessage());
        }
    }

    /**
     * بيحمل الملف من URL بشكل مجزأ (chunked) عشان الملفات الكبيرة.
     *
     * @param string $url الـ URL بتاع الملف.
     * @param array $options خيارات التحميل (timeout, maxSize, chunkSize).
     * @return UploadedFile كائن UploadedFile للملف اللي تم تحميله.
     * @throws Exception لو حصل خطأ في التحميل المجزأ أو تجاوز الحجم الأقصى.
     */
    protected function chunkedDownloadFromUrl(string $url, array $options): UploadedFile
    {
        $timeout = $options['timeout'] ?? config('file-upload.url_download.timeout', 300);
        $maxSize = $options['max_size'] ?? config('file-upload.url_download.max_size', 1024 * 1024 * 500); // 500MB default
        $chunkSize = $options['chunk_size'] ?? config('file-upload.url_download.chunk_size', 1024 * 1024 * 5); // 5MB chunks

        $tempDir = sys_get_temp_dir();
        $tempName = Str::uuid();
        $tempPath = "{$tempDir}/{$tempName}";
        $fileHandle = null;

        try {
            $bytesDownloaded = 0;
            $startByte = 0;
            $fileHandle = fopen($tempPath, 'w');

            if ($fileHandle === false) {
                throw new Exception("فشل فتح ملف مؤقت للكتابة: {$tempPath}");
            }

            // عشان نتحقق من الـ MIME type قبل التنزيل الكامل
            $initialResponse = Http::timeout($timeout)->head($url);
            if ($initialResponse->failed()) {
                throw new Exception('فشل جلب معلومات الملف من الرابط: ' . $initialResponse->status());
            }
            $contentTypeHeader = $initialResponse->header('Content-Type');
            $mimeType = $this->detectMimeType($tempPath, $contentTypeHeader);
            $this->validateAllowedMimeType($mimeType); // وظيفة جديدة للفحص

            do {
                $endByte = $startByte + $chunkSize - 1;
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Range' => "bytes={$startByte}-{$endByte}",
                        'Accept' => '*/*'
                    ])
                    ->get($url);

                if ($response->failed()) {
                    // لو الخادم مردش أو مدعمش التحميل المجزأ بعد أول جزء
                    if ($response->status() === 416 && $startByte === 0) { // لو 416 في البداية يبقى مش بيدعم الـ Range
                         //Fallback to normal download
                        if (is_resource($fileHandle)) {
                            fclose($fileHandle);
                        }
                        return $this->simpleDownloadFromUrl($url, $options);
                    } elseif ($response->status() === 416 && $startByte > 0) {
                        // لو 416 بعد ما بدأنا تحميل يبقى الملف خلص أو فيه مشكلة في الـ range اللي بنطلبه
                        break; // نوقف التحميل ونعتبره خلص
                    } else {
                        throw new Exception('فشل التحميل المجزأ: ' . $response->status());
                    }
                }

                $chunkData = $response->body();
                $bytesWritten = fwrite($fileHandle, $chunkData);
                if ($bytesWritten === false) {
                     throw new Exception('فشل الكتابة في الملف المؤقت أثناء التحميل المجزأ.');
                }
                $bytesDownloaded += $bytesWritten;
                $startByte += $bytesWritten;

                if ($bytesDownloaded > $maxSize) {
                    throw new Exception('حجم الملف يتجاوز الحد المسموح به (' . round($maxSize / (1024 * 1024), 2) . 'MB)');
                }

                $contentRange = $response->header('Content-Range');
                $totalSize = $contentRange ? (int) explode('/', $contentRange)[1] : null;

                // لو التحميل المجزأ مدعومش، نعتمد على الـ Content-Length بتاع الـ chunk الأخير لو مفيش Content-Range
                if (!$totalSize && $response->header('Content-Length')) {
                    $totalSize = $bytesDownloaded; // لو محددش الـ Total Size يبقى اللي نزلناه هو ده كله
                }

            } while (!$totalSize || $bytesDownloaded < $totalSize);

            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }

            // تحديد معلومات الملف
            // ملاحظة: الـ mimetype ممكن يكون مش دقيق لحد ما يتم تحميل الملف كله.
            $mimeType = $this->detectMimeType($tempPath, $contentTypeHeader);
            $extension = $this->getExtensionFromMime($mimeType, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: $tempName;

            // إعادة تسمية الملف بالامتداد الصحيح
            $finalPath = "{$tempPath}.{$extension}";
            rename($tempPath, $finalPath);

            return new UploadedFile(
                $finalPath,
                "{$originalName}.{$extension}",
                $mimeType,
                null,
                true
            );
        } catch (Exception $e) {
            // تنظيف الملفات المؤقتة لو حصل خطأ
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            if (isset($finalPath) && file_exists($finalPath)) {
                unlink($finalPath);
            }

            Log::error("خطأ في التحميل المجزأ من URL {$url}: " . $e->getMessage());
            throw new Exception('خطأ في التحميل المجزأ: ' . $e->getMessage());
        }
    }

    /**
     * بيحمل الملف من URL بشكل مباشر (مش مجزأ).
     *
     * @param string $url الـ URL بتاع الملف.
     * @param array $options خيارات التحميل (timeout, maxSize).
     * @return UploadedFile كائن UploadedFile للملف اللي تم تحميله.
     * @throws Exception لو حصل خطأ في التحميل المباشر أو تجاوز الحجم الأقصى.
     */
    protected function simpleDownloadFromUrl(string $url, array $options): UploadedFile
    {
        try {
            $timeout = $options['timeout'] ?? config('file-upload.url_download.timeout', 30);
            $maxSize = $options['max_size'] ?? config('file-upload.url_download.max_size', 1024 * 1024 * 50); // 50MB default

            $response = Http::timeout($timeout)
                ->withHeaders(['Accept' => '*/*'])
                ->get($url);

            if ($response->failed()) {
                throw new Exception('فشل تنزيل الملف من الرابط: ' . $response->status());
            }

            $contentType = $response->header('Content-Type');
            $this->validateAllowedMimeType($contentType); // فحص الـ MIME type

            // التحقق من حجم الملف
            if (strlen($response->body()) > $maxSize) {
                throw new Exception('حجم الملف يتجاوز الحد المسموح به (' . round($maxSize / (1024 * 1024), 2) . 'MB)');
            }

            $extension = $this->getExtensionFromMime($contentType, $url);
            $originalName = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
            $tempName = Str::uuid() . ($originalName ? '_' . Str::slug($originalName) : ''); // استخدام Str::slug لتنظيف الاسم
            $tempPath = sys_get_temp_dir() . '/' . $tempName . '.' . $extension;

            if (!file_put_contents($tempPath, $response->body())) {
                throw new Exception('فشل حفظ الملف المؤقت.');
            }

            return new UploadedFile(
                $tempPath,
                "{$originalName}.{$extension}",
                $contentType,
                null,
                true
            );
        } catch (Exception $e) {
            if (isset($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }
            Log::error("خطأ أثناء تنزيل الملف من URL {$url}: " . $e->getMessage());
            throw new Exception('خطأ أثناء تنزيل الملف: ' . $e->getMessage());
        }
    }

    /**
     * بيتعامل مع رفع الملفات اللي جاية من الـ Request (سواء كان chunked أو عادي).
     *
     * @param Request $request الـ Request اللي فيه الملفات.
     * @param string $path المسار اللي هيتخزن فيه الملف.
     * @param string $disk اسم الـ disk في الـ Storage.
     * @param array $options خيارات إضافية.
     * @param array $customValidationRules قواعد فاليديشن مخصصة.
     * @return array|object|null نتائج الرفع.
     */
    protected function handleRequestUpload(Request $request, string $path, string $disk, array $options, array $customValidationRules = [])
    {
        $config = config('file-upload');
        $fieldName = $options['field_name'] ?? 'file';
        // dd($fieldName);
        // Check for chunked upload
        $receiver = new FileReceiver($fieldName, $request, HandlerFactory::classFromRequest($request));
        if ($receiver->isUploaded()) {
            $save = $receiver->receive();
            if ($save->isFinished()) {
                $file = $save->getFile();
                if ($config['quota']['enabled']) {
                    $this->checkQuota($file);
                }
                return $this->saveFile($file, $path, $disk, $options, $customValidationRules);
            }
            $handler = $save->handler();
            return response()->json([
                'done' => $handler->getPercentageDone(),
                'status' => true
            ]);
        }

        // Handle multiple files from 'files' field or dynamic field name
        $files = $request->file('files') ?? $request->file($fieldName);
        if (is_array($files)) {
            $results = [];
            foreach ($files as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    if ($config['quota']['enabled']) {
                        $this->checkQuota($file);
                    }
                    $results[] = $this->saveFile($file, $path, $disk, $options, $customValidationRules);
                } else {
                    Log::warning("ملف غير صالح تم استلامه في الريكويست: " . ($file ? $file->getClientOriginalName() : 'غير معروف'));
                    $results[] = [
                        'status' => false,
                        'error' => 'ملف غير صالح',
                        'original_name' => $file ? $file->getClientOriginalName() : 'غير معروف',
                    ];
                }
            }
            return $results;
        }

        // Handle single file
        $file = $request->file($fieldName);
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            Log::error("ملف فردي غير صالح في الريكويست، اسم الحقل: {$fieldName}");
            throw new Exception('الملف غير صالح.');
        }

        if ($config['quota']['enabled']) {
            $this->checkQuota($file);
        }

        return $this->saveFile($file, $path, $disk, $options, $customValidationRules);
    }

    /**
     * بيتعامل مع رفع الملفات مباشرةً (كأوبجكت UploadedFile).
     *
     * @param UploadedFile|array $file الملف أو مجموعة الملفات.
     * @param string $path المسار اللي هيتخزن فيه الملف.
     * @param string $disk اسم الـ disk في الـ Storage.
     * @param array $options خيارات إضافية.
     * @param array $customValidationRules قواعد فاليديشن مخصصة.
     * @return array نتائج الرفع.
     */
    protected function handleDirectUpload($file, string $path, string $disk, array $options, array $customValidationRules = [])
    {
        $config = config('file-upload');
        if (is_array($file)) {
            $results = [];
            foreach ($file as $singleFile) {
                if ($singleFile instanceof UploadedFile && $singleFile->isValid()) {
                    if ($config['quota']['enabled']) {
                        $this->checkQuota($singleFile);
                    }
                    $results[] = $this->saveFile($singleFile, $path, $disk, $options, $customValidationRules);
                } else {
                     Log::warning("ملف مباشر غير صالح: " . ($singleFile instanceof UploadedFile ? $singleFile->getClientOriginalName() : 'غير معروف'));
                    $results[] = [
                        'status' => false,
                        'error' => 'ملف غير صالح',
                        'original_name' => $singleFile instanceof UploadedFile ? $singleFile->getClientOriginalName() : 'غير معروف',
                    ];
                }
            }
            return $results;
        }

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            Log::error("ملف مباشر فردي غير صالح.");
            throw new Exception('الملف غير صالح.');
        }

        if ($config['quota']['enabled']) {
            $this->checkQuota($file);
        }

        return $this->saveFile($file, $path, $disk, $options, $customValidationRules);
    }

    /**
     * بيكتشف الـ MIME type للملف.
     *
     * @param string $filePath مسار الملف.
     * @param string|null $contentType الـ Content-Type من الهيدر لو موجود.
     * @return string الـ MIME type المكتشف.
     */
    protected function detectMimeType(string $filePath, ?string $contentType): string
    {
        // بيحاول يكتشف الـ MIME type من الملف نفسه
        if (file_exists($filePath)) {
            $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $fileInfo->file($filePath);
            if ($detected && $detected !== 'application/octet-stream') {
                return $detected;
            }
        }

        // لو ماقدرش يكتشف من الملف، بيستخدم الـ Content-Type اللي جاي من الهيدر
        if ($contentType) {
            return explode(';', $contentType)[0];
        }

        return 'application/octet-stream'; // Default fallback
    }

    /**
     * بيجيب امتداد الملف من الـ MIME type أو من الـ URL.
     *
     * @param string $mime الـ MIME type بتاع الملف.
     * @param string $url الـ URL الأصلي للملف (مستخدم للـ fallback).
     * @return string امتداد الملف.
     */
    protected function getExtensionFromMime(string $mime, string $url): string
    {
        $mimeTypes = new MimeTypes();
        $extensions = $mimeTypes->getExtensions($mime);

        if (!empty($extensions)) {
            return $extensions[0];
        }

        $commonTypes = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv', 'video/webm' => 'webm', 'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/plain' => 'txt', 'text/csv' => 'csv', 'text/xml' => 'xml', 'application/json' => 'json'
        ];

        // Fallback to common types or URL extension
        return $commonTypes[$mime] ?? pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'bin';
    }

    /**
     * بيقوم بفحص الـ MIME type للملف عشان يتأكد إنه مسموح بيه في الـ config.
     *
     * @param string $mimeType الـ MIME type بتاع الملف.
     * @throws Exception لو الـ MIME type مش مسموح بيه.
     */
    protected function validateAllowedMimeType(string $mimeType)
    {
        $config = config('file-upload.url_download.allowed_mimes');
        if (empty($config)) {
            return; // لو مفيش قائمة مسموحات، كل حاجة مسموحة.
        }

        $isAllowed = false;
        $fileType = explode('/', $mimeType)[0];
        $fileSubtype = explode('/', $mimeType)[1] ?? '';

        if (isset($config[$fileType])) {
            if (empty($config[$fileType]) || in_array($fileSubtype, $config[$fileType])) {
                $isAllowed = true;
            }
        } elseif (isset($config['other']) && (empty($config['other']) || in_array($fileSubtype, $config['other']))) {
            // لو فيه "other" generic category
            $isAllowed = true;
        }

        if (!$isAllowed) {
            throw new Exception("نوع الملف ({$mimeType}) غير مسموح بتحميله من الروابط.");
        }
    }


    /**
     * بيحفظ الملف على الـ disk بعد ما بيخلص كل المعالجة والفاليديشن.
     *
     * @param UploadedFile $file الملف اللي هيتحفظ.
     * @param string $path المسار اللي هيتخزن فيه الملف.
     * @param string $disk اسم الـ disk في الـ Storage.
     * @param array $options خيارات إضافية.
     * @param array $customValidationRules قواعد فاليديشن مخصصة.
     * @return array بيانات الملف اللي اتحفظ.
     * @throws Exception لو حصل خطأ في الحفظ أو المعالجة.
     */
    protected function saveFile(UploadedFile $file, string $path, string $disk, array $options, array $customValidationRules = [])
    {
        $config = config('file-upload');
        $mime = $file->getMimeType();
        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $fileName = Str::uuid() . '.' . $extension;
        $fullPath = $path . '/' . $fileName;

        // الفاليديشن
        $this->validateFile($file, $mime, $options['field_name'] ?? 'file', $customValidationRules);

        try {
            // معالجة الصور إذا كانت مفعلة
            if (str_starts_with($mime, 'image') && $config['processing']['image']['enabled']) {
                $image = Image::make($file->getRealPath());
                $processedContent = $this->processImage($image, $config['processing']['image'], $options);
                Storage::disk($disk)->put($fullPath, $processedContent);
            } else {
                // حفظ الملف كما هو للأنواع الأخرى
                Storage::disk($disk)->put($fullPath, file_get_contents($file->getRealPath()));
            }

            // توليد thumbnails للصور إذا كانت مفعلة
            $thumbnailUrls = [];
            if (str_starts_with($mime, 'image') && $config['thumbnails']['enabled']) {
                $thumbnailUrls = $this->generateThumbnails($fullPath, $disk, $file->getRealPath(), $config['thumbnails']['sizes'], $fileName);
            }

            // حفظ بيانات الملف في DB إذا كانت مفعلة
            $fileData = [];
            if ($config['database']['enabled']) {
                $modelClass = $config['database']['model'];
                $fileData = $modelClass::create([
                    'name' => $fileName,
                    'path' => $fullPath,
                    'mime_type' => $mime,
                    'size' => $file->getSize(),
                    'user_id' => auth()->id(),
                    'type' => $this->getFileType($mime),
                ])->toArray();
            }

            return array_merge([
                'status' => true,
                'path' => $fullPath,
                'url' => $this->getFileUrl($config, $disk, $fullPath),
                'thumbnail_urls' => $thumbnailUrls,
                'mime_type' => $mime,
                'type' => $this->getFileType($mime),
            ], $fileData);

        } catch (Exception $e) {
            Log::error("فشل حفظ الملف: " . $e->getMessage());
            if (Storage::disk($disk)->exists($fullPath)) {
                Storage::disk($disk)->delete($fullPath);
            }
            throw new Exception('فشل حفظ الملف: ' . $e->getMessage());
        }
    }

    /**
     * بيحدد نوع الملف بناءً على الـ MIME type بتاعه.
     *
     * @param string $mime الـ MIME type بتاع الملف.
     * @return string نوع الملف (image, video, audio, pdf, document, other).
     */
    protected function getFileType(string $mime): string
    {
        if (str_starts_with($mime, 'image')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio')) {
            return 'audio';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain'
        ])) {
            return 'document';
        }

        return 'other';
    }

    /**
     * بيتحقق من صحة الملف بناءً على قواعد الفاليديشن.
     *
     * @param UploadedFile $file الملف اللي هيتم فحصه.
     * @param string $mimeType الـ MIME type بتاع الملف.
     * @param string $fieldName اسم حقل الفورم اللي جاي منه الملف.
     * @param array $customValidationRules قواعد فاليديشن مخصصة (لو موجودة).
     * @throws Exception لو الفاليديشن فشل.
     */
    protected function validateFile(UploadedFile $file, string $mimeType, string $fieldName = 'file', array $customValidationRules = [])
    {
        // لو فيه قواعد فاليديشن مخصصة للحقل ده، بنستخدمها
        if (isset($customValidationRules[$fieldName])) {
            $rule = $customValidationRules[$fieldName];
        } else {
            // غير كده، بنرجع لقواعد الفاليديشن اللي في الـ config
            $rules = config('file-upload.validation');
            $type = explode('/', $mimeType)[0]; // مثال: image/jpeg -> image
            $rule = $rules['custom_fields'][$fieldName] ?? ($rules[$type] ?? $rules['other']);
        }

        $validator = Validator::make([$fieldName => $file], [
            $fieldName => $rule,
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->errors()->first());
        }
    }

    /**
     * بيعالج الصور (resize, watermark, filters, convert_to).
     *
     * @param \Intervention\Image\Image $image كائن Intervention Image للتحكم في الصورة.
     * @param array $config إعدادات معالجة الصور من الـ config.
     * @param array $options خيارات إضافية للتحويل.
     * @return string محتوى الصورة بعد المعالجة.
     * @throws Exception لو حصل خطأ في معالجة الصورة.
     */
    protected function processImage(\Intervention\Image\Image $image, array $config, array $options)
    {
        try {
            // Resize (تغيير الحجم)
            if (isset($config['resize']['enabled']) && $config['resize']['enabled'] && $config['resize']['width'] && $config['resize']['height']) {
                $image->resize(
                    $config['resize']['width'],
                    $config['resize']['height'],
                    function ($constraint) use ($config) {
                        if ($config['resize']['maintain_aspect_ratio']) {
                            $constraint->aspectRatio();
                        }
                        // منع تكبير الصور لو كانت أبعادها أصغر من الأبعاد المطلوبة (upsize: false)
                        if ($config['resize']['upsize'] === false) { // لو upsize في الـ config قيمتها false
                            $constraint->upsize();
                        }
                    }
                );
            }

            // Watermark (العلامة المائية)
            if (isset($config['watermark']['enabled']) && $config['watermark']['enabled'] && $config['watermark']['path']) {
                // تأكد أن مسار العلامة المائية صحيح وموجود
                $watermarkPath = public_path($config['watermark']['path']); // افتراضياً من الـ public folder
                if (file_exists($watermarkPath)) {
                    $image->insert(
                        $watermarkPath,
                        $config['watermark']['position'] ?? 'bottom-right',
                        $config['watermark']['x_offset'] ?? 10,
                        $config['watermark']['y_offset'] ?? 10
                    );
                    // لو عايز تتحكم في شفافية العلامة المائية
                    if (isset($config['watermark']['opacity'])) {
                         // Intervention Image بيستخدم percentage (0-100)
                        $image->opacity($config['watermark']['opacity']);
                    }
                } else {
                    Log::warning("مسار العلامة المائية غير صحيح أو الملف غير موجود: " . $watermarkPath);
                }
            }

            // Filters (الفلاتر)
            if (isset($config['filters']) && !empty($config['filters'])) {
                foreach ($config['filters'] as $filter => $value) {
                    if (method_exists($image, $filter)) {
                        $image->$filter($value);
                    } else {
                        Log::warning("فلتر الصورة غير موجود أو غير مدعوم: " . $filter);
                    }
                }
            }

            // Convert To (تحويل الصيغة)
            $format = $options['convert_to'] ?? $config['convert_to'];
            $quality = $options['quality'] ?? $config['quality'] ?? 85; // جودة التحويل
            if ($format) {
                return (string) $image->encode($format, $quality);
            }

            return (string) $image->encode(null, $quality); // لو مفيش تحويل، بنرجعها بنفس صيغتها بالجودة المحددة
        } catch (Exception $e) {
            Log::error("خطأ في معالجة الصورة: " . $e->getMessage());
            throw new Exception("فشل في معالجة الصورة: " . $e->getMessage());
        }
    }

    /**
     * بيولد صور مصغرة (thumbnails) للملفات.
     *
     * @param string $fullPath المسار الكامل للملف الأصلي.
     * @param string $disk اسم الـ disk.
     * @param string $originalFileRealPath المسار الحقيقي للملف الأصلي (عشان Intervention Image).
     * @param array $sizes أحجام الـ thumbnails من الـ config.
     * @param string $originalFileName اسم الملف الأصلي (عشان التسمية الجديدة).
     * @return array URLs للصور المصغرة اللي تم توليدها.
     * @throws Exception لو فشل توليد thumbnail.
     */
    protected function generateThumbnails(string $fullPath, string $disk, string $originalFileRealPath, array $sizes, string $originalFileName)
    {
        $thumbnailUrls = [];
        // بنعمل Image instance من الملف الأصلي
        try {
            $image = Image::make($originalFileRealPath);
        } catch (Exception $e) {
            Log::error("فشل في إنشاء كائن الصورة لتوليد الـ thumbnails: " . $e->getMessage());
            return []; // بنرجع مصفوفة فاضية لو فشل
        }

        $dirName = dirname($fullPath);
        $fileBaseNameWithoutExt = pathinfo($originalFileName, PATHINFO_FILENAME); // اسم الملف بدون امتداد (UUID فقط)
        $fileExtension = pathinfo($originalFileName, PATHINFO_EXTENSION); // امتداد الملف الأصلي

        foreach ($sizes as $sizeName => $dimensions) {
            try {
                // بنعمل نسخة من الصورة الأصلية عشان كل thumbnail يكون مستقل.
                $tempImage = clone $image;

                // بنسمي الصورة المصغرة بنفس اسم الملف الأصلي بالضبط مع إضافة الحجم في الأول
                $thumbnailName = "thumb_{$sizeName}_" . $fileBaseNameWithoutExt . '.' . $fileExtension;
                $thumbnailPath = $dirName . '/' . $thumbnailName;

                $width = $dimensions['width'] ?? null;
                $height = $dimensions['height'] ?? null;
                $crop = $dimensions['crop'] ?? false; // خيار الـ crop

                if ($crop && $width && $height) {
                    // لو الـ crop مفعل، بنستخدم fit عشان نقص الصورة وتناسب الأبعاد بالضبط
                    $tempImage->fit($width, $height, function ($constraint) {
                        $constraint->upsize(); // منع التكبير أثناء الـ fit
                    });
                } elseif ($width || $height) {
                    // لو مش crop، بنستخدم resize مع الحفاظ على الـ aspect ratio ومنع التكبير (لو مفعل)
                    $tempImage->resize($width, $height, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize(); // منع التكبير
                    });
                }

                // حفظ الصورة المصغرة
                Storage::disk($disk)->put($thumbnailPath, (string) $tempImage->encode());
                $thumbnailUrls[$sizeName] = $this->getFileUrl(config('file-upload'), $disk, $thumbnailPath);
            } catch (Exception $e) {
                Log::error("فشل توليد thumbnail بحجم {$sizeName} للملف: {$originalFileName}. السبب: " . $e->getMessage());
                // ممكن نكمل باقي الـ thumbnails حتى لو واحد فشل
            }
        }
        return $thumbnailUrls;
    }

    /**
     * بيضغط محتوى الملف. (ده مجرد مثال بسيط وممكن يحتاج مكتبات خارجية معقدة لأنواع معينة).
     *
     * @param string $content محتوى الملف.
     * @param string $extension امتداد الملف.
     * @param int $quality جودة الضغط (تستخدم بشكل مختلف حسب نوع الملف).
     * @return string محتوى الملف بعد الضغط.
     */
    protected function compressFile($content, string $extension, int $quality)
    {
        // ده implementation بسيط جداً لضغط الملفات النصية (مثل TXT, CSV, JSON, XML) باستخدام Gzip.
        // لو الملفات دي هتتعرض في المتصفح، المتصفح بيقدر يفك ضغط Gzip تلقائياً لو الـ Content-Encoding: gzip.
        // لو هتستخدمها في مكان تاني، هتحتاج تفك الضغط هناك.

        // بالنسبة لأنواع ملفات زي PDF, DOCX, XLSX:
        // ضغط الملفات دي بيحتاج مكتبات متخصصة أو أدوات Command Line (زي Ghostscript للـ PDFs)
        // وده بيخلي الـ package معقدة أكتر وبتتطلب تثبيت حاجات إضافية على السيرفر.
        // عشان كده، الأفضل إن وظيفة الضغط لملفات زي دي تكون خارج الـ scope بتاع الـ package دي،
        // أو تكون feature منفصلة يتم تفعيلها بتثبيت مكتبات معينة.

        $text_based_extensions = ['txt', 'csv', 'xml', 'json'];

        if (in_array(strtolower($extension), $text_based_extensions) && function_exists('gzencode')) {
            Log::info("ضغط ملف نصي بامتداد {$extension} باستخدام Gzip.");
            return gzencode($content, 9); // 9 هو أعلى مستوى ضغط
        }

        // لو الامتداد مش من الأنواع النصية أو مفيش gzencode، بنرجع المحتوى الأصلي زي ما هو
        Log::info("لم يتم تطبيق ضغط على الملف بامتداد {$extension} لأنه غير مدعوم حالياً بشكل مباشر.");
        return $content;
    }

    /**
     * بيتحقق من حصة التخزين المسموح بها للمستخدم.
     *
     * @param UploadedFile $file الملف اللي بيتم رفعه حالياً.
     * @throws Exception لو تم تجاوز الحد الأقصى للتخزين.
     */
    protected function checkQuota(UploadedFile $file)
    {
        $config = config('file-upload.quota');
        if (!$config['enabled'] || !auth()->check()) {
            return; // لو الحصة مش مفعلة أو مفيش مستخدم مسجل دخول، بنرجع على طول.
        }

        $userId = auth()->id();
        $modelClass = config('file-upload.database.model');
        // هنا بنجمع حجم الملفات اللي رفعها المستخدم قبل كده.
        $totalSize = $modelClass::where('user_id', $userId)->sum('size');
        $newSize = $file->getSize();

        // بنتحقق لو الحجم الكلي مع الملف الجديد هيتجاوز الحد المسموح بيه.
        if (($totalSize + $newSize) > $config['max_size_per_user']) {
            $maxSizeMB = round($config['max_size_per_user'] / (1024 * 1024), 2);
            Log::warning("المستخدم {$userId} تجاوز حد التخزين المسموح به: {$maxSizeMB}MB");
            throw new Exception('تم تجاوز حد التخزين المسموح للمستخدم. الحد الأقصى: ' . $maxSizeMB . 'MB');
        }
    }

    /**
     * بيولد مسار حفظ الملف.
     *
     * @param string $basePath المسار الأساسي.
     * @param string $folderName اسم الفولدر.
     * @return string المسار النهائي.
     */
    protected function generatePath(string $basePath, string $folderName)
    {
        $path = trim($basePath . '/' . $folderName, '/');
        return $path;
    }

    /**
     * بيجيب الـ URL الكامل للملف.
     *
     * @param array $config إعدادات الـ config.
     * @param string $disk اسم الـ disk.
     * @param string $path مسار الملف النسبي.
     * @return string الـ URL الكامل للملف.
     */
    protected function getFileUrl(array $config, string $disk, string $path)
    {
        $url = Storage::disk($disk)->url($path);
        // لو الـ CDN مفعل، بنستخدم الـ URL بتاع الـ CDN
        if ($config['storage']['cdn']['enabled'] && $config['storage']['cdn']['url']) {
            // بنشيل الـ domain بتاع الـ Storage disk من الـ URL ونضيف الـ CDN URL بداله
            $parsedStorageUrl = parse_url($url);
            $relativePath = ltrim($parsedStorageUrl['path'], '/'); // بنشيل الـ leading slash
            $url = rtrim($config['storage']['cdn']['url'], '/') . '/' . $relativePath;
        }
        return $url;
    }

    /**
     * بيحذف ملف أو مجموعة ملفات.
     *
     * @param int|string|array $idOrPath الـ ID بتاع الملف في الداتابيز، أو مسار الملف، أو مصفوفة منهم.
     * @return array حالة الحذف.
     */
    public function delete($idOrPath)
    {
        // لو بنحذف مجموعة ملفات
        if (is_array($idOrPath)) {
            $results = [];
            foreach ($idOrPath as $item) {
                try {
                    $results[] = $this->processDelete($item);
                } catch (Exception $e) {
                    $results[] = ['status' => 'فشل الحذف', 'error' => $e->getMessage(), 'item' => $item];
                }
            }
            return $results;
        }

        // لو بنحذف ملف واحد
        return $this->processDelete($idOrPath);
    }

    /**
     * بيكمل عملية حذف ملف واحد.
     *
     * @param int|string $idOrPath الـ ID أو المسار بتاع الملف.
     * @return array حالة الحذف.
     * @throws Exception لو الملف مش موجود أو حصل خطأ في الحذف.
     */
    protected function processDelete($idOrPath)
    {
        $config = config('file-upload');
        $disk = $config['storage']['disk'];
        $file = null;

        try {
            if ($config['database']['enabled']) {
                $modelClass = $config['database']['model'];
                // بنشوف إذا كان ID أو path
                $file = is_numeric($idOrPath)
                    ? $modelClass::findOrFail($idOrPath)
                    : $modelClass::where('path', $idOrPath)->firstOrFail();

                // حذف الملف الأساسي من الـ Storage
                if (Storage::disk($disk)->exists($file->path)) {
                    Storage::disk($disk)->delete($file->path);
                } else {
                    Log::warning("الملف الأساسي مش موجود في الـ Storage عند الحذف: " . $file->path);
                }

                // حذف الـ thumbnails لو مفعلة
                if ($config['thumbnails']['enabled']) {
                    $originalFileName = $file->name; // اسم الملف اللي متخزن في الداتابيز (UUID.ext)
                    $dirName = dirname($file->path);
                    foreach ($config['thumbnails']['sizes'] as $sizeName => $size) {
                        $thumbnailPath = $dirName . "/thumb_{$sizeName}_" . $originalFileName;
                        if (Storage::disk($disk)->exists($thumbnailPath)) {
                            Storage::disk($disk)->delete($thumbnailPath);
                        } else {
                            Log::warning("Thumbnail مش موجود عند الحذف: " . $thumbnailPath);
                        }
                    }
                }

                // حذف سجل الملف من الداتابيز
                $file->delete();
                Log::info("تم حذف الملف بنجاح (ID: {$idOrPath}, Path: {$file->path}).");

            } else {
                // لو الداتابيز مش مفعلة، بنعتمد على الـ path مباشرة
                if (!is_string($idOrPath)) {
                    throw new Exception('عشان تحذف ملف بدون داتابيز، لازم تبعت المسار (string).');
                }
                if (Storage::disk($disk)->exists($idOrPath)) {
                    Storage::disk($disk)->delete($idOrPath);
                    Log::info("تم حذف الملف بنجاح من الـ Storage (Path: {$idOrPath}).");
                } else {
                    Log::warning("الملف مش موجود في الـ Storage للحذف: " . $idOrPath);
                    throw new Exception('الملف غير موجود في الـ Storage.');
                }
            }
            return ['status' => 'تم حذف الملف بنجاح'];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("لم يتم العثور على الملف في الداتابيز للحذف: " . $idOrPath);
            throw new Exception('الملف مش موجود في الداتابيز.');
        } catch (Exception $e) {
            Log::error("خطأ أثناء حذف الملف (ID/Path: {$idOrPath}): " . $e->getMessage());
            throw new Exception('فشل حذف الملف: ' . $e->getMessage());
        }
    }
}
