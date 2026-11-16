{{--
    Ally Studio design, premium system v2 (specs/grow/13, SA1c). One page per size, rendered by headless Chrome.
    Inputs: $w, $h, $layout, $collection, $p (palette), $kit, $c (content), $fonts (@font-face CSS), $photos (list of
    URLs, best first), $agent (cut-out or headshot URL), $logo, $monogram (initials). Everything is escaped; no scripts.

    Art direction: editorial typography (Cormorant Garamond, Bodoni Moda, Cinzel caps, Jost), hairline rules and
    metallic accents from the brand colour, film grain and gentle grading on photos, generous space, never boxes.
--}}
@php
    $story = $h >= 1900;
    $square = $h <= 1100;
    $u = $square ? 0.9 : ($story ? 1.08 : 1.0);
    $px = fn (float $n): string => round($n * $u).'px';
    $hl = (string) ($c['headline'] ?? '');
    $long = mb_strlen($hl) > 22;
    $hsize = $px(($long ? 82 : 104) * (mb_strlen($hl) > 34 ? 0.78 : 1));
    $photo = fn (int $i): ?string => $photos[$i] ?? $photos[0] ?? null;
    $name = (string) ($kit['details']['display_name'] ?? '');
    $title = (string) ($kit['details']['title'] ?? '');
    $phone = (string) ($kit['details']['phone'] ?? '');
    $facts = array_values(array_filter((array) ($c['facts'] ?? [])));
    $legal = implode(' · ', array_filter([$c['attribution'] ?? null, $kit['details']['disclaimer'] ?? null, $kit['details']['licence_line'] ?? null, $kit['brokerage']]));
    $dark = $p['dark'];
    $stars = $c['moment'] ?? '' ;
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<style>
{!! $fonts !!}
:root{--bg:{{ $p['bg'] }};--paper:{{ $p['paper'] }};--ink:{{ $p['fg'] }};--muted:{{ $p['muted'] }};--m1:{{ $p['metal_light'] }};--m2:{{ $p['metal'] }};--m3:{{ $p['metal_deep'] }};--rule:{{ $p['rule'] }}}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:{{ $w }}px;height:{{ $h }}px;overflow:hidden}
body{background:var(--bg);color:var(--ink);font-family:'Jost',sans-serif;font-weight:300;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision}
.canvas{position:relative;width:{{ $w }}px;height:{{ $h }}px;overflow:hidden}
.grain:after{content:"";position:absolute;inset:0;pointer-events:none;opacity:{{ $dark ? '.10' : '.07' }};mix-blend-mode:{{ $dark ? 'screen' : 'multiply' }};
  background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='220' height='220'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 .5 0 0 0 0 .5 0 0 0 0 .5 0 0 0 .55 0'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>")}
.onphoto{text-shadow:0 2px 28px rgba(0,0,0,.45)}
.ph{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:contrast(1.06) saturate(1.04) brightness(1.02)}
.metal{background:linear-gradient(100deg,var(--m3) 0%,var(--m1) 38%,var(--m2) 52%,var(--m1) 66%,var(--m3) 100%);-webkit-background-clip:text;background-clip:text;color:transparent}
.hair{height:1px;background:linear-gradient(90deg,transparent,var(--m2) 18%,var(--m1) 50%,var(--m2) 82%,transparent)}
.hair-l{height:1px;background:linear-gradient(90deg,var(--m2),transparent)}
.caps{font-family:'Cinzel',serif;font-weight:400;text-transform:uppercase;letter-spacing:.42em;font-size:{{ $px(19) }}}
.kick{display:flex;align-items:center;gap:{{ $px(22) }};justify-content:center}
.kick:before,.kick:after{content:"";height:1px;width:{{ $px(70) }};background:var(--m2)}
.display{font-family:'Cormorant Garamond',serif;font-weight:300;line-height:.98;letter-spacing:-.005em}
.ital{font-family:'Cormorant Garamond',serif;font-style:italic;font-weight:400}
.bodoni{font-family:'Bodoni Moda',serif;font-weight:400}
.sig{font-family:'Pinyon Script',cursive;line-height:1}
.facts{display:flex;align-items:center;font-family:'Jost',sans-serif;font-weight:400;text-transform:uppercase;letter-spacing:.24em;font-size:{{ $px(19) }}}
.facts span{padding:0 {{ $px(24) }};border-left:1px solid var(--rule);white-space:nowrap}
.facts span:first-child{border-left:0;padding-left:0}
.center .facts{justify-content:center}.center .facts span:first-child{padding-left:{{ $px(24) }}}
.offered{display:flex;align-items:baseline;gap:{{ $px(18) }}}
.offered small{white-space:nowrap;font-family:'Cinzel',serif;font-size:{{ $px(15) }};letter-spacing:.38em;color:var(--muted)}
.price{white-space:nowrap;font-family:'Bodoni Moda',serif;font-size:{{ $px(58) }};letter-spacing:.01em}
.mono{width:{{ $px(92) }};height:{{ $px(92) }};border-radius:50%;display:grid;place-items:center;border:1px solid var(--m2);font-family:'Cormorant Garamond',serif;font-size:{{ $px(36) }};letter-spacing:.04em}
.logo{max-height:{{ $px(70) }};max-width:{{ $px(320) }};object-fit:contain}
.agentcard{display:flex;align-items:center;gap:{{ $px(24) }}}
.portrait{border-radius:50%;object-fit:cover;object-position:50% 12%;background:radial-gradient(circle at 50% 35%,var(--m1),var(--m3))}
.legal{position:absolute;left:{{ $px(60) }};right:{{ $px(60) }};bottom:{{ $px(26) }};display:flex;justify-content:space-between;align-items:center;gap:20px;font-size:{{ $px(13) }};letter-spacing:.06em;color:var(--muted);font-weight:300}
.eho{display:flex;align-items:center;gap:8px;white-space:nowrap}
.mat{background:var(--paper);padding:{{ $px(12) }};box-shadow:0 30px 60px -20px rgba(0,0,0,.45),0 8px 16px -8px rgba(0,0,0,.3)}
.mat img{display:block;width:100%;height:100%;object-fit:cover;filter:contrast(1.06) saturate(1.04)}
.stars{letter-spacing:.3em;font-size:{{ $px(26) }}}
</style></head><body>
@php
    $eho = '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 2 10h3v11h14V10h3z" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 12h8M8 16h8" stroke="currentColor" stroke-width="1.6"/></svg>';
    $brandmark = $logo ? '<img class="logo" src="'.e($logo).'" alt="">' : '<div class="mono metal">'.e($monogram).'</div>';
    $signature = '<div><div class="sig metal" style="font-size:'.$px(52).'">'.e($name).'</div><div class="caps" style="font-size:'.$px(13).';letter-spacing:.34em;margin-top:'.$px(6).';color:var(--muted)">'.e($title !== '' ? $title : $kit['brokerage']).($phone !== '' ? ' &nbsp;·&nbsp; '.e($phone) : '').'</div></div>';
@endphp

@switch($layout)

{{-- Cover: full-bleed photograph, cinematic grading, brand mark and kicker above, editorial type below. --}}
@case('cover')
<div class="canvas grain">
    <img class="ph" src="{{ $photo(0) }}" alt="">
    <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.55) 0%,rgba(0,0,0,0) 22%,rgba(0,0,0,0) 36%,{{ $p['shade'] }} 66%,{{ $p['bg'] }} 100%)"></div>
    <div style="position:absolute;top:{{ $px(64) }};left:0;right:0;display:grid;justify-items:center;gap:{{ $px(26) }};color:#fff">
        {!! $brandmark !!}
        <div class="caps kick" style="color:#fff">{{ $c['kicker'] }}</div>
    </div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $px($story ? 300 : 230) }};display:grid;gap:{{ $px(20) }}">
        <div class="display onphoto" style="font-size:{{ $hsize }};color:#fff">{{ $hl }}</div>
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:{{ $px(30) }}">
            @if (! empty($c['sub']))<div class="ital metal" style="font-size:{{ $px(38) }}">{{ $c['sub'] }}</div>@endif
            @if (! empty($c['price']))<div class="offered"><small style="color:rgba(255,255,255,.7)">Offered at</small><span class="price metal">{{ $c['price'] }}</span></div>@endif
        </div>
        <div class="hair-l" style="margin:{{ $px(6) }} 0"></div>
        @if ($facts !== [])<div class="facts" style="color:#fff">@foreach ($facts as $f)<span style="border-color:rgba(255,255,255,.35)">{{ $f }}</span>@endforeach</div>@endif
    </div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $px($story ? 120 : 70) }};display:flex;justify-content:space-between;align-items:center;color:#fff">
        <div class="agentcard">
            @if ($agent)<img class="portrait" src="{{ $agent }}" alt="" style="width:{{ $px(96) }};height:{{ $px(96) }};box-shadow:0 0 0 1px var(--m2),0 0 0 6px rgba(0,0,0,.35)">@endif
            {!! $signature !!}
        </div>
    </div>
