<?php

use Illuminate\Support\Facades\Route;
use TMAn1An\FormBuilder\Http\Controllers\Admin\FormController;
use TMAn1An\FormBuilder\Http\Controllers\Admin\FormSubmissionController;
use TMAn1An\FormBuilder\Http\Controllers\Admin\PageController;
use TMAn1An\FormBuilder\Http\Controllers\Admin\PageMediaController;

/*
|--------------------------------------------------------------------------
| Builder admin routes
|--------------------------------------------------------------------------
| Registered by FormBuilderServiceProvider with config('formbuilder.admin')
| prefix / name / middleware. Authorization: FormPolicy / PagePolicy, which
| delegate role decisions to the host's Authorizer. There is deliberately no
| DELETE route for forms, pages or submissions: forms/pages are archived,
| submissions are permanent.
*/

Route::prefix('forms')->name('forms.')->group(function () {
    Route::get('/', [FormController::class, 'index'])->name('index');
    Route::get('/create', [FormController::class, 'create'])->name('create');
    Route::post('/', [FormController::class, 'store'])->name('store');
    // Literal segment -- registered before the {form}-bound routes.
    Route::get('/submissions', [FormSubmissionController::class, 'overview'])->name('submissions.overview');

    Route::get('/{form}/edit', [FormController::class, 'edit'])->name('edit');
    Route::put('/{form}/builder', [FormController::class, 'update'])->name('update');
    Route::post('/{form}/builder/sanitize', [FormController::class, 'sanitize'])->name('sanitize');
    Route::get('/{form}/preview', [FormController::class, 'preview'])->name('preview');
    Route::post('/{form}/publish', [FormController::class, 'publish'])->name('publish');
    Route::post('/{form}/deactivate', [FormController::class, 'deactivate'])->name('deactivate');
    Route::post('/{form}/archive', [FormController::class, 'archive'])->name('archive');
    Route::post('/{form}/restore', [FormController::class, 'restore'])->name('restore');
    Route::post('/{form}/duplicate', [FormController::class, 'duplicate'])->name('duplicate');

    Route::get('/{form}/submissions', [FormSubmissionController::class, 'index'])->name('submissions.index');
    Route::get('/{form}/submissions/export.xlsx', [FormSubmissionController::class, 'export'])->name('submissions.export');
    // scopeBindings(): ids from another form/submission 404 (no cross-form IDOR).
    Route::get('/{form}/submissions/{submission}', [FormSubmissionController::class, 'show'])->name('submissions.show')->scopeBindings();
    Route::get('/{form}/submissions/{submission}/files/{file}', [FormSubmissionController::class, 'file'])->name('submissions.file')->scopeBindings();
});

Route::prefix('pages')->name('pages.')->group(function () {
    Route::get('/', [PageController::class, 'index'])->name('index');
    Route::get('/create', [PageController::class, 'create'])->name('create');
    Route::post('/', [PageController::class, 'store'])->name('store');
    // Literal segments -- registered before the {page}-bound routes.
    Route::post('/media', [PageMediaController::class, 'store'])->name('media.store');
    Route::get('/form-preview/{form}', [PageController::class, 'formPreview'])->name('form-preview');

    Route::get('/{page}/edit', [PageController::class, 'edit'])->name('edit');
    Route::put('/{page}/builder', [PageController::class, 'update'])->name('update');
    Route::post('/{page}/builder/sanitize', [PageController::class, 'sanitize'])->name('sanitize');
    Route::get('/{page}/preview', [PageController::class, 'preview'])->name('preview');
    Route::post('/{page}/publish', [PageController::class, 'publish'])->name('publish');
    Route::post('/{page}/deactivate', [PageController::class, 'deactivate'])->name('deactivate');
    Route::post('/{page}/archive', [PageController::class, 'archive'])->name('archive');
    Route::post('/{page}/restore', [PageController::class, 'restore'])->name('restore');
    Route::post('/{page}/duplicate', [PageController::class, 'duplicate'])->name('duplicate');
});
