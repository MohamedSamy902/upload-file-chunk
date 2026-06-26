<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MohamedSamy902\AdvancedFileUpload\Http\Controllers\MediaManagerController;

$prefix     = config('file-upload.ui.route_prefix', 'advanced-file-upload');
$middleware = config('file-upload.ui.middleware', ['web']);

Route::prefix($prefix)
    ->middleware($middleware)
    ->name('advanced-file-upload.')
    ->group(function () {
        Route::get('/',              [MediaManagerController::class, 'index'])->name('index');
        Route::get('/media',         [MediaManagerController::class, 'media'])->name('media');
        Route::get('/config',        [MediaManagerController::class, 'config'])->name('config');
        Route::post('/config',       [MediaManagerController::class, 'saveConfig'])->name('config.save');
        Route::get('/sessions',      [MediaManagerController::class, 'sessions'])->name('sessions');

        Route::post('/media/bulk-destroy',       [MediaManagerController::class, 'bulkDestroy'])->name('media.bulk-destroy');
        Route::post('/media/bulk-force-destroy', [MediaManagerController::class, 'bulkForceDestroy'])->name('media.bulk-force-destroy');

        Route::delete('/media/{id}',           [MediaManagerController::class, 'destroy'])->name('media.destroy');
        Route::delete('/media/{id}/force',     [MediaManagerController::class, 'forceDestroy'])->name('media.force-destroy');
        Route::post('/media/{id}/restore',     [MediaManagerController::class, 'restore'])->name('media.restore');
        Route::post('/media/{id}/mark-used',   [MediaManagerController::class, 'markUsed'])->name('media.mark-used');
        Route::post('/scan',                   [MediaManagerController::class, 'scan'])->name('scan');
    });
