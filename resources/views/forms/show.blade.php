{{--
  Public form page (and, with $previewBackUrl set, the admin preview),
  rendered inside the host's public layout (config('formbuilder.public.layout')).
  `title`/`description` are passed as PLAIN TEXT -- the layout contract says
  the layout escapes them.
--}}
@php $form = $presented['form']; @endphp
<x-dynamic-component :component="config('formbuilder.public.layout', 'formbuilder::public-layout')"
    :title="$form->title()"
    :description="\Illuminate\Support\Str::limit($form->description ?? $form->title(), 150)"
    :url="url()->current()">
  <x-slot:head>
    <link rel="stylesheet" href="{{ $fb->asset('css/forms.css') }}">
    @isset($previewBackUrl)<meta name="robots" content="noindex,nofollow">@endisset
  </x-slot:head>

  @isset($previewBackUrl)
    <div class="ff-preview-banner">
      Preview &mdash; this is how the form looks to visitors. Submissions are disabled here.
      <a href="{{ $previewBackUrl }}">Back to the builder</a>
    </div>
  @endisset

  <main id="main" class="ff-page">
    @include('formbuilder::forms._renderer')
  </main>

  <x-slot:scripts>
    <script src="{{ $fb->asset('js/form-logic.js') }}"></script>
    <script src="{{ $fb->asset('js/form-runtime.js') }}"></script>
  </x-slot:scripts>
</x-dynamic-component>
