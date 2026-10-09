@php
  use TMAn1An\FormBuilder\Enums\PageStatus;
  $publicUrl = $fb->public('pages.show', ['page' => $page->slug]);
@endphp
<x-formbuilder::admin :title="'Page Builder — '.$page->title" wide>
  <x-slot:head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ $fb->asset('css/forms.css') }}">
    <link rel="stylesheet" href="{{ $fb->asset('css/pages.css') }}">
    <link rel="stylesheet" href="{{ $fb->asset('css/builder.css') }}">
  </x-slot:head>

  <div class="fb" id="page-builder">
    <div class="fb-toolbar">
      <div class="fb-toolbar__left">
        <a href="{{ $fb->admin('pages.index') }}">&larr; Pages</a>
        <span class="fba-badge" id="pb-status-badge"></span>
        <span class="fb-save-state" id="pb-save-state" role="status" aria-live="polite"></span>
      </div>
      <div class="fb-toolbar__right">
        <a href="{{ $fb->admin('pages.preview', $page) }}" class="fba-btn fba-btn--ghost fba-btn--sm" target="_blank" rel="noopener">Preview</a>
        @if ($page->status === PageStatus::Published)
          <a href="{{ $publicUrl }}" class="fba-btn fba-btn--ghost fba-btn--sm" target="_blank" rel="noopener">View live</a>
        @endif
        <button type="button" class="fba-btn fba-btn--ghost fba-btn--sm" data-fba-copy="{{ $publicUrl }}">Copy link</button>
        @can('duplicate', $page)
          <form method="POST" action="{{ $fb->admin('pages.duplicate', $page) }}" class="fb-inline-form">
            @csrf
            <button type="submit" class="fba-btn fba-btn--ghost fba-btn--sm" data-pb-guard>Duplicate</button>
          </form>
        @endcan
        @if ($page->status === PageStatus::Published)
          @can('deactivate', $page)
            <form method="POST" action="{{ $fb->admin('pages.deactivate', $page) }}" class="fb-inline-form" onsubmit="return confirm('Deactivate this page? It stops being public immediately.')">
              @csrf
              <button type="submit" class="fba-btn fba-btn--danger fba-btn--sm" data-pb-guard>Deactivate</button>
            </form>
          @endcan
        @endif
        <button type="button" class="fba-btn fba-btn--ghost fba-btn--sm" id="pb-save" hidden>Save draft</button>
        <button type="button" class="fba-btn fba-btn--primary fba-btn--sm" id="pb-publish" hidden>Publish</button>
      </div>
    </div>

    <div class="fb-errors" id="pb-errors" role="alert" hidden></div>

    <div class="fb-shell">
      <aside class="fb-palette" id="pb-palette" aria-label="Add a block"></aside>

      <section class="fb-stage" aria-label="Page preview">
        <div class="fb-stage__bar">
          <span>Click a block to edit it. Containers: select them (or a column) to add blocks inside.</span>
          <div class="fb-seg" role="group" aria-label="Preview width">
            <button type="button" class="is-active" data-pb-device="desktop">Desktop</button>
            <button type="button" data-pb-device="mobile">Mobile</button>
          </div>
        </div>
        <div class="fb-canvas pbb-canvas" id="pb-canvas"></div>
      </section>

      <aside class="fb-panel" aria-label="Settings">
        <div class="fb-tabs" role="tablist" id="pb-tabs"></div>
        <div class="fb-panel__body" id="pb-panel"></div>
      </aside>
    </div>
  </div>

  <script type="application/json" id="page-builder-data">@json($builder)</script>
  <script src="{{ $fb->asset('js/fb-kit.js') }}"></script>
  <script src="{{ $fb->asset('js/page-builder.js') }}"></script>
</x-formbuilder::admin>
