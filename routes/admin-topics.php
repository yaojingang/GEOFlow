<?php

use App\Http\Controllers\Admin\TopicBatchController;
use App\Http\Controllers\Admin\TopicController;
use App\Http\Controllers\Admin\TopicPathController;
use App\Http\Controllers\Admin\TopicRunController;
use App\Http\Controllers\Admin\TopicSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('topics')->name('topics.')->group(function (): void {
    Route::get('/', [TopicController::class, 'index'])->name('index');
    Route::get('create', [TopicController::class, 'create'])->name('create');
    Route::post('/', [TopicController::class, 'store'])->name('store');
    Route::post('bulk', [TopicController::class, 'bulk'])->name('bulk');
    Route::get('articles', [TopicController::class, 'articles'])->name('articles');
    Route::get('settings', [TopicSettingsController::class, 'index'])->name('settings');
    Route::post('settings/template-rollback', [TopicSettingsController::class, 'rollback'])->name('settings.rollback');
    Route::post('settings', [TopicSettingsController::class, 'store'])->name('settings.save');
    Route::get('batches/create', [TopicBatchController::class, 'create'])->name('batches.create');
    Route::post('batches/preview', [TopicBatchController::class, 'preview'])->name('batches.preview');
    Route::post('batches', [TopicBatchController::class, 'store'])->name('batches.store');
    Route::get('batches/{batch}', [TopicBatchController::class, 'show'])->whereNumber('batch')->name('batches.show');
    Route::get('batches/{batch}/status', [TopicBatchController::class, 'status'])->whereNumber('batch')->name('batches.status');
    Route::post('batches/{batch}/{action}', [TopicBatchController::class, 'action'])->whereNumber('batch')->whereIn('action', ['cancel', 'retry', 'continue'])->name('batches.action');
    Route::get('runs/{run}', [TopicRunController::class, 'show'])->whereNumber('run')->name('runs.show');
    Route::get('runs/{run}/status', [TopicRunController::class, 'status'])->whereNumber('run')->name('runs.status');
    Route::post('runs/{run}/{action}', [TopicRunController::class, 'action'])->whereNumber('run')->whereIn('action', ['cancel', 'retry', 'adopt', 'ignore'])->name('runs.action');
    Route::get('{topic}/path', [TopicPathController::class, 'edit'])->whereNumber('topic')->name('paths.edit');
    Route::post('{topic}/path/preview', [TopicPathController::class, 'preview'])->whereNumber('topic')->name('paths.preview');
    Route::post('{topic}/path/confirm', [TopicPathController::class, 'confirm'])->whereNumber('topic')->name('paths.confirm');
    Route::get('{topic}/edit', [TopicController::class, 'edit'])->whereNumber('topic')->name('edit');
    Route::put('{topic}', [TopicController::class, 'update'])->whereNumber('topic')->name('update');
    Route::get('{topic}/preview', [TopicController::class, 'preview'])->whereNumber('topic')->name('preview');
    Route::get('{topic}/history', [TopicController::class, 'history'])->whereNumber('topic')->name('history');
    Route::get('{topic}/history/{revision}', [TopicController::class, 'revision'])->whereNumber(['topic', 'revision'])->name('revisions.preview');
    Route::post('{topic}/history/{revision}/restore', [TopicController::class, 'restoreRevision'])->whereNumber(['topic', 'revision'])->name('revisions.restore');
    Route::post('{topic}/{action}', [TopicController::class, 'action'])->whereNumber('topic')->whereIn('action', ['publish', 'approve', 'withdraw', 'trash', 'restore'])->name('action');
});
