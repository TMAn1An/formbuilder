# Integrating the package into a host application

Everything host-specific is configured in `config/formbuilder.php`. The package never references
host classes directly.

```bash
php artisan vendor:publish --tag=formbuilder-config
```

## 1. Users

```php
'user_model' => App\Models\User::class,   // created_by / updated_by / submitted_by relations
'users_table' => 'users',                 // FK target for the package's tables
```

## 2. Authorization: the `Authorizer` contract

Every role decision in `FormPolicy` / `PagePolicy` goes through
`TMAn1An\FormBuilder\Contracts\Authorizer`:

| Method | Grants |
|---|---|
| `canAccess($user)` | use the builders: list/create/edit/publish/deactivate/duplicate forms & pages, view & export submissions, upload page images; fill in *private* forms |
| `canManageCustomCode($user)` | form/page custom CSS, custom HTML before/after a form, stored-only custom JS |
| `canArchive($user)` | archive / restore forms and pages |

Archived forms/pages are read-only for everyone, and no ability exists to delete a form, page or
submission.

**Default (`GateAuthorizer`)** checks these Gates if you define them, otherwise falls back to safe
defaults:

| Gate | Default when undefined |
|---|---|
| `formbuilder.access` | any authenticated user |
| `formbuilder.custom-code` | nobody |
| `formbuilder.archive` | nobody |

```php
// AppServiceProvider::boot()
Gate::define('formbuilder.custom-code', fn ($user) => $user->is_admin);
Gate::define('formbuilder.archive', fn ($user) => $user->is_admin);
```

Or bind your own class: `'authorizer' => App\FormBuilder\MyAuthorizer::class`.

## 3. Audit trail: the `AuditLogger` contract

`TMAn1An\FormBuilder\Contracts\AuditLogger::record(AuditEvent $event, Model $subject, $actor, $summary, $metadata)`
receives `form_created|updated|published|deactivated|archived|restored|duplicated` and the same
`page_*` events. The default `NullAuditLogger` records nothing. Bind an adapter to write into your
own audit table. Builder autosaves are not reported individually; the next explicit save is.

## 4. Routes and route names

```php
'admin' => [
    'prefix' => 'formbuilder',            // /formbuilder/forms, /formbuilder/pages
    'name' => 'formbuilder.admin.',       // route names: {name}forms.index, {name}pages.edit, ...
    'middleware' => ['web', 'auth'],      // add your own, e.g. 'verified', 'active', 'can:...'
],
'public' => [
    'forms_prefix' => 'forms',            // /forms/{slug}
    'pages_prefix' => 'pages',            // /pages/{slug}
    'name' => 'formbuilder.',             // {name}forms.show, {name}forms.submit, {name}pages.show
    'middleware' => ['web'],
    'submit_throttle' => '30,1',          // per-IP throttle on submissions
],
```

Two internal routes always use fixed names: `formbuilder.assets` (CSS/JS) and
`formbuilder.media` (page images), under `config('formbuilder.assets.prefix')`.

Link to the builder from your own navigation with your configured names, e.g.
`route(config('formbuilder.admin.name').'forms.index')`.

## 5. Layouts

Admin screens render inside `config('formbuilder.admin.layout')`. Public forms and pages render
inside `config('formbuilder.public.layout')`. Both are Blade component names. The package ships
minimal standalone defaults (`formbuilder::admin-layout`, `formbuilder::public-layout`).

**Admin layout contract:** props `title` (string), `wide` (bool), a `head` slot (the package puts
its stylesheets/meta there) and the default slot. Display `session('status')` and `$errors` if
you want flash messages.

```blade
{{-- resources/views/components/my-builder-admin.blade.php --}}
@props(['title' => 'Builder', 'wide' => false])
<x-layouts.admin :title="$title" :wide="$wide">
  <x-slot:head>{{ $head ?? '' }}</x-slot:head>
  {{ $slot }}
</x-layouts.admin>
```

**Public layout contract:** props `title` and `description` as **plain text** (the layout must
escape them), `url`, plus `head` and `scripts` slots. Output `head` somewhere inside the page (it
contains `<link rel="stylesheet">` tags) and `scripts` before `</body>`.

All package markup uses its own prefixed classes (`fba-*` admin, `ff-*` forms, `pb-*` pages,
`fb-*` builders) scoped so it never restyles the host. Admin colours are CSS variables
(`--fba-accent`, `--fba-line`, …) that you may override.

## 6. Storage

| What | Disk (config) | Path | Delivery |
|---|---|---|---|
| Form submission images | `storage.submissions_disk` (default `local`, **private**) | `form-builder/submissions/{form}/{submission}/{uuid}.{ext}` | only via the authenticated admin route `…/forms/{form}/submissions/{submission}/files/{file}` |
| Page images | `storage.media_disk` (default `public`) | `form-builder/pages/{yyyy}/{mm}/{uuid}.{ext}` | `media_delivery = route` (default): served by the package at `/formbuilder-assets/media/{uuid}/…`, **no `storage:link` needed**. `url`: uses `Storage::url()`, which needs `php artisan storage:link` |

Only metadata and paths are stored in the database, never image bytes. Accepted image types are
JPG, PNG and WebP, checked by detected content type plus an actual image decode. SVG is never
accepted.

## 7. Assets

CSS/JS live in the package (`resources/dist`) and are served by `AssetController` with a
content-hash `?v=` and long-term cache headers. There is no publish step, and assets can't go stale
after an update.

## 8. Migrations and existing data

`php artisan migrate` runs the package's migrations (`loadMigrationsFrom`). Laravel tracks
migrations by **filename**, so a host that previously shipped identically named migrations
(e.g. the IEEE app's original `2026_10_09_1000xx_create_form*` files) sees them as already run.
Its existing tables and data are left untouched, and only the newer package migrations run.

## 9. Upgrading

```bash
composer update tman1an/formbuilder
php artisan migrate
php artisan view:clear     # if you published/overrode package views
```

Read [CHANGELOG.md](../CHANGELOG.md) first. Published config is never overwritten by updates.
Compare it against `config/formbuilder.php` in the package for new keys (missing keys fall back to
the package defaults).

## 10. Deployment checklist

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force`
3. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
4. Ensure the configured disks are writable (`storage/app/private`, `storage/app/public`).
5. `php artisan storage:link` only if you set `media_delivery` to `url`.