</div>
@break

{{-- Cinema: a letterboxed band of photograph on deep colour, like a film title card. --}}
@case('cinema')
@php $band = $square ? 470 : ($story ? 760 : 600); @endphp
<div class="canvas grain" style="background:radial-gradient(120% 80% at 50% 50%,{{ $p['bg_glow'] }} 0%,var(--bg) 70%)">
    <div style="position:absolute;top:{{ $px($story ? 150 : 70) }};left:0;right:0;display:grid;justify-items:center;gap:{{ $px(22) }}">
        {!! $brandmark !!}
        <div class="caps kick metal">{{ $c['kicker'] }}</div>
    </div>
    <div style="position:absolute;left:0;right:0;top:50%;transform:translateY(-58%);height:{{ $band }}px">
        <div class="hair" style="position:absolute;top:-14px;left:{{ $px(72) }};right:{{ $px(72) }}"></div>
        <img class="ph" src="{{ $photo(0) }}" alt="" style="position:absolute;inset:0">
        <div class="hair" style="position:absolute;bottom:-14px;left:{{ $px(72) }};right:{{ $px(72) }}"></div>
    </div>
    <div class="center" style="position:absolute;left:{{ $px(60) }};right:{{ $px(60) }};bottom:{{ $px($story ? 280 : 150) }};display:grid;justify-items:center;text-align:center;gap:{{ $px(14) }}">
        <div class="display" style="font-size:{{ $px($long ? 66 : 80) }}">{{ $hl }}</div>
        @if (! empty($c['sub']))<div class="ital metal" style="font-size:{{ $px(32) }}">{{ $c['sub'] }}</div>@endif
        <div style="display:flex;gap:{{ $px(36) }};align-items:baseline;margin-top:{{ $px(6) }}">
            @if ($facts !== [])<div class="facts" style="font-size:{{ $px(17) }}">@foreach ($facts as $f)<span>{{ $f }}</span>@endforeach</div>@endif
            @if (! empty($c['price']))<span class="price metal" style="font-size:{{ $px(44) }}">{{ $c['price'] }}</span>@endif
        </div>
    </div>
    <div style="position:absolute;left:0;right:0;bottom:{{ $px($story ? 120 : 62) }};display:flex;justify-content:center">
        <div class="agentcard">@if ($agent)<img class="portrait" src="{{ $agent }}" alt="" style="width:{{ $px(64) }};height:{{ $px(64) }};box-shadow:0 0 0 1px var(--m2)">@endif
            <div class="caps" style="font-size:{{ $px(14) }};letter-spacing:.34em">{{ $name }} @if ($phone)<span style="color:var(--muted)">&nbsp;·&nbsp;{{ $phone }}</span>@endif</div></div>
    </div>
