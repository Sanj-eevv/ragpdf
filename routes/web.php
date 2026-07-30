<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Dashboard')->name('dashboard');

Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
