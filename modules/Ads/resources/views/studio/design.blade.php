{{--
    Ally Studio design (specs/grow/13, SA1b). One page per size, rendered to an image by headless Chrome.
    $w, $h, $variant (full|split|frame), $type (listing|text|agent), $collection, $p (palette), $kit, $c (content),
    $fonts (@font-face CSS), $hero (main image URL or null), $agent (cut-out or headshot URL or null), $logo, $scale.
    Everything is escaped; no scripts.
--}}
@php
    $s = $scale; // 1 for portrait; smaller type for square, more room for story
    $hl = $c['headline'] ?? '';
    $hsize = (int) round(($h >= 1900 ? 104 : ($h >= 1300 ? 88 : 76)) * (mb_strlen($hl) > 40 ? 0.68 : (mb_strlen($hl) > 26 ? 0.82 : 1)));
    $serifCaps = in_array($collection, ['luxury_noir', 'estate'], true);
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<style>
{!! $fonts !!}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:{{ $w }}px;height:{{ $h }}px;overflow:hidden}
body{background:{{ $p['bg'] }};color:{{ $p['fg'] }};font-family:'{{ $kit['fonts']['body'] }}',sans-serif;position:relative;-webkit-font-smoothing:antialiased}
.h{font-family:'{{ $kit['fonts']['heading'] }}',serif;font-weight:600;line-height:1.04;letter-spacing:-0.01em}
.k{font-size:{{ round(22 * $s) }}px;letter-spacing:.32em;text-transform:uppercase;color:{{ $p['accent'] }};font-weight:600}
.rule{height:2px;background:{{ $p['accent'] }};width:96px}
.muted{color:{{ $p['muted'] }}}
.facts{display:flex;flex-wrap:wrap;gap:0 22px;font-size:{{ round(28 * $s) }}px;letter-spacing:.04em}
.facts span+span:before{content:"·";color:{{ $p['accent'] }};margin-right:22px}
.price{font-size:{{ round(54 * $s) }}px;color:{{ $p['accent'] }}}
.cover{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.logo{max-height:{{ round(64 * $s) }}px;max-width:300px;object-fit:contain}
.agent{display:flex;align-items:center;gap:22px}
.agent img{width:{{ round(118 * $s) }}px;height:{{ round(118 * $s) }}px;border-radius:50%;object-fit:cover;object-position:top;background:{{ $p['accent'] }};border:3px solid {{ $p['accent'] }}}
.agent .n{font-size:{{ round(30 * $s) }}px;font-weight:600}
.agent .t{font-size:{{ round(22 * $s) }}px}
.legal{position:absolute;left:56px;right:56px;bottom:22px;display:flex;justify-content:space-between;align-items:center;gap:16px;font-size:15px;color:{{ $p['muted'] }}}
.eho{display:flex;align-items:center;gap:8px;white-space:nowrap}
.body{font-size:{{ round(34 * $s) }}px;line-height:1.35}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:28px}
.stat{display:grid;gap:8px}.stat .v{font-size:{{ round(72 * $s) }}px;color:{{ $p['accent'] }}}
.stat .l{font-size:{{ round(22 * $s) }}px;letter-spacing:.2em;text-transform:uppercase}
@if ($collection === 'modern') .h{font-family:'{{ $kit['fonts']['body'] }}',sans-serif;font-weight:600;letter-spacing:-0.035em}.rule{height:6px;width:72px}.k{letter-spacing:.16em} @endif
@if ($collection === 'coastal') img{border-radius:28px}.cover{border-radius:0}.rule{border-radius:3px;height:4px} @endif
@if ($serifCaps) .kick-rules{display:flex;align-items:center;gap:18px}.kick-rules:before,.kick-rules:after{content:"";height:1px;width:64px;background:{{ $p['accent'] }}} @endif
</style></head><body>

@php
    $eho = '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 2 10h3v11h14V10h3z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M8 12h8M8 16h8" stroke="currentColor" stroke-width="1.8"/></svg>';
@endphp

