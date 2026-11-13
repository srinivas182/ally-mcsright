<!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
.check { height: 3.4in; border-bottom: 1px dashed #999; position: relative; page-break-inside: avoid; }
.no { position: absolute; top: 0.35in; right: 0.5in; font-size: 13px; }
.date { position: absolute; top: 0.8in; right: 0.5in; }
.payee { position: absolute; top: 1.3in; left: 0.9in; font-size: 13px; }
.amount { position: absolute; top: 1.3in; right: 0.5in; font-size: 13px; }
.words { position: absolute; top: 1.75in; left: 0.5in; }
.memo { position: absolute; top: 2.6in; left: 0.5in; }
.stub { height: 3.3in; padding-top: 0.2in; border-bottom: 1px dashed #999; }
</style></head><body>
@foreach ($checks as $c)
<div class="check">
  <div class="no">{{ $c['number'] }}</div>
  <div class="date">{{ \Carbon\CarbonImmutable::parse($c['date'])->format('m/d/Y') }}</div>
  <div class="payee">{{ $c['name'] }}</div>
  <div class="amount">**{{ number_format($c['cents'] / 100, 2) }}</div>
  <div class="words">{{ \Modules\Payouts\Words::dollars($c['cents']) }} ********</div>
  <div class="memo">{{ $c['memo'] }}</div>
</div>
<div class="stub">{{ $brokerage }} · Check {{ $c['number'] }} · {{ $c['name'] }} · ${{ number_format($c['cents'] / 100, 2) }} · {{ $c['memo'] }}</div>
@endforeach
</body></html>
