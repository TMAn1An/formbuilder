<x-formbuilder::admin :title="'Submissions — '.$form->name" wide>
  <div class="fba-toolbar">
    <a href="{{ $fb->admin('forms.index') }}">&larr; All forms</a>
    <div class="fba-actions">
      <a href="{{ $fb->admin('forms.edit', $form) }}" class="fba-btn fba-btn--ghost fba-btn--sm">Open builder</a>
      <a href="{{ $fb->admin('forms.submissions.export', $form) }}" class="fba-btn fba-btn--primary fba-btn--sm">Export Excel</a>
    </div>
  </div>

  <p class="fba-muted" style="margin-top:0">
    {{ $submissions->total() }} {{ \Illuminate\Support\Str::plural('submission', $submissions->total()) }}.
    The table shows a few summary fields (tick &ldquo;Show in submissions list&rdquo; on a field in the builder to choose them); open a submission for every value.
  </p>

  <div class="fba-card fba-card--flush">
    <table class="fba-table">
      <thead>
        <tr>
          <th>ID</th><th>Submitted at</th>
          @foreach ($summaryFields as $field)<th>{{ $field->label }}</th>@endforeach
          <th></th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $row)
          <tr>
            <td>#{{ $row['submission']->id }}</td>
            <td style="white-space:nowrap">{{ $row['submission']->submitted_at->format('j M Y, g:i A') }}</td>
            @foreach ($row['cells'] as $cell)
              <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis">{{ \Illuminate\Support\Str::limit((string) $cell, 80) ?: '—' }}</td>
            @endforeach
            <td style="text-align:right"><a href="{{ $fb->admin('forms.submissions.show', [$form, $row['submission']]) }}" class="fba-btn fba-btn--ghost fba-btn--sm">View</a></td>
          </tr>
        @empty
          <tr><td colspan="{{ 3 + $summaryFields->count() }}" class="fba-empty">No submissions yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="fba-pagination">{{ $submissions->links() }}</div>
</x-formbuilder::admin>
