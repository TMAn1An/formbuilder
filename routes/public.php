<?php

use Illuminate\Support\Facades\Route;
use TMAn1An\FormBuilder\Http\Controllers\PublicFormController;
use TMAn1An\FormBuilder\Http\Controllers\PublicPageController;

/*
|--------------------------------------------------------------------------
| Public form + page routes
|--------------------------------------------------------------------------
| Registered with config('formbuilder.public') name / middleware. Only
| published forms/pages resolve; everything else 404s (see FormAvailability
| and PublicPageController).
*/

$forms = trim((string) config('formbuilder.public.forms_prefix', 'forms'), '/');
$pages = trim((string) config('formbuilder.public.pages_prefix', 'pages'), '/');

Route::get("/{$forms}/{form:slug}", [PublicFormController::class, 'show'])->name('forms.show');
Route::post("/{$forms}/{form:slug}", [PublicFormController::class, 'submit'])
    ->middleware('throttle:'.config('formbuilder.public.submit_throttle', '30,1'))
    ->name('forms.submit');

Route::get("/{$pages}/{page:slug}", [PublicPageController::class, 'show'])->name('pages.show');
