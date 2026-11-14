{{--
  THE dynamic-form renderer -- used by /forms/{slug}, the admin preview,
  forms embedded in pages, and the page builder's form-block preview, so a
  form looks and validates the same everywhere.
  Expects:
    $presented     TMAn1An\FormBuilder\Services\Forms\FormPresenter::present() output
    $action        submit URL (null = preview: the form can't be submitted)
    $notice        optional availability message shown instead of the form
    $success       optional success message (form is replaced by it)
  Optional (page embeds):
    $embedPageId   page id posted back as `_embed_page` (verified server-side)
    $titleTag      'h1' (default) | 'h2'
    $hideTitle / $hideDescription
  Errors come from this form's own error bag ("form_{id}"), so several
  forms on one page never show each other's errors.
  Banner / Image-block pictures come from the media library (page_media);
  their style values are validated integers/enums built in FormPresenter.
  Everything user-authored is escaped with {{ }} except:
    - $presented['styleSheet']  validated/enum-mapped design CSS + scoped custom CSS (`<` escaped)
    - htmlBefore/htmlAfter/field html  sanitized by HtmlSanitizer at render time
  See docs/FORMS.md §Security.
--}}
@php
  use TMAn1An\FormBuilder\Enums\FormFieldType as T;
  use TMAn1An\FormBuilder\Services\Forms\FieldSettingsSchema;
  $form = $presented['form'];
  $formErrors = isset($errors) ? $errors->getBag('form_'.$form->id) : new \Illuminate\Support\MessageBag;
  // Old input is only reused for the form that was actually submitted with errors.
  $hasOld = $formErrors->any() && session()->hasOldInput();
  $old = fn (string $key, mixed $default = null) => $hasOld ? old($key, $default) : $default;
  $titleTag = in_array($titleTag ?? 'h1', ['h1', 'h2'], true) ? ($titleTag ?? 'h1') : 'h1';
@endphp
<style>{!! $presented['styleSheet'] !!}</style>
<div class="ff-form" id="{{ $presented['wrapperId'] }}">
  @if ($presented['banner'])
    @php $banner = $presented['banner']; @endphp
    <figure class="ff-banner">
      <img src="{{ $banner['url'] }}" alt="{{ $banner['alt'] }}"
        @if ($banner['width']) width="{{ $banner['width'] }}" @endif @if ($banner['height']) height="{{ $banner['height'] }}" @endif
        @if ($banner['style'] !== '') style="{{ $banner['style'] }}" @endif>
    </figure>
  @endif
  @if (empty($hideTitle))
    <{{ $titleTag }} class="ff-title">{{ $form->title() }}</{{ $titleTag }}>
  @endif
  @if ($form->description && empty($hideDescription))
    <p class="ff-description">{{ $form->description }}</p>
  @endif

  @if (! empty($success))
    <div class="ff-alert ff-alert--success" role="status">{{ $success }}</div>
  @elseif (! empty($notice))
    <div class="ff-alert ff-alert--info" role="status">{{ $notice }}</div>
  @else
    @if ($presented['htmlBefore'] !== '')
      <div class="ff-custom-html ff-custom-html--before ff-html">{!! $presented['htmlBefore'] !!}</div>
    @endif

    @if ($formErrors->any())
      <div class="ff-alert ff-alert--error" role="alert">
        Please correct the highlighted fields.
        @if ($formErrors->has('form'))
          <ul><li>{{ $formErrors->first('form') }}</li></ul>
        @endif
      </div>
    @endif

    <form method="POST" action="{{ $action ?? '#' }}" enctype="multipart/form-data" @if (! $action) onsubmit="return false" @endif>
      @csrf
      @if (! empty($embedPageId))
        <input type="hidden" name="_embed_page" value="{{ (int) $embedPageId }}">
      @endif
      <div class="ff-grid">
        @foreach ($presented['fields'] as $f)
          @php
            /** @var \TMAn1An\FormBuilder\Models\FormField $model */
            $model = $f['model'];
            $type = $f['type'];
            $key = $f['key'];
            $error = $formErrors->first($key) ?: $formErrors->first($key.'.*');
            $default = $model->setting('default_value');
            $value = $model->usesServerValue() ? $default : $old($key, $default);
            $disabled = $model->isDisabled();
            $wrapperClasses = trim('ff-field ff-field--'.$type->value.' '.$f['widthClass'].' '.$f['cssClass'].($error ? ' has-error' : ''));
          @endphp

          @if ($type === T::Hidden)
            <input type="hidden" name="{{ $key }}" value="{{ $default }}">
            @continue
          @endif
          @if ($type === T::ImageBlock && ! $f['picture'])
            @continue {{-- its image no longer exists --}}
          @endif

          <div class="{{ $wrapperClasses }}" data-ff-key="{{ $key }}" @if ($f['inlineStyle'] !== '') style="{{ $f['inlineStyle'] }}" @endif>
            @switch($type)
              @case(T::Heading)
                @php $level = $f['headingLevel']; @endphp
                <{{ $level }} class="ff-heading" @if ($f['textAlign']) style="text-align:{{ $f['textAlign'] }}" @endif>{{ $model->setting('content', $model->label) }}</{{ $level }}>
                @break

              @case(T::Paragraph)
                <p class="ff-paragraph" @if ($f['textAlign']) style="text-align:{{ $f['textAlign'] }}" @endif>{{ $model->setting('content') }}</p>
                @break

              @case(T::Divider)
                <hr class="ff-divider">
                @break

              @case(T::Section)
                <div class="ff-section">
                  <h2 class="ff-section__title">{{ $model->label }}</h2>
                  @if ($model->setting('content'))
                    <p class="ff-section__desc">{{ $model->setting('content') }}</p>
                  @endif
                </div>
                @break

              @case(T::Html)
                <div class="ff-html">{!! $f['html'] !!}</div>
                @break

              @case(T::ImageBlock)
                @php $pic = $f['picture']; @endphp
                <figure class="ff-image" style="text-align:{{ $pic['align'] }}">
                  @if ($pic['link'])<a href="{{ $pic['link'] }}" @if ($pic['newTab']) target="_blank" rel="noopener noreferrer" @endif>@endif
                  <img src="{{ $pic['url'] }}" alt="{{ $pic['alt'] }}" loading="lazy"
                    @if ($pic['width']) width="{{ $pic['width'] }}" @endif @if ($pic['height']) height="{{ $pic['height'] }}" @endif
                    @if ($pic['style'] !== '') style="{{ $pic['style'] }}" @endif>
                  @if ($pic['link'])</a>@endif
                </figure>
                @break

              @case(T::Radio)
              @case(T::CheckboxGroup)
                @php
                  $selected = $type === T::CheckboxGroup
                      ? array_map('strval', (array) $old($key, []))
                      : [(string) $value];
                @endphp
                <fieldset class="ff-choices {{ $model->setting('options_layout') === 'inline' ? 'ff-choices--inline' : '' }}">
                  <legend class="ff-label">{{ $model->label }}@if ($model->required)<span class="ff-req" aria-hidden="true">*</span>@endif</legend>
                  <div class="ff-choices__list">
                    @foreach ($model->options() as $i => $option)
                      <label class="ff-choice">
                        <input type="{{ $type === T::Radio ? 'radio' : 'checkbox' }}" name="{{ $f['name'] }}" value="{{ $option['value'] }}"
                          @checked(in_array((string) $option['value'], $selected, true))
                          @if ($type === T::Radio && $model->required) required @endif
                          @if ($disabled) disabled data-ff-disabled @endif>
                        <span>{{ $option['label'] }}</span>
                      </label>
                    @endforeach
                  </div>
                </fieldset>
                @break

              @case(T::Checkbox)
                @php $checked = $hasOld ? (bool) old($key) : (bool) $default; @endphp
                @if ($model->setting('checkbox_text'))
                  <span class="ff-label">{{ $model->label }}@if ($model->required)<span class="ff-req" aria-hidden="true">*</span>@endif</span>
                @endif
                <label class="ff-choice">
                  <input type="checkbox" name="{{ $key }}" value="1" @checked($checked) @if ($model->required) required @endif @if ($disabled) disabled data-ff-disabled @endif>
                  <span>{{ $model->setting('checkbox_text', $model->label) }}@if ($model->required && ! $model->setting('checkbox_text'))<span class="ff-req" aria-hidden="true">*</span>@endif</span>
                </label>
                @break

              @case(T::Image)
                @php
                  $mimes = FieldSettingsSchema::imageMimes($model->settings ?? []);
                  $exts = strtoupper(implode(', ', array_keys(array_intersect(FieldSettingsSchema::IMAGE_TYPES, $mimes))));
                @endphp
                <label class="ff-label" for="{{ $f['inputId'] }}">{{ $model->label }}@if ($model->required)<span class="ff-req" aria-hidden="true">*</span>@endif</label>
                <input class="ff-input ff-input--file" id="{{ $f['inputId'] }}" type="file" name="{{ $key }}" accept="{{ implode(',', $mimes) }}"
                  @if ($model->required) required @endif>
                <p class="ff-help">{{ $exts }} &middot; up to {{ number_format(FieldSettingsSchema::imageMaxKb($model->settings ?? []) / 1024, 1) }} MB</p>
                @break

              @default
                <label class="ff-label" for="{{ $f['inputId'] }}">{{ $model->label }}@if ($model->required)<span class="ff-req" aria-hidden="true">*</span>@endif</label>
                @if ($type === T::LongText)
                  <textarea class="ff-input" id="{{ $f['inputId'] }}" name="{{ $key }}" rows="{{ $model->setting('rows', 4) }}"
                    placeholder="{{ $model->setting('placeholder') }}"
                    @if ($model->setting('max_length')) maxlength="{{ $model->setting('max_length') }}" @endif
                    @if ($model->required) required @endif @if ($model->isReadOnly()) readonly @endif
                    @if ($disabled) disabled data-ff-disabled @endif>{{ $value }}</textarea>
                @elseif ($type === T::Select)
                  <select class="ff-input" id="{{ $f['inputId'] }}" name="{{ $key }}" @if ($model->required) required @endif @if ($disabled) disabled data-ff-disabled @endif>
                    <option value="">{{ $model->setting('placeholder', 'Select…') }}</option>
                    @foreach ($model->options() as $option)
                      <option value="{{ $option['value'] }}" @selected((string) $value === (string) $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                  </select>
                @else
                  <input class="ff-input" id="{{ $f['inputId'] }}" type="{{ $type->htmlInputType() }}" name="{{ $key }}" value="{{ $value }}"
                    placeholder="{{ $model->setting('placeholder') }}"
                    @if ($model->setting('max_length')) maxlength="{{ $model->setting('max_length') }}" @endif
                    @if ($model->setting('min') !== null) min="{{ $model->setting('min') }}" @endif
                    @if ($model->setting('max') !== null) max="{{ $model->setting('max') }}" @endif
                    @if ($model->setting('step')) step="{{ $model->setting('step') }}" @elseif ($type === T::Number) step="any" @endif
                    @if ($model->required) required @endif @if ($model->isReadOnly()) readonly @endif
                    @if ($disabled) disabled data-ff-disabled @endif>
                @endif
            @endswitch

            @if ($type->acceptsUserInput() && $model->setting('help_text'))
              <p class="ff-help">{{ $model->setting('help_text') }}</p>
            @endif
            @if ($error)
              <p class="ff-error">{{ $error }}</p>
            @endif
          </div>
        @endforeach
      </div>

      <div class="ff-actions">
        <button type="submit" class="ff-button" @if (! $action) disabled title="Preview only — submissions are disabled" @endif>{{ $form->setting('submit_label', 'Submit') }}</button>
      </div>
    </form>

    @if ($presented['htmlAfter'] !== '')
      <div class="ff-custom-html ff-custom-html--after ff-html">{!! $presented['htmlAfter'] !!}</div>
    @endif

    <script type="application/json" data-ff-logic>@json($presented['logic'])</script>
  @endif
</div>
