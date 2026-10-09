# Developing the package

## Tests and style

```bash
composer install
vendor/bin/phpunit          # Orchestra Testbench + in-memory SQLite; no host app needed
vendor/bin/pint             # fix style
vendor/bin/pint --test      # check style (CI)
```

`tests/TestCase.php` boots a throwaway Laravel app with a fixture `User` (with an `is_admin`
flag), gates for custom code/archive, and a `RecordingAuditLogger`. These are the same
extension points a real host uses. Admin routes use the `admin` prefix in tests.

| Suite | Covers |
|---|---|
| `tests/Feature/FormBuilderTest.php` | builder saves, persistence, keys, design, custom code, conditions, lifecycle, duplication |
| `tests/Feature/FormSubmissionTest.php` | public submissions, validation, conditional logic, snapshots, limits |
| `tests/Feature/FormSubmissionExportTest.php` | Excel columns, rename/archive stability, formula guard |
| `tests/Feature/ButtonStyleTest.php` | button sizing/width/padding/alignment for forms and page buttons |
| `tests/Feature/ImageUploadTest.php` | image field validation, storage, cleanup, admin-only delivery, export links |
| `tests/Feature/PublicFormWorkflowTest.php` | public URLs, View live, copy link, preview, asset route |
| `tests/Feature/PageDefinitionValidationTest.php` | every page-save invariant + transactional rollback |
| `tests/Feature/PageBuilderTest.php` | block rendering, media, embedding, publishing, duplication, security |
| `tests/Unit/*` | HTML sanitizer, CSS scoper, conditional-logic resolver |

## Working on the package from a host app

Use a Composer **path repository** so the host runs your working copy live:

```json
// host composer.json (local development only)
"repositories": [
    { "type": "path", "url": "../formbuilder", "options": { "symlink": true } }
],
"require": { "tman1an/formbuilder": "*@dev" }
```

```bash
composer update tman1an/formbuilder     # symlinks (junction on Windows) vendor/tman1an/formbuilder -> ../formbuilder
```

Edits in `../formbuilder` are picked up immediately (PHP, views, CSS, JS). Run
`php artisan migrate` after adding migrations.

**Before committing the host**, switch back to the GitHub VCS repository and a released tag, so the
host's `composer.lock` points at a commit anyone (and the production server) can fetch:

```bash
composer config repositories.formbuilder vcs https://github.com/TMAn1An/formbuilder
composer config --unset repositories.0          # remove the path entry (check `composer config repositories`)
composer require tman1an/formbuilder:^0.1
```

## Releasing

1. Update `CHANGELOG.md`.
2. Commit, then tag: `git tag v0.1.1 && git push origin main --tags`.
3. In each host: `composer update tman1an/formbuilder`, run its tests, commit the updated
   `composer.lock`.

## Conventions

- Business logic lives in services (`src/Services/...`); controllers stay thin; Form Requests
  validate before anything is written.
- Anything an admin types that reaches HTML goes through `Support\HtmlSanitizer`. Anything that
  reaches CSS is either a schema-validated value or custom CSS passed through `Support\CssScoper`.
- Style properties are defined once (`Styling\*Schema`). The JS builders receive the same
  definitions, so the preview can never disagree with the server.
- No host classes, ever. Extend through config and the two contracts.
