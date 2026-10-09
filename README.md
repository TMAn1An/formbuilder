# tman1an/formbuilder — Form + Page Builder for Laravel

A reusable Laravel package (Laravel 11/12, PHP 8.2+) that adds two visual builders to an
existing application:

- **Form Builder**: 19 field types including image upload, responsive widths, a full design
  system (container, labels, inputs, a fully sizable submit button, messages), conditional
  visibility, sanitized custom HTML, scoped custom CSS, public URLs, admin preview, submissions
  with historical snapshots, and Excel export.
- **Page Builder**: simple block-based pages (section, columns, heading, paragraph, image,
  button, divider, spacer, embedded form, custom HTML), page design settings, scoped custom CSS,
  public URLs and admin preview. A Form block **references** a form and renders it with the same
  renderer as `/forms/{slug}`, so a form looks, validates and stores submissions identically
  wherever it appears.

The package owns the models, migrations, services, policies, controllers, routes, views, CSS and
JS. The host application provides authentication, its role model (through a small `Authorizer`
contract), an optional audit trail (`AuditLogger` contract), and the admin/site layouts.

## Requirements

- PHP 8.2+, Laravel 11 or 12
- An authenticated admin area (any guard/middleware the host already uses)
- A database supported by Laravel (MySQL/MariaDB/SQLite tested)
- PHP `fileinfo` and `gd` (or another image decoder `getimagesize()` supports) for image uploads

No Node, Redis, queues or Docker are needed at runtime. CSS and JS are plain files served by the
package itself, so there is no build step and no `vendor:publish` for assets.

## Installation

Until the package is on Packagist, install it from GitHub:

```json
// composer.json of the host app
"repositories": [
    { "type": "vcs", "url": "https://github.com/TMAn1An/formbuilder" }
],
"require": {
    "tman1an/formbuilder": "^0.1"
}
```

```bash
composer update tman1an/formbuilder
php artisan vendor:publish --tag=formbuilder-config   # optional: config/formbuilder.php
php artisan migrate
```

The service provider is auto-discovered. With the default config:

| URL | What |
|---|---|
| `/formbuilder/forms`, `/formbuilder/pages` | admin (middleware `web`, `auth`) |
| `/forms/{slug}` | public form (published forms only) |
| `/pages/{slug}` | public page (published pages only) |

Then follow **[docs/INTEGRATION.md](docs/INTEGRATION.md)** to connect your roles, audit trail
and layouts. Out of the box, any signed-in user can use the builders. Custom code and
archive/restore are disabled until you grant them.

## Documentation

| Doc | Contents |
|---|---|
| [docs/INTEGRATION.md](docs/INTEGRATION.md) | Config, the `Authorizer`/`AuditLogger` contracts, layouts, routes & names, storage, assets, upgrading, deployment |
| [docs/FORMS.md](docs/FORMS.md) | Form Builder reference: schema, field types, styling (incl. button sizing), image uploads, conditional logic, validation, submissions & snapshots, Excel export, public URLs/preview/copy link, security |
| [docs/PAGES.md](docs/PAGES.md) | Page Builder reference: blocks, save payload & validation invariants, form embedding, page media, publishing, security |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Working on the package: tests, Pint, developing against a host app with a Composer path repository |
| [CHANGELOG.md](CHANGELOG.md) | Release notes |

## Testing

```bash
composer install
vendor/bin/phpunit      # Orchestra Testbench, in-memory SQLite
vendor/bin/pint --test
```