</div>
@break

{{-- Gallery: three photographs as matted prints, hung like an exhibition. --}}
@case('gallery')
@php $top = $square ? 64 : ($story ? 200 : 90); $big = $square ? 560 : ($story ? 860 : 700); @endphp
<div class="canvas grain" style="background:{{ $dark ? 'radial-gradient(110% 70% at 30% 20%,'.$p['bg_glow'].' 0%,'.$p['bg'].' 65%)' : 'linear-gradient(180deg,'.$p['paper'].','.$p['bg'].')' }}">
    <div class="mat" style="position:absolute;left:{{ $px(64) }};top:{{ $px($top) }};width:{{ $px(640) }};height:{{ $px($big) }}"><img src="{{ $photo(0) }}" alt=""></div>
    <div class="mat" style="position:absolute;right:{{ $px(64) }};top:{{ $px($top + 40) }};width:{{ $px(300) }};height:{{ $px(($big - 70) / 2) }}"><img src="{{ $photo(1) }}" alt=""></div>
    <div class="mat" style="position:absolute;right:{{ $px(64) }};top:{{ $px($top + 70 + ($big - 70) / 2) }};width:{{ $px(300) }};height:{{ $px(($big - 70) / 2) }}"><img src="{{ $photo(2) }}" alt=""></div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};top:{{ $px($top + $big + ($story ? 90 : 56)) }};display:grid;gap:{{ $px(14) }}">
        <div class="caps metal" style="font-size:{{ $px(17) }}">{{ $c['kicker'] }}</div>
        <div class="display" style="font-size:{{ $px($long ? 64 : 78) }}">{{ $hl }}</div>
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:{{ $px(24) }}">
            <div class="ital" style="font-size:{{ $px(30) }};color:var(--muted)">{{ $c['sub'] ?? '' }}</div>
            @if (! empty($c['price']))<span class="price metal" style="font-size:{{ $px(46) }}">{{ $c['price'] }}</span>@endif
        </div>
        @if ($facts !== [])<div class="hair-l" style="margin-top:{{ $px(6) }}"></div><div class="facts" style="font-size:{{ $px(16) }}">@foreach ($facts as $f)<span>{{ $f }}</span>@endforeach</div>@endif
    </div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $px($story ? 110 : 62) }};display:flex;justify-content:space-between;align-items:center">
        <div class="agentcard">@if ($agent)<img class="portrait" src="{{ $agent }}" alt="" style="width:{{ $px(80) }};height:{{ $px(80) }};box-shadow:0 0 0 1px var(--m2)">@endif {!! $signature !!}</div>
        {!! $brandmark !!}
    </div>
