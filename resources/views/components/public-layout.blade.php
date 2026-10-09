{{--
  Default public shell for STANDALONE installs. Hosts normally replace it
  with an adapter around their site layout via config('formbuilder.public.layout').
  Contract: `title` (plain text -- escape it), `description` (plain text),
  `url`, plus `head` and `scripts` slots.
--}}
@props(['title' => '', 'description' => '', 'url' => null])
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
@if ($description !== '')
<meta name="description" content="{{ $description }}">
@endif
@if ($url)
<link rel="canonical" href="{{ $url }}">
@endif
<style>body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1a1a1a;background:#f4f6f8}</style>
{{ $head ?? '' }}
</head>
<body>
{{ $slot }}
{{ $scripts ?? '' }}
</body>
</html>
