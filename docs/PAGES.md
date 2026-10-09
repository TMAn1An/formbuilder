# Page Builder

A deliberately simple, reliable block-based page builder. It is not Elementor or Gutenberg. Pages
are stored as **structured blocks**, never as one big HTML blob.

Admin: **Pages → All Pages / Create Page**. Public: `/pages/{slug}` (published pages only).

## Database schema

| Table | Purpose |
|---|---|
| `pages` | `title`, unique `slug`, `status` (`draft` / `published` / `deactivated` / `archived`), `settings` JSON (`meta_description`), `style_settings` JSON (`PageStyleSchema`), `custom_css` (scoped at render), `lock_version`, `published_at`, `created_by`, `updated_by` |
| `page_blocks` | `page_id`, `parent_id` (container, nullable), `slot` (column index), `type`, `sort_order`, `settings` JSON, **`form_id`** (FK → forms) and **`media_id`** (FK → page_media) as real columns |
| `page_media` | uploaded page images: random `uuid` (public URL key), `disk`, `path`, `original_name`, `mime_type`, `size`, `width`, `height`, `uploaded_by` |

No route deletes a page. Pages are archived. Removing a block in the builder deletes that block row
(blocks have no history to preserve).

## Blocks

| Block | Settings |
|---|---|
| Section *(container, 1 column)* | background colour, text colour, vertical/horizontal padding, corner radius |
| Columns *(container)* | 2, 3 or 4 columns, gap, vertical alignment. Stacks on phones |
| Heading | text, H1–H6, alignment, font size, weight, colour, space above/below |
| Paragraph | sanitized rich text (bold, italic, links, lists, paragraphs), alignment, size, colour |
| Image | uploaded image, alt text, width, max width (px/%/rem), alignment, corner radius, optional link (+ new tab) |
| Button | text, URL, new tab, plus the full **ButtonStyleSchema** (same as the form submit button: size, weight, colours, hover, border, radius, width auto/full/custom, min width/height, padding, alignment) |
| Divider | colour, thickness, width, vertical spacing |
| Spacer | height |
| Form | **reference** to a form (`form_id`), hide title, hide description |
| Custom HTML | sanitized HTML (same `HtmlSanitizer` as forms) |

Nesting is one level: a Section or Columns block holds non-container blocks. Containers can't be
nested.

Links (button URL, image link) accept `https://`, `http://`, `mailto:`, `tel:`, same-site paths
(`/events`) and anchors (`#register`) only.

## Page design

`PageStyleSchema` (`--pb-*` variables): max content width, page background, content background,
base text colour, content padding, space between blocks, content corner radius. This is
deliberately not a theme builder.

## Builder UI

Three columns: block palette, live canvas (same markup/classes as the public renderer), and a
settings panel with tabs *Block / Page design / Page settings / Custom CSS*.

- **Add**: click a palette item. It goes below the selected block. If a Section or a column of a
  Columns block is selected, it goes inside. Containers always go to the top level.
- **Reorder**: ↑/↓ on each block or Alt+↑/↓. Inside Columns, ←/→ moves a block between columns,
  and ⇱ moves it out of its container.
- **Duplicate / remove**: on the block bar and at the bottom of the Block tab. Duplicating a
  container copies its children.
- **Images**: upload in the Image block, or pick a previously uploaded one.
- **Form block**: pick a form. The canvas shows the real server-rendered form (non-submittable).
  Drafts are flagged. They only appear publicly once the form is published and public.
- Save draft / Publish / Save changes. Drafts autosave. Live pages never autosave. Optimistic
  locking prevents overwriting someone else's save (409).

## Save payload

`PUT {admin}/pages/{page}/builder`, JSON:

```json
{
  "version": 4, "intent": "save|publish", "autosave": false,
  "title": "...", "slug": "...", "settings": {"meta_description": "..."},
  "style_settings": {"page": {...}},
  "custom_css": "...",            // only for users with custom-code access
  "blocks": [
    {"ref": "b12", "id": 12, "parent": null, "order": 0, "type": "columns", "settings": {"count": 2}},
    {"ref": "n1", "id": null, "parent": "b12", "column": 1, "order": 1, "type": "heading", "settings": {"text": "Hi"}}
  ]
}
```

