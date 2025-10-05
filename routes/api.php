<?php

use Illuminate\Support\Facades\Route;

Route::post('/upload/video', [\App\Http\Controllers\UploadController::class, 'video']);
Route::post('/upload/attachment', [\App\Http\Controllers\UploadController::class, 'attachment']);
Route::post('/process/{video}', [\App\Http\Controllers\ProcessController::class, 'reprocess']);
Route::get('/videos/{video}/status', [\App\Http\Controllers\UploadController::class, 'status']);


