<?php

use App\Http\Controllers\UploadSessionController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| VideoShrink Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', [VideoController::class, 'index'])->name('dashboard');
Route::get('/videos', [VideoController::class, 'index'])->name('videos.index');

// Sequential Chunked Upload
Route::prefix('upload')->name('upload.')->group(function () {
    Route::post('/start', [UploadSessionController::class, 'start'])->name('start');
    Route::post('/chunk', [UploadSessionController::class, 'chunk'])->name('chunk');
    Route::post('/complete', [UploadSessionController::class, 'complete'])->name('complete');
});

// Video Processing & Management
Route::prefix('videos/{video:uuid}')->name('videos.')->group(function () {
    Route::post('/compress', [VideoController::class, 'compress'])->name('compress');
    Route::get('/status', [VideoController::class, 'status'])->name('status');
    Route::get('/preview', [VideoController::class, 'preview'])->name('preview');
    Route::get('/download', [VideoController::class, 'download'])->name('download');
    Route::post('/retry', [VideoController::class, 'retry'])->name('retry');
    Route::delete('/', [VideoController::class, 'destroy'])->name('destroy');
});
