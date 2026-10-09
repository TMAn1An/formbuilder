{{--
  Every package admin screen renders through this wrapper: it puts the
  package's own (fba-prefixed) admin styles into the host layout's `head`
  slot and wraps the content in `.fba`, then defers to whichever layout the
  host configured in config('formbuilder.admin.layout').
--}}
@props(['title' => 'Builder', 'wide' => false])
@php $fb = app(\TMAn1An\FormBuilder\FormBuilder::class); @endphp
<x-dynamic-component :component="config('formbuilder.admin.layout', 'formbuilder::admin-layout')" :title="$title" :wide="$wide">
  <x-slot:head>
    <link rel="stylesheet" href="{{ $fb->asset('css/admin.css') }}">
    <script src="{{ $fb->asset('js/admin.js') }}" defer></script>
    {{ $head ?? '' }}
  </x-slot:head>
  <div class="fba">
    {{ $slot }}
  </div>
</x-dynamic-component>