</div>
@break

{{-- Frame: a single photograph in a fine double frame with corner ornaments, centred classical type. --}}
@case('frame')
@php $ph = $square ? 520 : ($story ? 900 : 700); @endphp
<div class="canvas grain" style="background:var(--bg)">
    <div style="position:absolute;inset:{{ $px(30) }};border:1px solid var(--m2)"></div>
    <div style="position:absolute;inset:{{ $px(40) }};border:1px solid var(--rule)"></div>
    @foreach ([['top', 'left'], ['top', 'right'], ['bottom', 'left'], ['bottom', 'right']] as [$v, $hz])
        <div style="position:absolute;{{ $v }}:{{ $px(24) }};{{ $hz }}:{{ $px(24) }};width:12px;height:12px;transform:rotate(45deg);background:var(--m2)"></div>
    @endforeach
    <div style="position:absolute;top:{{ $px($story ? 150 : 96) }};left:{{ $px(96) }};right:{{ $px(96) }};height:{{ $px($ph) }};overflow:hidden;box-shadow:0 24px 50px -24px rgba(0,0,0,.4)"><img class="ph" src="{{ $photo(0) }}" alt=""></div>
    <div class="center" style="position:absolute;left:{{ $px(96) }};right:{{ $px(96) }};top:{{ $px(($story ? 150 : 96) + $ph + ($story ? 90 : 46)) }};display:grid;justify-items:center;text-align:center;gap:{{ $px(14) }}">
        <div class="caps kick metal" style="font-size:{{ $px(17) }}">{{ $c['kicker'] }}</div>
        <div class="display" style="font-size:{{ $px($long ? 62 : 76) }}">{{ $hl }}</div>
        @if (! empty($c['sub']))<div class="ital" style="font-size:{{ $px(30) }};color:var(--muted)">{{ $c['sub'] }}</div>@endif
        <div class="metal" style="font-size:{{ $px(18) }}">◆</div>
        @if ($facts !== [])<div class="facts" style="font-size:{{ $px(16) }}">@foreach ($facts as $f)<span>{{ $f }}</span>@endforeach</div>@endif
        @if (! empty($c['price']))<div class="offered"><small>Offered at</small><span class="price metal" style="font-size:{{ $px(46) }}">{{ $c['price'] }}</span></div>@endif
    </div>
    <div style="position:absolute;left:{{ $px(96) }};right:{{ $px(96) }};bottom:{{ $px($story ? 140 : 96) }};display:flex;justify-content:space-between;align-items:center">
        <div class="agentcard">@if ($agent)<img class="portrait" src="{{ $agent }}" alt="" style="width:{{ $px(78) }};height:{{ $px(78) }};box-shadow:0 0 0 1px var(--m2)">@endif {!! $signature !!}</div>
        {!! $brandmark !!}
    </div>
