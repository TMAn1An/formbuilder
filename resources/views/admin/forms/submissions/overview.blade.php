<x-formbuilder::admin title="Form Submissions">
  <div class="fba-card fba-card--flush">
    <table class="fba-table">
      <thead><tr><th>Form</th><th>Status</th><th>Submissions</th><th>Latest submission</th><th></th></tr></thead>
      <tbody>
        @forelse ($forms as $form)
          <tr>
            <td><strong>{{ $form->name }}</strong></td>
            <td>{{ $form->status->label() }}</td>
            <td>{{ $form->submissions_count }}</td>
            <td>{{ $form->submissions_max_submitted_at ? \Illuminate\Support\Carbon::parse($form->submissions_max_submitted_at)->format('j M Y, g:i A') : '—' }}</td>
            <td><div class="fba-actions">
              <a href="{{ $fb->admin('forms.submissions.index', $form) }}" class="fba-btn fba-btn--ghost fba-btn--sm">View</a>
              <a href="{{ $fb->admin('forms.submissions.export', $form) }}" class="fba-btn fba-btn--ghost fba-btn--sm">Export Excel</a>
            </div></td>
          </tr>
        @empty
          <tr><td colspan="5" class="fba-empty">No forms yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="fba-pagination">{{ $forms->links() }}</div>
</x-formbuilder::admin>
