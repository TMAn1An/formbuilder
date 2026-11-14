{{-- The form's banner: its own section above or below the form card (never
     inside it). Expects $banner from FormPresenter::banner() and $presented. --}}
<figure class="ff-banner-section ff-banner-section--{{ $banner['position'] }} ff-banner-section--{{ $banner['span'] }}" id="{{ $presented['wrapperId'] }}-banner">
  <img src="{{ $banner['url'] }}" alt="{{ $banner['alt'] }}"
    @if ($banner['width']) width="{{ $banner['width'] }}" @endif @if ($banner['height']) height="{{ $banner['height'] }}" @endif
    @if ($banner['style'] !== '') style="{{ $banner['style'] }}" @endif>
</figure>
