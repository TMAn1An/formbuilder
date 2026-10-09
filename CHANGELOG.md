# Changelog

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
