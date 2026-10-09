{{--
  Default admin shell for STANDALONE installs. Hosts normally replace it
  with their own admin layout via config('formbuilder.admin.layout') -- any
  Blade component accepting `title`, `wide` and a `head` slot works.
--}}
@props(['title' => 'Builder', 'wide' => false])
@php $fb = app(\TMAn1An\FormBuilder\FormBuilder::class); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $title }} &mdash; Builder</title>
<link rel="stylesheet" href="{{ $fb->asset('css/admin.css') }}">
{{ $head ?? '' }}
</head>
<body class="fba-standalone">
<div class="fba-shell">
  <nav class="fba-nav" aria-label="Builder">
    <strong class="fba-nav__brand">Builder</strong>
    <a href="{{ $fb->admin('forms.index') }}" @class(['is-active' => $fb->adminRouteIs('forms.index', 'forms.edit', 'forms.preview')])>Forms</a>
    <a href="{{ $fb->admin('forms.create') }}" @class(['is-active' => $fb->adminRouteIs('forms.create')])>Create form</a>
    <a href="{{ $fb->admin('forms.submissions.overview') }}" @class(['is-active' => $fb->adminRouteIs('forms.submissions.*')])>Submissions</a>
    <a href="{{ $fb->admin('pages.index') }}" @class(['is-active' => $fb->adminRouteIs('pages.index', 'pages.edit', 'pages.preview')])>Pages</a>
    <a href="{{ $fb->admin('pages.create') }}" @class(['is-active' => $fb->adminRouteIs('pages.create')])>Create page</a>
  </nav>
  <main class="fba-main{{ $wide ? ' fba-main--wide' : '' }}">
    <h1 class="fba-title">{{ $title }}</h1>
    @if (session('status'))
      <div class="fba-alert fba-alert--ok">{{ session('status') }}</div>
    @endif
    @if (isset($errors) && $errors->any())
      <div class="fba-alert fba-alert--error"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    {{ $slot }}
  </main>
</div>
</body>
</html>