</div>
@break

{{-- Arch: the photograph in an arched window with an offset gilt outline; airy and architectural. --}}
@case('arch')
@php $aw = $square ? 600 : 700; $ah = $square ? 600 : ($story ? 1000 : 780); @endphp
<div class="canvas grain" style="background:linear-gradient(180deg,var(--paper),var(--bg))">
    <div style="position:absolute;left:50%;top:{{ $px($story ? 150 : 80) }};transform:translateX(-50%);width:{{ $px($aw) }};height:{{ $px($ah) }}">
        <div style="position:absolute;inset:0;transform:translate(18px,18px);border:1px solid var(--m2);border-radius:{{ $px($aw / 2) }} {{ $px($aw / 2) }} 0 0"></div>
        <div style="position:absolute;inset:0;overflow:hidden;border-radius:{{ $px($aw / 2) }} {{ $px($aw / 2) }} 0 0;box-shadow:0 30px 60px -30px rgba(0,0,0,.45)"><img class="ph" src="{{ $photo(0) }}" alt=""></div>
    </div>
    <div class="center" style="position:absolute;left:{{ $px(80) }};right:{{ $px(80) }};top:{{ $px(($story ? 150 : 80) + $ah + ($story ? 90 : 50)) }};display:grid;justify-items:center;text-align:center;gap:{{ $px(12) }}">
        <div class="caps kick metal" style="font-size:{{ $px(16) }}">{{ $c['kicker'] }}</div>
        <div class="display" style="font-size:{{ $px($long ? 58 : 72) }}">{{ $hl }}</div>
        <div style="display:flex;gap:{{ $px(28) }};align-items:baseline">
            @if (! empty($c['sub']))<span class="ital" style="font-size:{{ $px(28) }};color:var(--muted)">{{ $c['sub'] }}</span>@endif
            @if (! empty($c['price']))<span class="price metal" style="font-size:{{ $px(40) }}">{{ $c['price'] }}</span>@endif
        </div>
        @if ($facts !== [])<div class="facts" style="font-size:{{ $px(15) }}">@foreach ($facts as $f)<span>{{ $f }}</span>@endforeach</div>@endif
    </div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $px($story ? 120 : 60) }};display:flex;justify-content:space-between;align-items:center">
        <div class="agentcard">@if ($agent)<img class="portrait" src="{{ $agent }}" alt="" style="width:{{ $px(74) }};height:{{ $px(74) }};box-shadow:0 0 0 1px var(--m2)">@endif {!! $signature !!}</div>
        {!! $brandmark !!}
    </div>
</div>
@break

{{-- Masthead: a magazine cover, the moment as the masthead across the photograph. --}}
@case('masthead')
<div class="canvas grain">
    <img class="ph" src="{{ $photo(0) }}" alt="">
    <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.45) 0%,rgba(0,0,0,0) 30%,rgba(0,0,0,0) 50%,rgba(0,0,0,.72) 100%)"></div>
    <div style="position:absolute;top:{{ $px($story ? 120 : 56) }};left:0;right:0;text-align:center;color:#fff">
        <div style="font-family:'Italiana',serif;font-size:{{ $px(mb_strlen((string) $c['kicker']) > 12 ? 104 : 136) }};letter-spacing:.14em;text-transform:uppercase;line-height:1">{{ $c['kicker'] }}</div>
        <div class="caps" style="font-size:{{ $px(14) }};letter-spacing:.5em;margin-top:{{ $px(14) }}">{{ $kit['brokerage'] }} &nbsp;·&nbsp; {{ $c['sub'] ?? '' }}</div>
    </div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $px($story ? 260 : 170) }};color:#fff;display:grid;gap:{{ $px(16) }}">
        <div class="bodoni onphoto" style="font-style:italic;font-size:{{ $px($long ? 64 : 80) }};line-height:1">{{ $hl }}</div>
        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:24px">
            @if ($facts !== [])<div class="facts" style="font-size:{{ $px(17) }}">@foreach ($facts as $f)<span style="border-color:rgba(255,255,255,.4)">{{ $f }}</span>@endforeach</div>@endif
            @if (! empty($c['price']))<span class="price metal" style="font-size:{{ $px(50) }}">{{ $c['price'] }}</span>@endif
        </div>
    </div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $px($story ? 110 : 60) }};display:flex;justify-content:space-between;align-items:center;color:#fff">
        <div class="agentcard">@if ($agent)<img class="portrait" src="{{ $agent }}" alt="" style="width:{{ $px(70) }};height:{{ $px(70) }};box-shadow:0 0 0 1px var(--m2)">@endif {!! $signature !!}</div>
        {!! $brandmark !!}
    </div>
