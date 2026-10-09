@php $files = $submission->files->keyBy('id'); @endphp
<x-formbuilder::admin :title="'Submission #'.$submission->id.' — '.$form->name">
  <p><a href="{{ $fb->admin('forms.submissions.index', $form) }}">&larr; All submissions for {{ $form->name }}</a></p>

  <div class="fba-card">
    <dl class="fba-meta">
      <dt>Submitted at</dt><dd>{{ $submission->submitted_at->format('j M Y, g:i:s A') }}</dd>
      <dt>Submitted by</dt><dd>{{ $submission->submitter?->name ?? 'Public visitor' }}</dd>
      <dt>Form version</dt><dd>{{ $submission->form_version ?? '—' }}</dd>
      @if (! empty($submission->metadata['ip']))
        <dt>IP address</dt><dd>{{ $submission->metadata['ip'] }}</dd>
      @endif
    </dl>
  </div>

  <div class="fba-card fba-card--flush">
    <table class="fba-table">
      <thead><tr><th style="width:34%">Field (as submitted)</th><th>Value</th></tr></thead>
      <tbody>
        @forelse ($submission->values as $value)
          @php
            $current = $value->form_field_id ? $currentFields->get($value->form_field_id) : null;
            $file = is_array($value->value) && isset($value->value['file_id']) ? $files->get($value->value['file_id']) : null;
          @endphp
          <tr>
            <td>
              <strong>{{ $value->field_label_snapshot }}</strong>
              <br><code class="fba-muted" style="font-size:.75em">{{ $value->field_key }}</code>
              @if ($current && $current->label !== $value->field_label_snapshot)
                <br><span class="fba-help">Now labelled &ldquo;{{ $current->label }}&rdquo;</span>
              @endif
              @if (! $current)
                <br><span class="fba-badge fba-badge--archived">field removed</span>
              @elseif (! $current->is_active)
                <br><span class="fba-badge fba-badge--archived">field archived</span>
              @endif
            </td>
            <td style="white-space:pre-wrap;word-break:break-word">
              @if ($file)
                @php $fileUrl = $fb->admin('forms.submissions.file', [$form, $submission, $file]); @endphp
                <a href="{{ $fileUrl }}" target="_blank" rel="noopener"><img src="{{ $fileUrl }}" alt="{{ $file->original_name }}" class="fba-thumb" loading="lazy"></a>
                <span class="fba-help">{{ $file->original_name }} &middot; {{ number_format($file->size / 1024, 1) }} KB @if ($file->width) &middot; {{ $file->width }}&times;{{ $file->height }} @endif
                  &middot; <a href="{{ $fileUrl }}?download=1">Download</a></span>
              @else
                {{ $value->display_value ?? '—' }}
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="2" class="fba-empty">This submission has no stored values.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-formbuilder::admin>
