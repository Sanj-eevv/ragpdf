<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\RagasEvaluationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/documents');

Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
Route::post('queries', [QueryController::class, 'store'])->name('queries.store');

Route::get('evaluation', [RagasEvaluationController::class, 'index'])->name('evaluation.index');
Route::post('evaluation', [RagasEvaluationController::class, 'store'])->name('evaluation.store');
Route::get('evaluation/{run}/download', [RagasEvaluationController::class, 'download'])->name('evaluation.download');
Route::post('evaluation/{run}/cancel', [RagasEvaluationController::class, 'cancel'])->name('evaluation.cancel');
