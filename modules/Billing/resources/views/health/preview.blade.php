@php $c = $r['brand_color']; @endphp
@extends('billing::health.layout', ['title' => 'Preview: '.$r['brokerage'].' | RightAlly', 'description' => 'Private branded preview.', 'lang' => $lang])

@push('head')
<style>
    .app{border:1px solid var(--line);border-radius:18px;overflow:hidden;background:var(--card);box-shadow:0 10px 40px rgb(0 0 0 / .08);margin:20px 0}
    .appbar{display:flex;align-items:center;gap:12px;padding:12px 18px;background:{{ $c }};color:{{ $on }};font-weight:800;font-size:20px}
    .appbar img{height:36px;width:auto;max-width:160px;object-fit:contain;background:#fff;border-radius:8px;padding:3px}
    .appbody{padding:20px;font-size:20px}
    .talk{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:72px;border-radius:16px;border:0;background:{{ $c }};color:{{ $on }};font:800 22px/1 inherit;margin:12px 0 4px}
    .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0}
    .tiles div{border:2px solid var(--line);border-radius:16px;padding:20px 14px;text-align:center;font-weight:700;min-height:96px;display:grid;place-items:center}
    .needs li{padding:12px 14px;border:1px solid var(--line);border-radius:12px;margin-bottom:8px;list-style:none}
    .sample{display:inline-block;font-size:14px;font-weight:700;border:1px dashed currentColor;border-radius:999px;padding:2px 10px;margin-left:auto}
</style>
@endpush

@section('main')
<div class="wrap" style="padding-top:24px">
    <p class="actions small"><a href="{{ $base }}">← {{ $samples['back'] }}</a> <a href="{{ $base }}/preview{{ $lang === 'es' ? '' : '?lang=es' }}" lang="{{ $lang === 'es' ? 'en' : 'es' }}">{{ $samples['switch'] }}</a></p>
    <p class="lead">{{ $samples['note'] }}</p>
    <div class="app" lang="{{ $lang }}">
        <div class="appbar">
            @if ($r['has_logo'])<img src="{{ $base }}/logo" alt="{{ $r['brokerage'] }}">@endif
            <span>{{ $r['brokerage'] }}</span>
            <span class="sample">{{ $samples['label'] }}</span>
        </div>
        <div class="appbody">
            <h2 style="margin:0">{{ str_replace(':name', $samples['agent'], $w['greeting']) }}</h2>
            <p class="muted">{{ $w['what'] }}</p>
            <div class="talk" role="presentation"><span aria-hidden="true">🎤</span> {{ $w['talk'] }}</div>
            <p class="muted small">{{ $w['talk_hint'] }}</p>
            <div class="tiles">
                <div>{{ $w['add_person'] }}</div><div>{{ $w['start_deal'] }}</div><div>{{ $w['call_today'] }}</div>
                <div>{{ $w['my_deals'] }}</div><div>{{ $w['/tasks'] }}</div><div>{{ $w['ask'] }}</div>
            </div>
            <h3>{{ $w['needs_you'] }}</h3>
            <ul class="needs" style="padding:0">@foreach ($samples['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>
        </div>
    </div>
</div>
@endsection
