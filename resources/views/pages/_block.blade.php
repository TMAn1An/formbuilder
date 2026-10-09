{{-- One page block. Inline styles were rebuilt from re-checked values by PagePresenter. --}}
@php
  $s = $block['settings'];
  $styleAttr = $block['style'] !== '' ? $block['style'] : null;
  $newTab = ! empty($s['new_tab']);
@endphp
@switch($block['type'])
  @case('section')
    <section class="pb-block pb-section" @if ($styleAttr) style="{{ $styleAttr }}" @endif>
      @foreach ($block['slots'][0] ?? [] as $child)
        @include('formbuilder::pages._block', ['block' => $child])
      @endforeach
    </section>
    @break

  @case('columns')
    <div class="pb-block pb-columns" @if ($styleAttr) style="{{ $styleAttr }}" @endif>
      @foreach ($block['slots'] as $slot)
        <div class="pb-col">
          @foreach ($slot as $child)
            @include('formbuilder::pages._block', ['block' => $child])
          @endforeach
        </div>
      @endforeach
    </div>
    @break

  @case('heading')
    <{{ $block['tag'] }} class="pb-block pb-heading pb-heading--{{ $block['tag'] }}" @if ($styleAttr) style="{{ $styleAttr }}" @endif>{{ $s['text'] ?? '' }}</{{ $block['tag'] }}>
    @break

  @case('paragraph')
    <div class="pb-block pb-paragraph pb-rich" @if ($styleAttr) style="{{ $styleAttr }}" @endif>{!! $block['html'] !!}</div>
    @break

  @case('html')
    <div class="pb-block pb-html pb-rich" @if ($styleAttr) style="{{ $styleAttr }}" @endif>{!! $block['html'] !!}</div>
    @break

  @case('image')
    @if (isset($block['media']))
      <figure class="pb-block pb-image" @if ($styleAttr) style="{{ $styleAttr }}" @endif>
        @if ($block['link'])<a href="{{ $block['link'] }}"@if ($newTab) target="_blank" rel="noopener noreferrer"@endif>@endif
        <img src="{{ $block['media']['url'] }}" alt="{{ $block['media']['alt'] }}" loading="lazy"
          @if ($block['media']['width']) width="{{ $block['media']['width'] }}" height="{{ $block['media']['height'] }}" @endif
          @if ($block['imageStyle'] !== '') style="{{ $block['imageStyle'] }}" @endif>
        @if ($block['link'])</a>@endif
      </figure>
    @else
      <div class="pb-block pb-notice">{{ $block['missing'] }}</div>
    @endif
    @break

  @case('button')
    <div class="pb-block pb-button" style="{{ $block['style'] }}">
      <a class="ff-button" href="{{ $block['link'] }}" @if ($newTab) target="_blank" rel="noopener noreferrer" @endif>{{ $s['text'] ?? '' }}</a>
    </div>
    @break

  @case('divider')
    <hr class="pb-block pb-divider" @if ($styleAttr) style="{{ $styleAttr }}" @endif>
    @break

  @case('spacer')
    <div class="pb-block pb-spacer" aria-hidden="true" @if ($styleAttr) style="{{ $styleAttr }}" @endif></div>
    @break

  @case('form')
    @if (isset($block['form']))
      <div class="pb-block pb-form">
        @include('formbuilder::forms._renderer', [
            'presented' => $block['form']['presented'],
            'action' => $block['form']['action'],
            'notice' => $block['form']['notice'],
            'success' => $block['form']['success'],
            'embedPageId' => $block['form']['embedPageId'],
            'hideTitle' => $block['form']['hideTitle'],
            'hideDescription' => $block['form']['hideDescription'],
            'titleTag' => 'h2',
        ])
      </div>
    @else
      <div class="pb-block pb-notice">{{ $block['missing'] }}</div>
    @endif
    @break
@endswitch
