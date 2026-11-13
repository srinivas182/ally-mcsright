<!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; line-height: 1.45; } h1 { font-size: 15px; }
.body { white-space: pre-wrap; } .sig { margin-top: 18px; border-top: 1px solid #999; padding-top: 8px; } .muted { color: #555; font-size: 10px; }
</style></head><body>
<h1>{{ $brokerage }} — {{ $a['title'] }} (version {{ $a['version'] }})</h1>
<div class="body">{{ $a['body'] }}</div>
<div class="sig">
<p>Signed electronically by <strong>{{ $name }}</strong>, typed as “{{ $typed }}”, on {{ $at }}.</p>
<p class="muted">IP address: {{ $ip ?? 'not recorded' }} · Fingerprint of the signed text (SHA-256): {{ $sha }}</p>
</div>
</body></html>
