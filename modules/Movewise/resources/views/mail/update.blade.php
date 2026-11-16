<!DOCTYPE html>
<html lang="{{ $lang }}">
<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#14211f;line-height:1.5">
<h1 style="font-size:20px">{{ $heading }}</h1>
<p>{{ $body }}</p>
@foreach ($links as [$label, $url])
    <p><a href="{{ $url }}" style="color:#0f3d3e;font-weight:600">{{ $label }}</a></p>
@endforeach
<p style="color:#566360;font-size:13px">Movewise Homes. {{ config('movewise.postal_address') }}</p>
</body>
</html>
