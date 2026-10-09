@php
  use TMAn1An\FormBuilder\Enums\FormStatus;
  $badge = fn (FormStatus $s) => match ($s) {
      FormStatus::Active => 'fba-badge--active',
      FormStatus::Draft => 'fba-badge--draft',
      FormStatus::Inactive => 'fba-badge--inactive',
      FormStatus::Archived => 'fba-badge--archived',
  };
@endphp
<x-formbuilder::admin title="Forms" wide>
  <div class="fba-toolbar">
    <p class="fba-muted" style="margin:0">
      Published forms are public at their link below.
      @if ($showArchived)
        <a href="{{ $fb->admin('forms.index') }}">Hide archived</a>
      @else
        <a href="{{ $fb->admin('forms.index', ['archived' => 1]) }}">Show archived</a>
      @endif
    </p>
    @can('create', \TMAn1An\FormBuilder\Models\Form::class)
      <a href="{{ $fb->admin('forms.create') }}" class="fba-btn fba-btn--primary">Create form</a>
    @endcan
  </div>

  <div class="fba-card fba-card--flush">
    <table class="fba-table">
      <thead>
        <tr><th>Form</th><th>Status</th><th>Submissions</th><th>Updated</th><th style="text-align:right">Actions</th></tr>
      </thead>
      <tbody>
        @forelse ($forms as $form)
          @php
            $publicUrl = $fb->public('forms.show', ['form' => $form->slug]);
            $isLive = $form->status === FormStatus::Active && $form->setting('visibility', 'public') === 'public';
          @endphp
          <tr>
            <td>
              <strong>{{ $form->name }}</strong>
              <div class="fba-link-row">
                <code>{{ $publicUrl }}</code>
              </div>
            </td>
            <td><span class="fba-badge {{ $badge($form->status) }}">{{ $form->status->label() }}</span>
              @if ($form->setting('visibility') === 'private')<br><span class="fba-help">Private</span>@endif
            </td>
            <td>{{ $form->submissions_count }}</td>
            <td style="white-space:nowrap">{{ $form->updated_at->format('j M Y, g:i A') }}</td>
            <td>
              <div class="fba-actions">
                <a href="{{ $fb->admin('forms.edit', $form) }}" class="fba-btn fba-btn--sm {{ $form->status->isEditable() ? 'fba-btn--primary' : 'fba-btn--ghost' }}">{{ $form->status->isEditable() ? 'Edit' : 'View' }}</a>
                <a href="{{ $fb->admin('forms.preview', $form) }}" class="fba-btn fba-btn--ghost fba-btn--sm" target="_blank" rel="noopener">Preview</a>
                @if ($isLive)
                  <a href="{{ $publicUrl }}" class="fba-btn fba-btn--ghost fba-btn--sm" target="_blank" rel="noopener">View live</a>
                @endif
                <button type="button" class="fba-btn fba-btn--ghost fba-btn--sm" data-fba-copy="{{ $publicUrl }}" title="{{ $isLive ? 'Copy the public link' : 'Copy the link (works once the form is published)' }}">Copy public link</button>
                <a href="{{ $fb->admin('forms.submissions.index', $form) }}" class="fba-btn fba-btn--ghost fba-btn--sm">Submissions</a>
                <a href="{{ $fb->admin('forms.submissions.export', $form) }}" class="fba-btn fba-btn--ghost fba-btn--sm">Export Excel</a>
                @can('duplicate', $form)
                  <form method="POST" action="{{ $fb->admin('forms.duplicate', $form) }}">@csrf<button type="submit" class="fba-btn fba-btn--ghost fba-btn--sm">Duplicate</button></form>
                @endcan
                @if ($form->status === FormStatus::Active)
                  @can('deactivate', $form)
                    <form method="POST" action="{{ $fb->admin('forms.deactivate', $form) }}" onsubmit="return confirm('Deactivate this form? It stops accepting submissions immediately.')">@csrf<button type="submit" class="fba-btn fba-btn--danger fba-btn--sm">Deactivate</button></form>
                  @endcan
                @elseif ($form->status->isEditable())
                  @can('publish', $form)
                    <form method="POST" action="{{ $fb->admin('forms.publish', $form) }}">@csrf<button type="submit" class="fba-btn fba-btn--ghost fba-btn--sm">Publish</button></form>
                  @endcan
                @endif
                @can('archive', $form)
                  <form method="POST" action="{{ $fb->admin('forms.archive', $form) }}" onsubmit="return confirm('Archive this form? It becomes read-only and stops accepting submissions. Submissions are kept.')">@csrf<button type="submit" class="fba-btn fba-btn--danger fba-btn--sm">Archive</button></form>
                @endcan
                @can('restore', $form)
                  <form method="POST" action="{{ $fb->admin('forms.restore', $form) }}">@csrf<button type="submit" class="fba-btn fba-btn--ghost fba-btn--sm">Restore</button></form>
                @endcan
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="fba-empty">No forms yet. <a href="{{ $fb->admin('forms.create') }}">Create the first one</a>.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="fba-pagination">{{ $forms->links() }}</div>
</x-formbuilder::admin>