@if ($variant === 'full' && $hero)
    <img class="cover" src="{{ $hero }}" alt="">
    <div style="position:absolute;inset:0;background:linear-gradient(180deg,{{ $p['shade0'] }} 0%,{{ $p['shade0'] }} 38%,{{ $p['shade'] }} 72%,{{ $p['bg'] }} 100%)"></div>
    <div style="position:absolute;top:56px;left:56px;right:56px;display:flex;justify-content:space-between;align-items:center">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@else<span class="k" style="color:{{ $p['fg'] }}">{{ $kit['brokerage'] }}</span>@endif
        <span class="k" style="border:1.5px solid {{ $p['accent'] }};padding:12px 22px;background:{{ $p['shade'] }}">{{ $c['kicker'] }}</span>
    </div>
    <div style="position:absolute;left:56px;right:56px;bottom:{{ $h >= 1900 ? 300 : 236 }}px;display:grid;gap:{{ round(22 * $s) }}px">
        <div class="h" style="font-size:{{ $hsize }}px">{{ $hl }}</div>
        @if (! empty($c['sub']))<div class="muted" style="font-size:{{ round(30 * $s) }}px">{{ $c['sub'] }}</div>@endif
        <div class="rule"></div>
        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px">
            @if (! empty($c['facts']))<div class="facts">@foreach ($c['facts'] as $f)<span>{{ $f }}</span>@endforeach</div>@endif
            @if (! empty($c['price']))<div class="price h">{{ $c['price'] }}</div>@endif
        </div>
    </div>
    <div style="position:absolute;left:56px;right:56px;bottom:{{ $h >= 1900 ? 110 : 64 }}px" class="agent">
        @if ($agent)<img src="{{ $agent }}" alt="">@endif
        <div><div class="n">{{ $kit['details']['display_name'] ?? '' }}</div><div class="t muted">{{ $c['cta'] ?? ($kit['details']['phone'] ?? '') }}</div></div>
    </div>

@elseif ($variant === 'split' && $hero)
    @php $cut = $h >= 1900 ? 0.6 : ($h >= 1300 ? 0.56 : 0.5); @endphp
    <img src="{{ $hero }}" alt="" style="position:absolute;top:0;left:0;width:100%;height:{{ round($h * $cut) }}px;object-fit:cover">
    <span class="k" style="position:absolute;top:48px;left:48px;background:{{ $p['bg'] }};padding:14px 24px">{{ $c['kicker'] }}</span>
    @if ($agent)
        <img src="{{ $agent }}" alt="" style="position:absolute;right:64px;top:{{ round($h * $cut) - round(120 * $s) }}px;width:{{ round(240 * $s) }}px;height:{{ round(240 * $s) }}px;border-radius:50%;object-fit:cover;object-position:top;background:{{ $p['accent'] }};border:6px solid {{ $p['bg'] }};box-shadow:0 0 0 2px {{ $p['accent'] }}">
    @endif
    <div style="position:absolute;top:{{ round($h * $cut) + 56 }}px;left:64px;right:{{ $agent ? round(330 * $s) : 64 }}px;display:grid;gap:{{ round(18 * $s) }}px">
        <div class="h" style="font-size:{{ round($hsize * 0.9) }}px">{{ $hl }}</div>
        @if (! empty($c['sub']))<div class="muted" style="font-size:{{ round(28 * $s) }}px">{{ $c['sub'] }}</div>@endif
    </div>
    <div style="position:absolute;left:64px;right:64px;bottom:{{ $h >= 1900 ? 200 : 76 }}px;display:grid;gap:{{ round(18 * $s) }}px">
        <div class="rule"></div>
        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:20px">
            @if (! empty($c['facts']))<div class="facts">@foreach ($c['facts'] as $f)<span>{{ $f }}</span>@endforeach</div>@endif
            @if (! empty($c['price']))<div class="price h">{{ $c['price'] }}</div>@endif
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div><span class="n" style="font-size:{{ round(28 * $s) }}px;font-weight:600">{{ $kit['details']['display_name'] ?? '' }}</span>
                <span class="muted" style="font-size:{{ round(22 * $s) }}px">&nbsp; {{ $kit['details']['phone'] ?? '' }}</span></div>
            @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
        </div>
    </div>

