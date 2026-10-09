<?php

namespace TMAn1An\FormBuilder;

use Illuminate\Support\Facades\Route;

/**
 * Small URL helper shared by package controllers and views (views get it as
 * `$fb`). Route names are configurable per host (config('formbuilder.admin.name')
 * / config('formbuilder.public.name')), so package code never hard-codes a
 * full route name.
 */
class FormBuilder
{
    /** Full name of an admin route, e.g. "forms.index" -> "admin.forms.index". */
    public function adminName(string $name): string
    {
        return config('formbuilder.admin.name').$name;
    }

    public function publicName(string $name): string
    {
        return config('formbuilder.public.name').$name;
    }

    public function admin(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route($this->adminName($name), $parameters, $absolute);
    }

    public function public(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route($this->publicName($name), $parameters, $absolute);
    }

    /** True when the current request is on one of the given admin routes ("forms.*"). */
    public function adminRouteIs(string ...$patterns): bool
    {
        return Route::is(...array_map($this->adminName(...), $patterns));
    }

    /** Versioned URL of a package asset (served by AssetController). */
    public function asset(string $path): string
    {
        $file = self::distPath($path);
        $version = is_file($file) ? substr(md5((string) filemtime($file).filesize($file)), 0, 10) : '0';

        return route('formbuilder.assets', ['path' => $path, 'v' => $version]);
    }

    public static function distPath(string $path = ''): string
    {
        return dirname(__DIR__).'/resources/dist'.($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}
