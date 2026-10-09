{{--
  Public page (and, with $previewBackUrl set, the admin preview), inside the
  host's public layout. Title/description are passed as plain text.
--}}
@php $page = $presented['page']; @endphp
<x-dynamic-component :component="config('formbuilder.public.layout', 'formbuilder::public-layout')"
    :title="$page->title"
    :description="(string) $page->setting('meta_description', '')"
    :url="url()->current()">
  <x-slot:head>
    <link rel="stylesheet" href="{{ $fb->asset('css/forms.css') }}">
    <link rel="stylesheet" href="{{ $fb->asset('css/pages.css') }}">
    @isset($previewBackUrl)<meta name="robots" content="noindex,nofollow">@endisset
  </x-slot:head>

  @isset($previewBackUrl)
    <div class="ff-preview-banner">
      Preview &mdash; drafts and unpublished forms are shown here but not to visitors. Form submissions are disabled.
      <a href="{{ $previewBackUrl }}">Back to the builder</a>
    </div>
  @endisset

  <main id="main">
    @include('formbuilder::pages._renderer')
  </main>

  <x-slot:scripts>
    <script src="{{ $fb->asset('js/form-logic.js') }}"></script>
    <script src="{{ $fb->asset('js/form-runtime.js') }}"></script>
  </x-slot:scripts>
</x-dynamic-component>
