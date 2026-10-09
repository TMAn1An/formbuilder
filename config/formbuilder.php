<?php

use TMAn1An\FormBuilder\Audit\NullAuditLogger;
use TMAn1An\FormBuilder\Auth\GateAuthorizer;

/*
|--------------------------------------------------------------------------
| Form + Page Builder configuration
|--------------------------------------------------------------------------
| Publish with: php artisan vendor:publish --tag=formbuilder-config
| Everything host-specific (auth, roles, audit trail, layouts, URLs) is
| configured here -- the package never references host classes directly.
| See docs/INTEGRATION.md.
*/

return [

    // The host's user model (used for created_by/updated_by/submitted_by relations).
    'user_model' => env('FORMBUILDER_USER_MODEL', 'App\\Models\\User'),
    'users_table' => 'users',

    // Who may do what. Must implement TMAn1An\FormBuilder\Contracts\Authorizer.
    // The default delegates to optional Gates (see docs/INTEGRATION.md §Authorization).
    'authorizer' => GateAuthorizer::class,

    // Where builder actions (created, published, archived, ...) are recorded.
    // Must implement TMAn1An\FormBuilder\Contracts\AuditLogger.
    'audit_logger' => NullAuditLogger::class,

    'admin' => [
        'enabled' => true,
        'prefix' => 'formbuilder',
        // Route-name prefix: routes are named "{name}forms.index", "{name}pages.edit", ...
        'name' => 'formbuilder.admin.',
        'middleware' => ['web', 'auth'],
        // Blade component wrapping every admin screen. Receives `title` and
        // `wide`, plus a `head` slot. See docs/INTEGRATION.md §Layouts.
        'layout' => 'formbuilder::admin-layout',
    ],

    'public' => [
        'enabled' => true,
        'forms_prefix' => 'forms',
        'pages_prefix' => 'pages',
        // Route-name prefix: "{name}forms.show", "{name}forms.submit", "{name}pages.show", ...
        'name' => 'formbuilder.',
        'middleware' => ['web'],
        // Blade component wrapping public forms/pages. Receives `title`
        // (plain text), `description`, `url`, plus `head` and `scripts` slots.
        'layout' => 'formbuilder::public-layout',
        // Laravel throttle middleware spec for public submissions (per IP).
        'submit_throttle' => '30,1',
    ],

    // CSS/JS are served by the package itself (no vendor:publish step).
    'assets' => [
        'prefix' => 'formbuilder-assets',
    ],

    'storage' => [
        // Submission uploads are personal data: keep them on a PRIVATE disk.
        // They are only ever served through an authenticated admin route.
        'submissions_disk' => env('FORMBUILDER_SUBMISSIONS_DISK', 'local'),
        'submissions_path' => 'form-builder/submissions',

        // Page images are public content.
        'media_disk' => env('FORMBUILDER_MEDIA_DISK', 'public'),
        'media_path' => 'form-builder/pages',
        // 'route': served through the package's media route (works on any
        //          disk, no storage:link needed).
        // 'url':   Storage::disk(media_disk)->url() -- requires a public disk
        //          and `php artisan storage:link`.
        'media_delivery' => env('FORMBUILDER_MEDIA_DELIVERY', 'route'),
    ],

    'uploads' => [
        // Hard ceiling for any single image (KB); per-field limits must stay below it.
        'max_kb' => 10240,
        'default_field_max_kb' => 4096,
    ],
];
