<?php

use Illuminate\Support\Facades\Route;
    use App\Http\Controllers\FileUploadController;

Route::get('/', function () {
    return view('welcome');
});


 Route::get('/', [FileUploadController::class, 'index'])->name('upload.form');
    Route::post('/upload-csv', [FileUploadController::class, 'upload'])->name('upload.csv');
    Route::get('/uploads/status', [FileUploadController::class, 'status'])->name('uploads.status');