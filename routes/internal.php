<?php

use Illuminate\Support\Facades\Route;
use TMAn1An\FormBuilder\Http\Controllers\AssetController;
use TMAn1An\FormBuilder\Http\Controllers\MediaController;

/*
|--------------------------------------------------------------------------
| Package-internal public routes (fixed names)
|--------------------------------------------------------------------------
| Assets (CSS/JS shipped in resources/dist) and public page media. Named
| "formbuilder.assets" / "formbuilder.media" regardless of the host's
| route-name prefixes, so they can never collide with host route names.
*/

$prefix = trim((string) config('formbuilder.assets.prefix', 'formbuilder-assets'), '/');

Route::get("/{$prefix}/media/{media:uuid}/{filename?}", [MediaController::class, 'show'])->name('formbuilder.media');
Route::get("/{$prefix}/{path}", [AssetController::class, 'show'])->where('path', '[a-z0-9\-/]+\.(css|js)')->name('formbuilder.assets');
