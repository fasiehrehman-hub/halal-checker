<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatController;

Route::get('/', [ChatController::class, 'index'])->name('chat.index');

Route::prefix('chat')->name('chat.')->group(function () {
    Route::post('/send', [ChatController::class, 'send'])->name('send');
    Route::post('/location-preference', [ChatController::class, 'saveLocationPreference'])->name('location.save');
    Route::delete('/location-preference', [ChatController::class, 'clearLocationPreference'])->name('location.clear');
});