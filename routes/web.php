<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\QueryController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Dashboard')->name('dashboard');

Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
Route::post('queries', [QueryController::class, 'store'])->name('queries.store');
