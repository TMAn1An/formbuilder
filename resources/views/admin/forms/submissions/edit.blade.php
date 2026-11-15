{{--
  Admin correction of a stored submission (FormSubmissionController::edit /
  update). Only the fields FormSubmissionService::editableFields() returns
  are editable; image uploads, hidden/read-only and removed fields are
  listed read-only below. Every value is escaped.
--}}
@php
  use TMAn1An\FormBuilder\Enums\FormFieldType as T;
  $val = function (string $key) use ($current) {
      return old($key, $current[$key] ?? null);
  };
@endphp
<x-formbuilder::admin :title="'Edit submission #'.$submission->id.' — '.$form->name">
  <p><a href="{{ $fb->admin('forms.submissions.show', [$form, $submission]) }}">&larr; Back to submission #{{ $submission->id }}</a></p>

  @if ($errors->any())
    <div class="fba-alert fba-alert--error" role="alert">
      Please fix the highlighted answers.
      <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
  @endif

  <div class="fba-card" style="max-width:760px">
    <p class="fba-help" style="margin-top:0">
      Correct the answers below and save. Answers are checked with the form's rules (formats, options, &ldquo;unique&rdquo; fields).
      The change is recorded in the Logbook. Submitted {{ $submission->submitted_at->format('j M Y, g:i A') }}.
    </p>

    <form method="POST" action="{{ $fb->admin('forms.submissions.update', [$form, $submission]) }}">
      @csrf
      @method('PUT')

      @forelse ($fields as $field)
        @php
          $key = $field->key;
          $id = 'sub-'.$key;
          $value = $val($key);
          $error = $errors->first($key) ?: $errors->first($key.'.*');
        @endphp
        <div class="fba-field">
          @switch($field->type)
            @case(T::Checkbox)
              <label class="fba-inline-check"><input type="checkbox" name="{{ $key }}" value="1" @checked((bool) $value) style="width:auto"> {{ $field->label }}</label>
              @break

            @case(T::Radio)
            @case(T::Select)
              <label for="{{ $id }}">{{ $field->label }}</label>
              <select id="{{ $id }}" name="{{ $key }}">
                <option value="">— No answer —</option>
                @foreach ($field->options() as $option)
                  <option value="{{ $option['value'] }}" @selected((string) $value === (string) $option['value'])>{{ $option['label'] }}</option>
                @endforeach
              </select>
              @break

            @case(T::CheckboxGroup)
              <span class="fba-field__label" style="display:block;font-weight:600;font-size:.88rem;margin-bottom:6px">{{ $field->label }}</span>
              @php $chosen = array_map('strval', (array) ($value ?? [])); @endphp
              @foreach ($field->options() as $option)
                <label class="fba-inline-check" style="display:block;font-weight:400"><input type="checkbox" name="{{ $key }}[]" value="{{ $option['value'] }}" @checked(in_array((string) $option['value'], $chosen, true)) style="width:auto"> {{ $option['label'] }}</label>
              @endforeach
              @break

            @case(T::LongText)
              <label for="{{ $id }}">{{ $field->label }}</label>
              <textarea id="{{ $id }}" name="{{ $key }}" rows="{{ $field->setting('rows', 4) }}">{{ is_scalar($value) ? $value : '' }}</textarea>
              @break

            @default
              <label for="{{ $id }}">{{ $field->label }} @if ($field->setting('unique'))<span class="fba-badge">unique</span>@endif</label>
              <input id="{{ $id }}" type="{{ $field->type->htmlInputType() ?? 'text' }}" name="{{ $key }}" value="{{ is_scalar($value) ? $value : '' }}"
                @if ($field->type === T::Number) step="any" @endif>
          @endswitch
          @if ($error)<p class="fba-help" style="color:var(--fba-danger)">{{ $error }}</p>@endif
        </div>
      @empty
        <p class="fba-empty">This form has no answers that can be edited here.</p>
      @endforelse

      <div style="display:flex;gap:10px;margin-top:8px">
        <button type="submit" class="fba-btn fba-btn--primary">Save changes</button>
        <a href="{{ $fb->admin('forms.submissions.show', [$form, $submission]) }}" class="fba-btn fba-btn--ghost">Cancel</a>
      </div>
    </form>
  </div>

  @if ($readOnly->isNotEmpty())
    <div class="fba-card" style="max-width:760px">
      <h3 style="margin-top:0;font-size:1rem">Not editable here</h3>
      <p class="fba-help">Uploaded images, hidden or read-only values, and answers to fields removed from the form.</p>
      <ul>
        @foreach ($readOnly as $v)
          <li><strong>{{ $v->field_label_snapshot }}</strong>: {{ $v->display_value ?? '—' }}</li>
        @endforeach
      </ul>
    </div>
  @endif
</x-formbuilder::admin>
