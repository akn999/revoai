<?php

use App\Http\Controllers\Platform\MediaFileController;
use App\Http\Middleware\AllowFramingBySalla;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::inertia('embedded', 'Embedded')->middleware(AllowFramingBySalla::class)->name('embedded');

Route::get('media/{image}', MediaFileController::class)->middleware('signed')->name('media.file');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
