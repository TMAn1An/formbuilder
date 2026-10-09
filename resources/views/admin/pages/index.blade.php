@php
  use TMAn1An\FormBuilder\Enums\PageStatus;
  $badge = fn (PageStatus $s) => match ($s) {
      PageStatus::Published => 'fba-badge--active',
      PageStatus::Draft => 'fba-badge--draft',
      PageStatus::Deactivated => 'fba-badge--inactive',
      PageStatus::Archived => 'fba-badge--archived',
  };
@endphp
<x-formbuilder::admin title="Pages" wide>
  <div class="fba-toolbar">
    <p class="fba-muted" style="margin:0">
      Simple block-based pages. Published pages are public at their link below.
      @if ($showArchived)
        <a href="{{ $fb->admin('pages.index') }}">Hide archived</a>
      @else
        <a href="{{ $fb->admin('pages.index', ['archived' => 1]) }}">Show archived</a>
      @endif
    </p>
    @can('create', \TMAn1An\FormBuilder\Models\Page::class)
      <a href="{{ $fb->admin('pages.create') }}" class="fba-btn fba-btn--primary">Create page</a>
    @endcan
  </div>

  <div class="fba-card fba-card--flush">
    <table class="fba-table">
      <thead><tr><th>Page</th><th>Status</th><th>Blocks</th><th>Updated</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        @forelse ($pages as $page)
          @php $publicUrl = $fb->public('pages.show', ['page' => $page->slug]); @endphp
          <tr>
            <td><strong>{{ $page->title }}</strong><div class="fba-link-row"><code>{{ $publicUrl }}</code></div></td>
            <td><span class="fba-badge {{ $badge($page->status) }}">{{ $page->status->label() }}</span></td>
            <td>{{ $page->blocks_count }}</td>
            <td style="white-space:nowrap">{{ $page->updated_at->format('j M Y, g:i A') }}</td>
            <td>
              <div class="fba-actions">
                <a href="{{ $fb->admin('pages.edit', $page) }}" class="fba-btn fba-btn--sm {{ $page->status->isEditable() ? 'fba-btn--primary' : 'fba-btn--ghost' }}">{{ $page->status->isEditable() ? 'Edit' : 'View' }}</a>
                <a href="{{ $fb->admin('pages.preview', $page) }}" class="fba-btn fba-btn--ghost fba-btn--sm" target="_blank" rel="noopener">Preview</a>
                @if ($page->status === PageStatus::Published)
                  <a href="{{ $publicUrl }}" class="fba-btn fba-btn--ghost fba-btn--sm" target="_blank" rel="noopener">View live</a>
                @endif
                <button type="button" class="fba-btn fba-btn--ghost fba-btn--sm" data-fba-copy="{{ $publicUrl }}">Copy link</button>
                @can('duplicate', $page)
                  <form method="POST" action="{{ $fb->admin('pages.duplicate', $page) }}">@csrf<button type="submit" class="fba-btn fba-btn--ghost fba-btn--sm">Duplicate</button></form>
                @endcan
                @if ($page->status === PageStatus::Published)
                  @can('deactivate', $page)
                    <form method="POST" action="{{ $fb->admin('pages.deactivate', $page) }}" onsubmit="return confirm('Deactivate this page? It stops being public immediately.')">@csrf<button type="submit" class="fba-btn fba-btn--danger fba-btn--sm">Deactivate</button></form>
                  @endcan
                @elseif ($page->status->isEditable())
                  @can('publish', $page)
                    <form method="POST" action="{{ $fb->admin('pages.publish', $page) }}">@csrf<button type="submit" class="fba-btn fba-btn--ghost fba-btn--sm">Publish</button></form>
                  @endcan
                @endif
                @can('archive', $page)
                  <form method="POST" action="{{ $fb->admin('pages.archive', $page) }}" onsubmit="return confirm('Archive this page? It becomes read-only and is no longer public.')">@csrf<button type="submit" class="fba-btn fba-btn--danger fba-btn--sm">Archive</button></form>
                @endcan
                @can('restore', $page)
                  <form method="POST" action="{{ $fb->admin('pages.restore', $page) }}">@csrf<button type="submit" class="fba-btn fba-btn--ghost fba-btn--sm">Restore</button></form>
                @endcan
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="fba-empty">No pages yet. <a href="{{ $fb->admin('pages.create') }}">Create the first one</a>.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="fba-pagination">{{ $pages->links() }}</div>
</x-formbuilder::admin>
