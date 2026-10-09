<?php

namespace TMAn1An\FormBuilder\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use TMAn1An\FormBuilder\FormBuilder;

/**
 * Serves the package's own CSS/JS from resources/dist, so hosts need no
 * vendor:publish step and can never run stale assets after a package
 * update (URLs carry a content-based ?v= version from FormBuilder::asset()).
 * Only real files inside resources/dist with a .css/.js extension resolve.
 */
class AssetController extends Controller
{
    private const TYPES = ['css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8'];

    public function show(string $path): BinaryFileResponse
    {
        $root = realpath(FormBuilder::distPath());
        $file = realpath(FormBuilder::distPath($path));

        abort_if($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file), 404);

        $extension = pathinfo($file, PATHINFO_EXTENSION);
        abort_unless(isset(self::TYPES[$extension]), 404);

        return response()->file($file, [
            'Content-Type' => self::TYPES[$extension],
            'Cache-Control' => request()->has('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
