<!doctype html>
<html lang="en">
<body style="margin:0;padding:24px;background:#f6f6f4;font-family:Arial,Helvetica,sans-serif;font-size:17px;line-height:1.55;color:#1f2328">
<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;padding:28px">
    @foreach (preg_split('/\n{2,}/', trim($body)) as $para)
        <p style="margin:0 0 16px">{!! nl2br(e($para)) !!}</p>
    @endforeach
</div>
<p style="max-width:600px;margin:16px auto 0;font-size:13px;color:#5b616b;text-align:center">
    {{ $brokerage }}@if ($postalAddress) · {{ $postalAddress }}@endif<br>
    You are getting this because {{ $fromName }} is in touch with you about working at {{ $brokerage }}.
    <a href="{{ $stopUrl }}" style="color:#5b616b">Stop these emails</a>.
</p>
</body>
</html>