</div>
@break

{{-- Split: photograph above, an elegant panel below with the agent's portrait set into an arch. --}}
@case('split')
@php $cut = $square ? 0.52 : ($story ? 0.58 : 0.56); @endphp
<div class="canvas grain">
    <div style="position:absolute;top:0;left:0;right:0;height:{{ round($h * $cut) }}px;overflow:hidden"><img class="ph" src="{{ $photo(0) }}" alt=""></div>
    <div class="hair" style="position:absolute;top:{{ round($h * $cut) }}px;left:0;right:0"></div>
    <div class="caps" style="position:absolute;top:{{ $px(52) }};left:{{ $px(60) }};color:#fff;text-shadow:0 2px 12px rgba(0,0,0,.5)">{{ $c['kicker'] }}</div>
    @if ($agent)
        <div style="position:absolute;right:{{ $px(64) }};top:{{ round($h * $cut) - round(150 * $u) }}px;width:{{ $px(250) }};height:{{ $px(320) }};border-radius:{{ $px(125) }} {{ $px(125) }} 0 0;overflow:hidden;background:radial-gradient(circle at 50% 30%,var(--m1),var(--m3));box-shadow:0 0 0 1px var(--m2),0 0 0 10px var(--bg)">
            <img src="{{ $agent }}" alt="" style="width:100%;height:100%;object-fit:cover;object-position:50% 10%">
        </div>
    @endif
    <div style="position:absolute;top:{{ round($h * $cut) + round(($square ? 56 : 96) * $u) }}px;left:{{ $px(72) }};right:{{ $agent ? $px(360) : $px(72) }};display:grid;gap:{{ $px(16) }}">
        <div class="display" style="font-size:{{ $px($long ? 60 : 76) }}">{{ $hl }}</div>
        @if (! empty($c['sub']))<div class="ital metal" style="font-size:{{ $px(32) }}">{{ $c['sub'] }}</div>@endif
    </div>
    <div style="position:absolute;left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $px($story ? 150 : 70) }};display:grid;gap:{{ $px(20) }}">
        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:20px">
            @if ($facts !== [])<div class="facts" style="font-size:{{ $px(16) }}">@foreach ($facts as $f)<span>{{ $f }}</span>@endforeach</div>@endif
            @if (! empty($c['price']))<span class="price metal" style="font-size:{{ $px(48) }}">{{ $c['price'] }}</span>@endif
        </div>
        <div class="hair-l"></div>
        <div style="display:flex;justify-content:space-between;align-items:center">{!! $signature !!} {!! $brandmark !!}</div>
    </div>
</div>
@break

{{-- Statement: text and agent moments. Typography as the image: metallic numerals, a pull quote, or the agent's portrait in an arch. --}}
@default
@php $isAgent = ($type ?? '') === 'agent' && $agent; $isQuote = ($c['moment'] ?? '') === 'testimonial'; $isHoliday = ($c['moment'] ?? '') === 'holiday'; @endphp
<div class="canvas grain" style="background:{{ $dark ? 'radial-gradient(100% 70% at 70% 30%,'.$p['bg_glow'].' 0%,'.$p['bg'].' 70%)' : 'radial-gradient(110% 80% at 30% 20%,'.$p['paper'].' 0%,'.$p['bg'].' 75%)' }}">
    <div style="position:absolute;inset:{{ $px(34) }};border:1px solid var(--rule)"></div>
    <div style="position:absolute;top:{{ $px($story ? 130 : 80) }};left:{{ $px(80) }};right:{{ $px(80) }};display:flex;justify-content:{{ $isHoliday ? 'center' : 'space-between' }};align-items:center">
        {!! $brandmark !!}
        @if (! $isHoliday)<div class="caps metal" style="font-size:{{ $px(16) }}">{{ $c['kicker'] }}</div>@endif
    </div>
    @if ($isAgent)
        <div style="position:absolute;right:{{ $px(80) }};bottom:{{ $px($story ? 420 : 150) }};width:{{ $px($square ? 380 : 430) }};height:{{ $px($square ? 520 : ($story ? 760 : 620)) }}">
            <div style="position:absolute;inset:0;transform:translate(-18px,-18px);border:1px solid var(--m2);border-radius:{{ $px(215) }} {{ $px(215) }} 0 0"></div>
            <div style="position:absolute;inset:0;border-radius:{{ $px(215) }} {{ $px(215) }} 0 0;overflow:hidden;background:radial-gradient(circle at 50% 30%,var(--m1),var(--m3))"><img src="{{ $agent }}" alt="" style="position:absolute;bottom:0;left:50%;transform:translateX(-50%);height:96%;object-fit:contain"></div>
        </div>
    @endif
    <div class="{{ $isHoliday ? 'center' : '' }}" style="position:absolute;left:{{ $px(80) }};right:{{ $isAgent ? $px($square ? 500 : 560) : $px(80) }};top:50%;transform:translateY(-52%);display:grid;gap:{{ $px(24) }};{{ $isHoliday ? 'justify-items:center;text-align:center' : '' }}">
        @if ($isQuote)
            <div class="display metal" style="font-size:{{ $px(240) }};line-height:.75;height:{{ $px(120) }};font-weight:500">&ldquo;</div>
            <div class="stars metal">★★★★★</div>
            <div class="ital" style="font-size:{{ $px(mb_strlen((string) ($c['body'] ?? '')) > 160 ? 40 : 50) }};line-height:1.22">{{ $c['body'] ?? $hl }}</div>
            @if (! empty($c['by']))<div class="caps" style="font-size:{{ $px(16) }};color:var(--muted)">— {{ $c['by'] }}</div>@endif
        @elseif ($isHoliday)
            <div class="metal" style="font-size:{{ $px(22) }}">◆ &nbsp; ◆ &nbsp; ◆</div>
            <div class="sig metal" style="font-size:{{ $px(mb_strlen($hl) > 18 ? 120 : 150) }}">{{ $hl }}</div>
            @if (! empty($c['body']))<div class="ital" style="font-size:{{ $px(38) }};color:var(--muted);max-width:{{ $px(760) }}">{{ $c['body'] }}</div>@endif
        @else
            <div class="display" style="font-size:{{ $px($isAgent ? 78 : 96) }}">{{ $hl }}</div>
            <div class="hair-l" style="width:{{ $px(220) }}"></div>
            @if (! empty($c['stats']))
                <div style="display:flex;gap:0;margin-top:{{ $px(10) }}">
                    @foreach ($c['stats'] as $i => $st)
                        <div style="padding:0 {{ $px(34) }};{{ $i > 0 ? 'border-left:1px solid var(--rule)' : 'padding-left:0' }}">
                            <div class="bodoni metal" style="font-size:{{ $px(82) }};line-height:1">{{ $st['value'] }}</div>
                            <div class="caps" style="font-size:{{ $px(13) }};letter-spacing:.3em;margin-top:{{ $px(12) }};color:var(--muted)">{{ $st['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if (! empty($c['body']))<div class="ital" style="font-size:{{ $px(38) }};line-height:1.25;color:var(--ink)">{{ $c['body'] }}</div>@endif
        @endif
    </div>
    <div style="position:absolute;left:{{ $px(80) }};bottom:{{ $px($story ? 150 : 104) }};display:flex;align-items:center;gap:{{ $px(22) }}">
        @if ($agent && ! $isAgent)<img class="portrait" src="{{ $agent }}" alt="" style="width:{{ $px(76) }};height:{{ $px(76) }};box-shadow:0 0 0 1px var(--m2)">@endif
        {!! $signature !!}
    </div>
</div>
@endswitch

<div class="legal" style="{{ in_array($layout, ['cover', 'masthead'], true) ? 'color:rgba(255,255,255,.62)' : '' }}{{ in_array($layout, ['frame', 'statement'], true) || ! in_array($layout, ['cover', 'cinema', 'gallery', 'frame', 'arch', 'masthead', 'split'], true) ? ';left:'.$px(80).';right:'.$px(80).';bottom:'.$px(52) : '' }}">
    <span>{{ $legal }}</span>
    <span class="eho">{!! $eho !!} Equal Housing Opportunity</span>
</div>
</body></html>