@elseif ($variant === 'frame' && $hero)
    @php $ph = round($h * ($h >= 1900 ? 0.5 : 0.52)); @endphp
    <div style="position:absolute;inset:28px;border:1.5px solid {{ $p['accent'] }}"></div>
    <div style="position:absolute;inset:38px;border:1px solid {{ $p['rule'] }}"></div>
    <div style="position:absolute;top:84px;left:84px;right:84px;height:{{ $ph }}px;overflow:hidden"><img class="cover" src="{{ $hero }}" alt=""></div>
    <div style="position:absolute;top:{{ $ph + 124 }}px;left:84px;right:84px;text-align:center;display:grid;justify-items:center;gap:{{ round(18 * $s) }}px">
        <div class="k kick-rules">{{ $c['kicker'] }}</div>
        <div class="h" style="font-size:{{ round($hsize * 0.86) }}px">{{ $hl }}</div>
        @if (! empty($c['sub']))<div class="muted" style="font-size:{{ round(26 * $s) }}px">{{ $c['sub'] }}</div>@endif
        @if (! empty($c['facts']))<div class="facts" style="justify-content:center">@foreach ($c['facts'] as $f)<span>{{ $f }}</span>@endforeach</div>@endif
        @if (! empty($c['price']))<div class="price h">{{ $c['price'] }}</div>@endif
    </div>
    <div style="position:absolute;left:84px;right:84px;bottom:{{ $h >= 1900 ? 130 : 96 }}px;display:flex;justify-content:space-between;align-items:center">
        <div class="agent">@if ($agent)<img src="{{ $agent }}" alt="">@endif
            <div><div class="n">{{ $kit['details']['display_name'] ?? '' }}</div><div class="t muted">{{ $kit['details']['title'] ?? $kit['brokerage'] }}</div></div></div>
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
    </div>

@else
    {{-- Text and agent designs: typography first, the agent as the picture. --}}
    @if ($agent && $type === 'agent')
        <div style="position:absolute;right:-80px;bottom:0;width:{{ round($w * 0.72) }}px;height:{{ round($h * 0.78) }}px;background:{{ $p['panel'] }};border-top-left-radius:{{ round($w * 0.36) }}px"></div>
        <img src="{{ $agent }}" alt="" style="position:absolute;right:0;bottom:0;height:{{ round($h * 0.74) }}px;max-width:{{ round($w * 0.7) }}px;object-fit:contain;object-position:bottom right">
    @endif
    <div style="position:absolute;top:64px;left:64px;right:64px;display:flex;justify-content:space-between;align-items:center">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@else<span class="k" style="color:{{ $p['fg'] }}">{{ $kit['brokerage'] }}</span>@endif
    </div>
    <div style="position:absolute;top:47%;transform:translateY(-50%);left:64px;right:{{ $agent && $type === 'agent' ? round($w * 0.42) : 64 }}px;display:grid;gap:{{ round(26 * $s) }}px;{{ $collection === 'estate' && $type !== 'agent' ? 'text-align:center;justify-items:center' : '' }}">
        <div class="k {{ $serifCaps ? 'kick-rules' : '' }}">{{ $c['kicker'] }}</div>
        <div class="h" style="font-size:{{ $hsize }}px">{{ $hl }}</div>
        <div class="rule"></div>
        @if (! empty($c['stats']))
            <div class="stats">@foreach ($c['stats'] as $st)<div class="stat"><div class="v h">{{ $st['value'] }}</div><div class="l muted">{{ $st['label'] }}</div></div>@endforeach</div>
        @endif
        @if (! empty($c['body']))<div class="body">{{ $c['body'] }}</div>@endif
        @if (! empty($c['by']))<div class="muted" style="font-size:{{ round(26 * $s) }}px">— {{ $c['by'] }}</div>@endif
    </div>
    <div style="position:absolute;left:64px;bottom:{{ $h >= 1900 ? 120 : 76 }}px" class="agent">
        @if ($agent && $type !== 'agent')<img src="{{ $agent }}" alt="">@endif
        <div><div class="n">{{ $kit['details']['display_name'] ?? '' }}</div><div class="t muted">{{ $kit['details']['phone'] ?? $kit['brokerage'] }}</div></div>
    </div>
@endif

<div class="legal" @if ($variant === 'frame') style="left:84px;right:84px;bottom:52px" @endif>
    <span>{{ implode(' · ', array_filter([$c['attribution'] ?? null, $kit['details']['disclaimer'] ?? null, $kit['details']['licence_line'] ?? null, $kit['brokerage']])) }}</span>
    <span class="eho">{!! $eho !!} Equal Housing Opportunity</span>
</div>
</body></html>