The list is **flat**. `ref` is a client reference (new blocks use temporary refs such as `n1`).
`id` is the persisted id, or null. `parent` is the ref of the container. `column` is the slot
inside it. `order` gives the position among siblings.

### Validation invariants (`PageDefinitionValidator`)

The **whole** definition is validated in the Form Request before the controller runs, so nothing
is written if anything is wrong:

1. Refs are unique, and persisted ids appear at most once. A block can't be in two places, and
   can't be both a top-level block and a child.
2. A persisted id must belong to **this** page (another page's id → explicit error) and still
   exist. A removed block can't be resurrected by a stale payload.
3. No orphans: `parent` must reference a block in the payload, and a block can't be its own
   parent.
4. Only containers have children, a child can't have children, and a container can't be placed
   inside a container. The nested `children` key is rejected outright.
5. `column` must exist in the parent (Section: 1, Columns: its 2–4).
6. `type` must be a `PageBlockType`, and settings are validated per type (unknown keys dropped,
   colours `#rrggbb`, sizes bounded, lengths px/%/rem, safe URLs).
7. A Form block must reference an existing, non-archived form.
8. `order` must be an integer 0–100000. Ordering is normalized (ties keep input order).
9. Custom HTML/Paragraph content is sanitized. Custom CSS is `prohibited` without custom-code
   access, however the request is crafted.

The write is one transaction with the page row locked: top-level blocks are upserted first, so
children can resolve their parent, then children, then removed blocks are deleted, then the page
row is updated. Any exception rolls everything back. This is covered by
`test_a_failure_mid_save_rolls_back_every_block_change`.

## Form embedding

A Form block stores only `form_id`. When the page renders, `PagePresenter` asks
`FormAvailability` whether the visitor may fill the form in, then renders it with the **same**
`FormPresenter` + `formbuilder::forms._renderer` used by `/forms/{slug}`. There is one renderer
and one validator, so an embedded form can't drift from the standalone one.

- The embedded form posts to the normal `/forms/{slug}` endpoint, with a hidden `_embed_page` id.
  The server honours it **only** if that page is published and really contains a Form block for
  this form. Otherwise it is ignored, so it can never become an open redirect.
- On success the visitor returns to the page (`#ff-form-{id}`) and sees the form's success message
  (or the form's redirect URL is used). The submission is stored under the **original form**, with
  `metadata.embedded_in_page`.
- Validation errors return to the page and use the form's own error bag (`form_{id}`), so two
  forms on one page never show each other's errors.
- Unpublished, deactivated, archived or private forms render nothing publicly. The admin preview
  shows a notice instead.

## Page images

Uploaded via `POST {admin}/pages/media` (needs `canAccess`): JPG/PNG/WebP only, checked by
detected MIME type plus an image decode, max `uploads.max_kb`. Stored on `storage.media_disk`
under `form-builder/pages/{yyyy}/{mm}/{uuid}.{ext}`. Image blocks reference the `page_media` row
by id. Public URLs use the random uuid (`/formbuilder-assets/media/{uuid}/…`), so numeric ids are
not addressable, and no `storage:link` is needed. Images uploaded for a draft page are reachable
by their (unguessable) URL, as is usual for web media.

## Publishing and preview

| Status | Public `/pages/{slug}` | Admin preview |
|---|---|---|
| Draft | 404 | ✓ |
| Published | ✓ | ✓ |
| Deactivated | 404 | ✓ |
| Archived | 404 | ✓ (read-only builder) |

Admin actions: Edit, Preview, View live (published only), Copy link, Duplicate (draft copy,
same block tree, same form/image references, no submissions), Publish/Deactivate, Archive/Restore
(`canArchive`).

## Security summary

- Output escaping everywhere, except HTML that passed `HtmlSanitizer` and CSS built from validated
  values or passed through `CssScoper` (custom CSS is scoped to `#pb-page-{id}`).
- Inline block styles are rebuilt at render time from re-checked values (colours re-matched,
  numbers clamped, enums re-checked). Stored strings are never echoed into CSS.
- IDOR: block ids are checked against the page, and form/media references are checked to exist.
  All admin routes are behind the host middleware plus `PagePolicy`.
- No arbitrary JavaScript anywhere in pages.
