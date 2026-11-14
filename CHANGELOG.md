# Changelog

## Unreleased — 2026-11-14

### Form Builder
- **Banner is now its own section outside the form box**, placed above or below the form
  (`banner_position`), as wide as the form or the full page width (`banner_width`). Existing
  banners move from inside the card to a section directly above it.
- **Fixed:** adding an Image block made every save and autosave fail ("The
  fields.0.settings.media id field is required") until a picture was chosen. The picture is now
  optional; a block without one isn't shown to visitors.
- **Fixed:** the builder's error box showed a stray "null"; field flags ("Needs fixing",
  "Conditional") no longer cover the form title.
- **Step-by-step forms** (Settings → Layout → "Show one section at a time"): every Section field
  starts a step; visitors use Back / Next with a "Step 2 of 4" progress bar, and submit on the
  last step. Next checks the current step's answers, steps hidden by conditions are skipped, and
  after a server-side error the form opens on the step with the error. The whole form is still
  submitted and validated in one request; without JavaScript it shows on one page. Stored as
  `forms.settings.multi_step` (off by default). See docs/FORMS.md §Step-by-step forms.
- **Banner image** (Design tab): one picture across the top of a form, above the title, with alt
  text and an optional maximum height (wide screens crop it; phones show the whole image). Stored in
  `forms.settings` (`banner_media_id`, `banner_alt`, `banner_height`).
- **Image block** (palette → Layout & content → Image, type `image_block`): a picture anywhere
  in the form, with alt text, maximum width, alignment, rounded corners, optional link and
  show/hide conditions. Collects nothing.
- Both use the page builder's media library (`page_media`); no migration. New upload route
  `POST {admin}/forms/{form}/media` (needs permission to edit that form).
- Media uploads (forms and pages) now use the size limit the server really accepts: the package
  limit lowered to PHP's `upload_max_filesize` / `post_max_size` when those are smaller. The
  builders state that limit, and the form builder checks it before uploading.
  See docs/FORMS.md §Pictures on the form.

## 0.1.0 — 2026-10-10 (unreleased)

First release as a standalone package. The Form Builder previously lived inside the IEEE IUBAT
Student Branch website; it was extracted here unchanged in behaviour and extended.

### Form Builder
- Extracted: models, migrations (same filenames, so existing databases keep their data), services,
  policies, controllers, views, CSS/JS. Host coupling replaced by config, the `Authorizer` and
  `AuditLogger` contracts, and configurable layouts/route names.
- **Submit button sizing rebuilt** (`Styling\ButtonStyleSchema`): width auto / full / custom
  (validated `px`, `%`, `rem`, capped at 100% of the container), min width, min height,
  horizontal/vertical padding, font size/weight, text/background/hover/border colours, border
  width, radius, alignment. The real button box changes. The same schema and CSS drive the page
  Button block.
- **Image Upload field** (JPG/PNG/WebP, per-field size limit and allowed types). Validated by
  detected MIME type plus an image decode, stored privately under
  `form-builder/submissions/{form}/{submission}/`, with metadata in `form_submission_files`.
  Thumbnails show on the submission page through an admin-only route; Excel exports an
  authorized link.
- Admin list/builder: **View live** (published public forms only), **Copy public link**.
- Per-form validation error bags (`form_{id}`), so several forms on one page stay independent.

### Page Builder (new)
- `pages`, `page_blocks` (one level of nesting via `parent_id` + `slot`), `page_media`.
- Blocks: section, columns (2–4), heading, paragraph (sanitized rich text), image (upload,
  alt, width, max width, alignment, radius, link), button (shared button style), divider,
  spacer, form (reference to a form, same renderer), custom HTML (sanitized).
- Flat save payload validated in full before any write (`PageDefinitionValidator`): unique
  refs/ids, ids must belong to the page and still exist, no orphans, one nesting level, no nested
  containers, valid columns, per-type settings, embeddable forms only, custom CSS permission.
  Saves are transactional with optimistic locking.
- Public `/pages/{slug}`, admin preview, View live, Copy link, duplicate, deactivate, archive.

### Infrastructure
- Assets served by the package (`/formbuilder-assets/...`) with content-hash versions.
- Page images served via `/formbuilder-assets/media/{uuid}` (no `storage:link` required).
