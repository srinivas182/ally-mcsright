<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>Stop market reports</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:480px;margin:48px auto;padding:0 16px;font-size:18px;color:#0f172a}button{font-size:18px;padding:12px 20px;border-radius:8px;border:0;background:#0f172a;color:#fff}</style></head>
<body>
@if ($done)
<h1 style="font-size:24px">Done</h1>
<p role="status">You will not get more market reports. Nothing else changes.</p>
@else
<h1 style="font-size:24px">Stop the monthly market report?</h1>
<p>You will stop getting the monthly market report email. Nothing else changes.</p>
<form method="post" action="{{ $action }}"><button type="submit">Yes, stop them</button></form>
@endif
</body>
</html>
