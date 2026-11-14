# Form Builder

General-purpose dynamic forms: authorized users visually build and style forms, publish them at
`/forms/{slug}`, embed them in pages, and review/export submissions. Host integration (roles,
audit trail, layouts, URLs) is described in [INTEGRATION.md](INTEGRATION.md).

## Contents

1. [Database schema](#database-schema)
2. [Builder architecture](#builder-architecture)
3. [Field types](#field-types)
4. [Layout system](#layout-system)
5. [Style system](#style-system)
6. [Custom CSS handling](#custom-css-handling)
7. [Custom HTML sanitization](#custom-html-sanitization)
8. [Custom code security](#custom-code-security)
9. [Conditional logic](#conditional-logic)
10. [Public form route](#public-form-route)
11. [Server-side validation](#server-side-validation)
12. [Submission architecture](#submission-architecture)
13. [Historical snapshot strategy](#historical-snapshot-strategy)
14. [Excel export](#excel-export)
15. [Authorization](#authorization)
16. [Audit integration](#audit-integration)
17. [Save reliability](#save-reliability)
18. [Extending: adding a field type](#extending-adding-a-field-type)
19. [Known limitations](#known-limitations)
20. [Submit button styling](#submit-button-styling)
21. [Image upload fields](#image-upload-fields)
22. [Pictures on the form: banner and Image block](#pictures-on-the-form-banner-and-image-block)
23. [Step-by-step forms](#step-by-step-forms)
24. [Public URLs, preview, View live, copy link](#public-urls-preview-view-live-copy-link)

## Database schema

| Table | Purpose |
|---|---|
| `forms` | One row per form. `name`, unique `slug`, `description`, `status` (`TMAn1An\FormBuilder\Enums\FormStatus`: `draft`/`active`/`inactive`/`archived`), `settings` JSON, `style_settings` JSON, `custom_css`, `custom_html_before`, `custom_html_after`, `custom_js` (stored only, never rendered), `lock_version` (optimistic-concurrency counter), `published_at`, `created_by`, `updated_by`. |
| `form_fields` | One row per builder element. `form_id`, `label`, `key` (unique per form **including archived fields**), `type` (`TMAn1An\FormBuilder\Enums\FormFieldType`), `required`, `sort_order`, `settings` JSON, `style_settings` JSON, `conditional_rules` JSON, `is_active` (false = archived). |
| `form_submissions` | One row per accepted submission. `form_id`, `submitted_by` (nullable — public visitors), `submitted_at`, `form_version` (the form's `lock_version` at submission time), `metadata` JSON (IP, user agent). |
| `form_submission_values` | One row per stored value. `form_submission_id`, `form_field_id` (nullable, `nullOnDelete`), `field_key`, `field_label_snapshot`, `field_type_snapshot`, `value` (JSON-encoded: string, list or bool), `display_value` (human-readable snapshot), `created_at` only. Unique (`form_submission_id`, `field_key`). |

Every foreign key from forms/fields/submissions uses `restrictOnDelete`, and no route deletes any of
these rows: forms are archived, fields with submissions are archived, and submissions are permanent.

The JSON columns are never free-form: each has a PHP "schema" class that owns its allowed keys,
validation and defaults (below). `forms.settings.schema_version` is stamped on every save so a future
change to the JSON shape can be migrated deliberately.

## Builder architecture

```
Browser (resources/dist/js/form-builder.js)             Server
─────────────────────────────────────────             ──────────────────────────────────────────────
boots from #form-builder-data  ◄───────────────────── FormBuilderState::forForm() + meta()
  (definition + schemas + permissions)                   (enums/schemas → palette, panels, operators)
holds the whole definition in `state`
renders a live preview with the SAME markup/classes
  as the public renderer + resources/dist/css/forms.css
PUT /admin/forms/{form}/builder (full definition) ──► SaveFormDefinitionRequest
                                                         rules()  ← FormDefinitionValidator::rules()
                                                         after()  ← FormDefinitionValidator::after()
                                                       FormDefinitionValidator::normalize()  (whitelist)
                                                       FormBuilderService::saveDefinition()  (1 transaction)
◄──────────────────────────── 200 {state} │ 422 {errors} │ 409 stale version
POST /admin/forms/{form}/builder/sanitize ──────────► CssScoper / HtmlSanitizer (live preview only)
```

- **Three-column screen**: palette (left) → canvas (center) → settings panel with tabs
  *Field / Design / Settings / Custom code* (right).
- **Adding**: click a palette item (inserts below the selected field) or drag it onto the canvas.
- **Reordering**: ↑/↓ buttons on each field, **Alt+↑ / Alt+↓** on a focused field, or drag-and-drop.
  Buttons and keyboard are always available; drag is optional.
- **Duplicate / remove**: on each field's hover bar and at the bottom of the Field tab. Removing a
  field that has submissions *archives* it (listed under the canvas with a Restore button).
- **Keys**: generated from the label (`Full name` → `full_name`) until the admin edits the key; locked
  once the field has submissions. Renaming a key also rewrites every condition that referenced it.
- **Saving**: *Save draft* / *Publish* (draft & deactivated forms) or *Save changes* (live forms).
  The payload is the complete ordered definition, archived fields included; any stored field missing
  from it is deleted (no submissions) or archived (has submissions).
- **Autosave**: drafts only, 5 seconds after the last change. It never runs on a published form, so a
  half-finished edit can't go live by accident, and it is never the only way to save. Autosaves are not
  individually reported to the audit logger; the next explicit save is.
- **Error handling**: 422 errors are mapped back onto the offending field (red outline + list in the
  Field tab) or tab (dot on the tab label); 409 (someone else saved first), 419 (session expired) and
  403 are shown with instructions. Unsaved changes trigger a "leave page?" warning.

Server classes (all in `app/Services/Forms/`):

| Class | Responsibility |
|---|---|
| `FormBuilderService` | create, saveDefinition, publish/deactivate/archive/restore, duplicate |
| `FormDefinitionValidator` | rules, cross-field checks, normalization of a builder payload |
| `FieldSettingsSchema` / `FormSettingsSchema` | per-field and form-level behavior settings |
| `Style\FormStyleSchema` / `Style\FieldStyleSchema` | design settings → validation → CSS custom properties |
| `Support\HtmlSanitizer` / `Support\CssScoper` | custom HTML / custom CSS safety |
| `FormVisibilityResolver` | conditional logic, server side |
| `FormAvailability` | "can this visitor submit right now?" |
| `FormSubmissionService` | validate + store a submission |
| `FormSubmissionExportService` | Excel export |
| `FormPresenter` / `FormBuilderState` | view data for the renderer / the builder |
| `Contracts\AuditLogger` (host) | receives audit events |

### Builder guidance

The builder is meant to be usable without reading this file: with no field selected, the right
panel shows a short "How to build this form" guide; every tab starts with a one-line hint; the
technical **Key** and **CSS class** of a field are folded under *Advanced* (opened automatically
when they have an error); and the step-by-step switch appears both in Settings → Layout and in
every Section's panel, with an "Add a Section" shortcut when steps are on but the form has none.

## Field types

`TMAn1An\FormBuilder\Enums\FormFieldType` is the single source of truth for what each type is and which settings it
accepts (`settingKeys()`); the builder receives the same metadata (`builderMeta()`).

| Group | Types | Stores a value? |
|---|---|---|
| Inputs | `text`, `long_text`, `email`, `number`, `phone`, `date`, `time`, `datetime`, `hidden` | yes (`hidden`: server-set) |
| Choices | `select` (dropdown), `radio`, `checkbox_group`, `checkbox` (single) | yes |
| Layout & content | `heading`, `paragraph`, `divider`, `section`, `html`, `image_block` (Image) | no |

Field settings (only those valid for the type are accepted): label, key, required, placeholder, help
text, default value, width, CSS class, min/max (numbers or date/time bounds), step, max length, pattern
(+ custom error message), rows, options (label + stored value), option layout, checkbox text, read-only,
disabled, "show in submissions list", content (heading/paragraph/section/HTML), heading size, alignment.

- **Read-only**: visitors see the default value; the server always stores the default, whatever is posted.
- **Disabled**: shown greyed out; never submitted or stored.
- **Hidden field**: stores its configured value; a posted value is ignored.
- **Section**: a titled divider whose conditional visibility applies to every field after it, up to the
  next section.

## Layout system

Each field has a width of 100 / 75 / 66 / 50 / 33 / 25 %. The renderer uses a 12-column CSS grid
(`.ff-grid`, widths map to `span 12/9/8/6/4/3`), so fields sit side by side until a row is full. Below
640 px every field spans the full width. There is no free-form grid editor: widths plus order are reliable
and cover side-by-side layouts.

## Style system

Form-level design (`forms.style_settings`) is grouped into **Form container** (max width, alignment,
background, text color, font, padding, field spacing, border width/color, corner radius, shadow),
**Labels** (color, size, weight, spacing), **Inputs** (text/background/border/focus colors, border
width, radius, padding, font size), **Submit button** (text, background, hover, text color, radius,
size, padding, width, alignment) and **Errors & messages** (error text/border, success
background/text/border).

Every property is one of three kinds, defined in `FormStyleSchema::definition()`:

- **color**: `#rrggbb` only (what `<input type="color">` produces); validated with a regex.
- **px**: an integer within the property's min..max, emitted as `{n}px`.
- **enum**: a key from a fixed map. The CSS value emitted is the server-side map value, never the submitted string.

Each property becomes a CSS custom property (e.g. `--ff-label-color`) on `#ff-form-{id}`, and
`resources/dist/css/forms.css` uses those variables. The same stylesheet renders the builder canvas and the
public page, so the preview is exact.

Per-field overrides (`form_fields.style_settings`, `FieldStyleSchema`) re-declare the same
variables on that field's wrapper (`style="--ff-label-color:#…"`), so they cascade to that field only.
They are optional: empty means "inherit the form design".

Values are validated on save **and** re-normalized at render time, so an invalid value that reached the
database by some other route still never reaches CSS.

## Custom CSS handling

Custom-code users only (`canManageCustomCode`). Stored exactly as typed, and transformed at **render time** by `Support\CssScoper` (so
improving the scoper re-protects every existing form without a data migration):

- every selector gets `#ff-form-{id}` prepended; `html` / `body` / `:root` at the start of a selector are
  replaced *by* the wrapper (`body { … }` styles the form box, not the page);
- `@media` / `@supports` / `@container` are kept and their rules scoped recursively; `@keyframes` kept;
- every other at-rule (`@import`, `@font-face`, `@page`, `@layer`, …) is dropped;
- nested rule blocks are dropped (declarations only), so CSS nesting can't escape the scope;
- `<` is escaped (no `</style>` break-out) and `expression(`, `javascript:`, `behavior:`, `-moz-binding`
  are neutralized;
- max 20 000 characters.

## Custom HTML sanitization

HTML-block fields (any admin) and custom HTML before/after the form (custom-code users) go through
`Support\HtmlSanitizer`, a wrapper around **symfony/html-sanitizer** (a DOM-parsing allowlist sanitizer). It is applied on save **and** on every render.

- Allowed: headings h2–h6, `p br hr div span strong b em i u s small mark sub sup abbr blockquote q code
  pre ul ol li dl dt dd a img figure figcaption table caption thead tbody tfoot tr th td`, with `class`,
  `title`, `lang`, `dir` plus a few element-specific attributes (`href`, `target`, `src`, `alt`,
  `width`, `height`, `colspan`, …).
- Removed with their content: `script`, `style`, `iframe`, `object`, `embed`, form controls, `svg`,
  `math`, `template`, media elements, …
- Unknown tags are unwrapped (their text is kept).
- Never allowed: `on*` event handlers, the `style` attribute, and URL schemes other than
  http/https/mailto/tel, so `javascript:` and `data:` never survive. Every link gets
  `rel="noopener noreferrer nofollow"`.

## Custom code security

| Item | Who | Behavior |
|---|---|---|
| Custom CSS | custom-code users | stored; rendered **scoped** (above) |
| Custom HTML before/after | custom-code users | stored **sanitized**; re-sanitized on render |
| Custom JavaScript | custom-code users | **stored only, never output or executed** anywhere (not on the public page, not in the builder) |
| HTML-block field | any form editor | sanitized like custom HTML |

The four custom-code keys are `prohibited` in the save request for anyone without custom-code access (422 if
present), and the builder never sends the raw code to other users. They see a lock notice, but the
preview still applies the already-scoped/sanitized result. Enabling custom JavaScript requires a
separate, security-reviewed phase (sandboxing, CSP, review workflow); until then the field exists
only so that work isn't blocked by schema changes.

## Conditional logic

Stored on a field as:

```json
{"action": "show", "match": "all",
 "conditions": [{"field": "department", "operator": "equals", "value": "other"}]}
```

- `action`: `show` | `hide`; `match`: `all` | `any`; up to 10 conditions.
- Operators: `equals`, `not_equals`, `contains`, `is_empty`, `is_not_empty`. Comparison is trimmed and
  case-insensitive; for a checkbox group, `equals`/`contains` mean "one of the ticked options is".
  A single checkbox is `"1"` when ticked.
- A condition may only reference an **active, visitor-filled field placed above** the field (validated
  on save). This makes cycles impossible and evaluation a single top-to-bottom pass.
- A hidden field's value counts as empty for later conditions.
- A section's visibility applies to every field up to the next section.

The same algorithm exists twice and must stay identical:
`TMAn1An\FormBuilder\Services\Forms\FormVisibilityResolver` (server, authoritative) and `resources/dist/js/form-logic.js`
(browser: public page + builder preview). On the public page hidden fields are hidden *and* their
inputs disabled; the server re-evaluates every submission regardless.

## Public form route

`GET|POST /forms/{slug}` (`PublicFormController`; POST throttled per IP via
`public.submit_throttle`). The page renders inside the host's public layout. A form is available
only when `FormAvailability` says so:

- status must be **active** (draft, deactivated and archived forms return 404);
- `visibility = private` forms require a signed-in user with builder access (404 otherwise);
- `opens_at` / `closes_at`, `submission_limit` and "allow multiple submissions" show a friendly
  message instead of the form.

After a successful submission the visitor sees the success message, or is redirected to the
configured `redirect_url` (http/https only, validated on save). Forms embedded in pages post to
the same endpoint; see [PAGES.md](PAGES.md) §Form embedding.

## Server-side validation

`FormSubmissionService` rebuilds the rules from the **stored** field definitions on every request:

1. Any posted key that isn't an active value-collecting field is rejected (`form` error).
2. Visibility is resolved from the posted values. Hidden-by-condition fields are not required, not
   validated and not stored, whatever the browser sent.
3. Per-type rules: `email:rfc`; `numeric` + min/max; `date_format` (+ min/max bounds) for date, time and
   datetime; a phone character whitelist; `max` length (default 1 000 / 10 000); optional anchored
   `regex` pattern; dropdown/radio `Rule::in(option values)`; checkbox group `array` + each item
   `in` + `distinct`; required single checkbox `accepted`.
4. Hidden and read-only fields take their value from the definition; disabled fields are skipped.
5. Inside a transaction with the form row locked, availability (limit, schedule) is re-checked, then
   the submission and its values are written.

## Submission architecture

`form_submissions` + `form_submission_values` (one row per visible value-collecting field, empty answers
stored as null). Metadata: IP address and user agent (255 chars), and `form_version`.

Admin pages:
- **Forms → Submissions**: every form with its submission count and latest submission date.
- **Per-form list**: ID, submitted at, and up to three summary fields (those ticked "Show in submissions
  list", else the first three). Wide forms are never forced into one giant table.
- **Detail page**: every stored value with its snapshot label and key, plus a note when the field has
  since been renamed, archived or removed. Route-model binding is `scopeBindings()`: a submission id
  from another form returns 404.

There is no edit or delete action for submissions.

## Historical snapshot strategy

A submission must stay understandable whatever happens to the form later. At submission time each value
row stores:

- `field_key`, `field_label_snapshot`, `field_type_snapshot`;
- `value` (raw: option *values*, a list for checkbox groups, a bool for single checkboxes);
- `display_value`: what a human should read (option **labels**, `Robotics, AI`, `Yes`/`No`).

Live-definition rules that protect this:
- a field's **key** can't change once it has submissions;
- a field with submissions is **archived**, never deleted, and its key stays reserved (a new field can't
  reuse it);
- option label/value edits never touch stored rows, because `display_value` already holds the label as it was.

## Excel export

`GET /admin/forms/{form}/submissions/export.xlsx` (`FormSubmissionExportService`, PhpSpreadsheet):

- one row per submission (`Submission ID`, `Submitted At`, `Submitted By`, then fields);
- one column per value-collecting field of the form, active **and** archived (`Nickname (archived)`),
  in builder order, headed by the field's current label. Duplicate labels get `[key]` appended;
- values are mapped to columns by `form_field_id`, so a renamed field keeps its column and old values.
  Values whose field row no longer exists fall back to a column keyed by `field_key`
  (`… (removed)`);
- cells hold `display_value`, are written as explicit strings (leading zeros survive), and pass
  through `ExcelFormulaGuard` (values starting with `= + - @` get a leading `'`), headings included;
- submissions are read with `chunkById(500)`, not all at once. The workbook itself is held in memory by
  PhpSpreadsheet, which is fine for thousands of rows. See Known limitations for very large exports.

## Authorization

`TMAn1An\FormBuilder\Policies\FormPolicy`, enforced in controllers and Form Requests (never just by
hiding buttons). Role decisions are delegated to the host's `Authorizer` (see
[INTEGRATION.md](INTEGRATION.md) §2):

| Ability | Requires |
|---|---|
| list / view / preview / create / edit / publish / deactivate / duplicate | `canAccess` (edits also need the form not to be archived) |
| view & export submissions, view uploaded images | `canAccess` |
| archive / restore | `canArchive` |
| custom code (CSS, HTML before/after, stored-only JS) | `canManageCustomCode` |
| delete a form or a submission | impossible: no route exists |

Field ids in a save payload must belong to the form being saved
(`FormDefinitionValidator::after()`), submission and file routes use `scopeBindings()`, and every
write goes through whitelisting normalizers. No model is ever filled from raw request input.

## Audit integration

Every significant action is reported to the host's `AuditLogger` (see
[INTEGRATION.md](INTEGRATION.md) §3): `form_created`, `form_updated` (metadata: added / deleted /
archived field keys and field count; autosaves are not reported individually), `form_published`,
`form_deactivated`, `form_archived`, `form_restored`, `form_duplicated` (metadata: source form
id). The package default records nothing.

## Save reliability

- Every save is one transaction with the form row locked (`lockForUpdate`). Fields are deleted/archived,
  renamed keys are parked on a temporary value (so swapping two keys can't trip the unique index), fields
  are upserted in order, and the form row is updated last. A failure rolls back everything.
- **Optimistic locking**: the builder sends the `lock_version` it loaded. A stale version gets 409, so
  two admins (or two tabs) can't silently overwrite each other.
- Order is taken from the payload's numeric indexes (fields, options and conditions). Laravel's
  `validated()` rebuilds arrays in rule order, so the normalizers `ksort` by index. A regression test
  covers this.
- Reloading the builder shows exactly what was stored: fields, order, widths, options, design, colors,
  conditions, custom CSS and HTML blocks.

## Extending: adding a field type

1. Add a case to `FormFieldType` (label, group, `settingKeys()`, and if relevant `htmlInputType()` /
   `dateFormat()`).
2. Add validation for any new setting key in `FieldSettingsSchema`.
3. Render it in `resources/views/forms/_renderer.blade.php` and in `renderFieldBody()` in
   `resources/dist/js/form-builder.js` (same classes).
4. Add its submission rules in `FormSubmissionService::rulesFor()` and, if needed, its display format
   in `displayValue()`.
5. Tests.

File upload, address, rating, signature and repeater fit this shape. File upload additionally needs
private storage plus a download route, and a repeater needs a nested value shape. Neither is built.

## Known limitations

- **Custom JavaScript is stored but never executed** (by design for this phase).
- "Allow multiple submissions = off" is best-effort for anonymous visitors (per browser session); it is
  per account for signed-in users. It is not an identity check.
- No CAPTCHA or honeypot yet; spam protection is the per-IP throttle only.
- No full revision history of form definitions. Submissions record `form_version`, and the audit trail
  records each save, but old definitions aren't stored.
- No file upload, payment, signature, multi-page wizard, calculations, webhooks or external
  integrations.
- Option *value* edits are not propagated into conditions that compare against the old value; the
  builder shows the condition's value select, so re-pick it after renaming a value.
- Very large exports (tens of thousands of rows) are limited by PhpSpreadsheet holding the workbook in
  memory. Switch to a streaming writer if that ever becomes real.
- Changing the slug of a published form breaks links already shared (the builder warns).

## Submit button styling

The submit button's style is `TMAn1An\FormBuilder\Styling\ButtonStyleSchema`, shared with the
page Button block. It renders through one `.ff-button` CSS rule driven by `--ff-btn-*` custom
properties, so the builder canvas, the admin preview, the public form and page buttons all look
the same.

| Setting | Values |
|---|---|
| Text | Settings → "Button text" (`settings.submit_label`) |
| Font size / weight | 10–40 px / 400–800 |
| Text, background, hover background, border colour | `#rrggbb` |
| Border width / corner radius | 0–10 px / 0–60 px |
| Width | **auto** (fit content), **full** (100%), **custom** |
| Custom width | `{n}px` (≤ 2000), `{n}%` (≤ 100), `{n}rem` (≤ 120), e.g. `220px`, `50%`, `12rem` |
| Min width / min height | same length format (`0px` = none) |
| Vertical / horizontal padding | 0–60 px / 0–120 px |
| Alignment | left / center / right |

Sizing changes the real box (`width`, `min-width`, `min-height`, `padding`), not just the text.
A custom width is emitted as `min(<value>, 100%)` and the button has `max-width: 100%`, so it can
never overflow on a phone. Lengths are validated against a strict pattern and per-unit maximums,
so `calc(...)`, `vw`, negative values or injected CSS are rejected (422). Older forms saved before
custom sizing existed keep rendering unchanged (missing keys fall back to defaults).

## Image upload fields

Field type `image` (single image).

| Setting | Notes |
|---|---|
| Label, key, required, help text, width | as other inputs |
| Maximum file size | KB, default `uploads.default_field_max_kb`, capped by `uploads.max_kb` |
| Allowed types | any of JPG, PNG, WebP (default: all). SVG is never accepted |

Server-side validation never trusts the extension or the browser's MIME claim: `mimetypes` checks
the **detected** content type, `max` the size, and an image decode (`getimagesize`) must succeed.
Renamed scripts/HTML, SVG, executables and oversized files are rejected.

Storage: `storage.submissions_disk` (private by default) at
`form-builder/submissions/{form}/{submission}/{uuid}.{ext}`. The extension comes from the detected
type, the name is random, and the client's filename is kept only as a cleaned display name, so
there is no path traversal and no collisions. `form_submission_files` stores disk, path, original
name, MIME type, size and dimensions. The value row stores `{"file_id": …}` (no binary data in the
database). If saving the submission fails after a file was written, the file is deleted.

Display: the submission page shows a thumbnail served by the admin-only route
`…/forms/{form}/submissions/{submission}/files/{file}` (bound with `scopeBindings`, `nosniff`,
stored MIME type). Excel export writes that authenticated URL into the cell, never the image or
its storage path.

## Pictures on the form: banner and Image block

Two ways to **show** a picture on a form (as opposed to the `image` field, where visitors upload
one). Both use the shared media library, the `page_media` table that page Image blocks use, so an
image uploaded in either builder can be chosen in both. No database migration was needed.

**Banner** (builder → Settings tab → *Banner image*): one image shown as **its own section, outside
the form box**, either **above** or **below** the form (`banner_position`: `top` | `bottom`), and
either **as wide as the form** (lined up with it, same max width and alignment) or the **full page
width** (`banner_width`: `form` | `full`). Also stored in `forms.settings`: `banner_media_id`,
`banner_alt` and `banner_height` (optional maximum height, 60–600 px: the image keeps its own
shape until it would be taller than that, then `object-fit: cover` crops it, so phones show the
whole banner, smaller). Markup: `resources/views/forms/_banner.blade.php`, a
`<figure class="ff-banner-section" id="ff-form-{id}-banner">` placed before or after the
`.ff-form` card; the form's design variables are emitted for that id too. It shows wherever the
form renders: the public page, the admin preview, and forms embedded in pages.

**Image block** (palette → Layout & content → *Image*, type `image_block`): a picture anywhere
between fields. Settings: image (`media_id`, required), alt text, maximum width (px, ≤ 2000, never
wider than the screen), alignment, rounded corners (0–200 px), optional link (`https://`,
`http://`, `mailto:`, `tel:`, `/` or `#` only) and "open in a new tab", plus the usual width, CSS
class, style overrides and show/hide conditions. It collects nothing: no submission value, no
Excel column, and a value posted under its key is refused like any unknown field. The picture is
optional when saving (so adding the block never blocks saving or autosave); a block without a
picture is simply not shown to visitors.

**Uploading** (`POST {admin}/forms/{form}/media`, `PageMediaController::storeForForm`): needs
permission to edit that form (`FormPolicy::update`, so not on archived forms). The page builder's
`POST {admin}/pages/media` uses the same code. JPG/PNG/WebP only, checked by detected MIME type
plus an image decode; the size limit is `FieldSettingsSchema::mediaUploadMaxKb()`, i.e.
`uploads.max_kb` lowered to PHP's `upload_max_filesize` / `post_max_size` when those are smaller,
and the builder states that limit and checks it before uploading. Stored publicly on
`storage.media_disk` under a random name, and served by its uuid URL.

**Removing and deleting.** *Remove banner* / *Remove picture from this block* (right under the
preview) only takes the picture off the form; the image stays in the library. **Manage uploaded
images** (under the picker) deletes an image for good: `DELETE {admin}/media/{uuid}`
(`PageMediaController::destroy`, same permission as uploading). The server refuses with 409 and
says where while any form banner, form Image block (archived ones included) or page Image block
uses it (`Services\Media\MediaLibrary::usages()`); images the open form uses aren't offered for
deletion at all. Deleting removes the row and the file. Deletions aren't written to the host's
audit log (the `AuditLogger` contract only covers forms and pages).

Safety: alt text is escaped; the style values (height, max width, radius, alignment) are validated
integers/enums rebuilt in `FormPresenter`; the link is re-checked with
`PageBlockSettingsSchema::isSafeUrl()` before it is printed. Media is never deleted by removing a
banner or block, or by archiving a form. If an image row disappears anyway, the banner or block is
simply left out. Media is public content: don't upload anything that must stay private.

## Step-by-step forms

Per form, off by default: builder → Settings → *Layout* → **Show one section at a time (step by
step)**, stored as `forms.settings.multi_step`. The same switch also appears in every Section
field's panel (*Step by step*), with a note saying whether it is on and which step that section
is.

- **Steps come from Section fields.** Each Section starts a new step; fields before the first
  Section are step 1. With no Sections the form shows as one page. The builder marks where each
  step starts.
- **Visitors** see "Step 2 of 4 · <section title>" with a progress bar, **Back** and **Next**,
  and the submit button only on the last step. Next checks the current step's answers with the
  browser's own validation (required, email, number ranges, patterns…). Pressing Enter on an
  earlier step acts as Next. A step whose fields are all hidden by conditions is skipped, and the
  step count follows the conditions as answers change.
- **Submitting** still sends the whole form in one request, validated by the server exactly as
  before. Before submitting, every step is checked; the visitor is taken to the first problem. If
  the server rejects the submission, the page opens on the step holding the first error, with the
  earlier answers kept.
- **Where it runs:** `resources/dist/js/form-runtime.js` (`setupSteps()`), triggered by
  `data-ff-steps` on the `<form>`. Steps are only hidden with a CSS class (`.ff-step-hidden`),
  never disabled, so every answer is submitted. Without JavaScript the whole form shows at once.
  It also applies to forms embedded in pages. In the admin preview (`data-ff-preview`) Next
  doesn't insist on answers, so every step can be looked at.
- Button texts are fixed ("Back", "Next"); the submit button keeps its configured text and style.
  "Back" uses the same button style, outlined.

## Public URLs, preview, View live, copy link

| Action | Where | Behaviour |
|---|---|---|
| Edit | list, builder | builder (read-only when archived) |
| Preview | list, builder | admin-only render of any form including drafts. Submissions are disabled |
| View live | list, builder | shown only for **published, public** forms. Opens `/forms/{slug}` |
| Copy public link | list, builder | copies `/forms/{slug}` to the clipboard (with a fallback for browsers without the clipboard API) |
| Submissions / Export Excel | list, builder | per-form submission list and `.xlsx` export |

Draft, deactivated and archived forms are never publicly accessible (404), and private forms 404
for visitors without builder access.
