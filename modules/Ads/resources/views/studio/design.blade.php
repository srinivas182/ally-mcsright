{{--
    Ally Studio design, v3 (specs/grow/13, SA1e): photo-first, the way the leading luxury brokerages post. One page per
    size and slide, rendered by headless Chrome. Inputs: $w, $h, $layout (the cover layout), $slide (cover | photo |
    details), $n and $total (slide number and count), $type (listing | text | agent), $t (style tokens), $p (palette),
    $kit, $c (content), $fonts (@font-face CSS), $photos (URLs, best first), $headshot and $cutout (agent photos),
    $logoLight (for photos and dark grounds) and $logoInk (for light grounds), $monogram. Everything is escaped; no scripts.

    Rules of the house: the photograph is the hero; one short line on the cover; the price and details live on the last
    slide and in the caption; at most two type families; generous space; the brokerage and Equal Housing on every image.
    The story size keeps clear of the app's top and bottom bars.
--}}
@php
    $story = $h >= 1900;
    $square = $h <= 1100;
    $u = $square ? 0.92 : ($story ? 1.06 : 1.0);
    $px = fn (float $n): string => round($n * $u).'px';
    $safeT = $story ? 200 : 0; // Stories: clear of the progress bar and name
    $safeB = $story ? 250 : 0; // and of the reply bar
    $top = fn (float $n): string => round($n * $u + $safeT).'px';
    $bot = fn (float $n): string => round($n * $u + $safeB).'px';
    $hh = fn (float $f): int => (int) round($h * $f);
    $hl = (string) ($c['headline'] ?? '');
    $len = mb_strlen($hl);
    $fit = fn (float $size, int $at = 20): string => $px($len > $at ? max($size * 0.55, $size * $at / $len * 1.1) : $size);
    $kicker = (string) ($c['kicker'] ?? '');
    $sub = (string) ($c['sub'] ?? '');
    $city = trim((string) preg_replace('/\s+\d{5}(-\d{4})?$/', '', $sub));
    $price = (string) ($c['price'] ?? '');
    $facts = array_values(array_filter(array_map('strval', (array) ($c['facts'] ?? []))));
    $photo = fn (int $i): ?string => $photos[$i] ?? $photos[0] ?? null;
    $name = (string) ($kit['details']['display_name'] ?? '');
    $title = (string) ($kit['details']['title'] ?? '');
    $phone = (string) ($kit['details']['phone'] ?? '');
    $legal = implode(' · ', array_filter([! empty($c['enhanced']) ? 'Photos virtually enhanced' : null, $c['attribution'] ?? null, $kit['details']['disclaimer'] ?? null, $kit['details']['licence_line'] ?? null, $kit['brokerage']]));
    $dark = $p['dark'];
    $agent = $headshot ?? $cutout;
    $initials = mb_strtoupper(implode('', array_map(fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2))));
    $nn = str_pad((string) ($n ?? 1), 2, '0', STR_PAD_LEFT);
    // On a photograph the legal line is white; on a ground it takes the ground's muted ink.
    $legalOnPhoto = $slide === 'photo' ? (string) $t['slide'] === 'bleed' : ($slide === 'cover' && in_array($layout, ['gallery', 'frosted', 'monograph', 'seal', 'wide'], true));
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<style>
{!! $fonts !!}
:root{--display:{!! $t['display'] !!};--dw:{{ $t['dw'] }};--ds:{{ $t['ds'] }};--dt:{{ $t['dt'] }};--dl:{{ $t['dl'] }};--sans:{!! $t['sans'] !!};--italic:{!! $t['italic'] !!};--grade:{{ $grade }};--r:{{ $t['radius'] }}px;
  --bg:{{ $p['bg'] }};--paper:{{ $p['paper'] }};--ink:{{ $p['fg'] }};--muted:{{ $p['muted'] }};--rule:{{ $p['rule'] }};--accent:{{ $p['metal'] }};--field:{{ $p['field'] }}}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:{{ $w }}px;height:{{ $h }}px;overflow:hidden}
body{position:relative;background:var(--bg);color:var(--ink);font-family:var(--sans);font-weight:400;-webkit-font-smoothing:antialiased;text-rendering:geometricPrecision}
.abs{position:absolute}
.ph{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:var(--grade) url(#sharpen)}
.pic{display:block;object-fit:cover;filter:var(--grade) url(#sharpen)}
.cap{font-family:var(--sans);font-weight:500;text-transform:uppercase;letter-spacing:.3em;font-size:{{ $px(16) }}}
.display{font-family:var(--display);font-weight:var(--dw);font-style:var(--ds);text-transform:var(--dt);letter-spacing:var(--dl);line-height:1}
.ital{font-family:var(--italic);font-style:italic;font-weight:400}
.light{color:#fff}
.shadow{text-shadow:0 2px 24px rgba(0,0,0,.35)}
.logo{display:block;max-height:{{ $px(38) }};max-width:{{ $px(300) }};object-fit:contain}
.legal{position:absolute;left:{{ $px(56) }};right:{{ $px(56) }};bottom:{{ round(22 * $u + $safeB * 0.5) }}px;display:flex;justify-content:center;align-items:center;gap:{{ $px(10) }};
  font:400 {{ $px(13) }}/1.2 var(--sans);letter-spacing:.06em;text-align:center;color:{{ $legalOnPhoto ? 'rgba(255,255,255,.72)' : 'var(--muted)' }}}
.legal svg{flex:none}
.portrait{border-radius:50%;object-fit:cover;object-position:50% 18%}
.pill{display:inline-block;border-radius:999px;padding:{{ $px(16) }} {{ $px(26) }};font:500 {{ $px(17) }}/1 var(--sans);letter-spacing:.16em;text-transform:uppercase;white-space:nowrap}
.grid4{display:grid;grid-template-columns:repeat(4,1fr)}
.facts{display:flex;flex-wrap:wrap;gap:0 {{ $px(14) }};font:400 {{ $px(19) }}/1.4 var(--sans);letter-spacing:.1em;text-transform:uppercase}
.facts span+span:before{content:"·";margin-right:{{ $px(14) }};opacity:.7}
</style></head><body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><filter id="sharpen"><feConvolveMatrix order="3" preserveAlpha="true" kernelMatrix="0 -.3 0 -.3 2.2 -.3 0 -.3 0"/></filter></svg>
@php
    $eho = '<svg width="'.round(15 * $u).'" height="'.round(15 * $u).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/><path d="M9 14h6M9 17h6"/></svg>';
    $mark = fn (bool $onLight, float $size = 38): string => ($onLight ? ($logoInk ?? $logoLight) : ($logoLight ?? $logoInk))
        ? '<img class="logo" src="'.e((string) ($onLight ? ($logoInk ?? $logoLight) : ($logoLight ?? $logoInk))).'" alt="" style="max-height:'.$px($size).($onLight ? '' : ';filter:drop-shadow(0 2px 10px rgba(0,0,0,.3))').'">'
        : '<div class="cap" style="font-size:'.$px(15).';letter-spacing:.34em">'.e((string) $kit['brokerage']).'</div>';
    $avatar = fn (float $size, string $ring): string => $agent
        ? '<img class="portrait" src="'.e((string) $agent).'" alt="" style="width:'.$px($size).';height:'.$px($size).';box-shadow:0 0 0 '.$px(2).' '.$ring.'">'
        : '<div style="width:'.$px($size).';height:'.$px($size).';border-radius:50%;border:1px solid '.$ring.';display:grid;place-items:center;font:400 '.$px($size * 0.38).'/1 var(--display);color:var(--accent)">'.e($initials !== '' ? $initials : 'R').'</div>';
    $who = fn (string $color, string $muted): string => '<div><div class="display" style="font-size:'.$px(34).';text-transform:none;letter-spacing:0;color:'.$color.'">'.e($name).'</div>'
        .'<div style="font:400 '.$px(15).'/1 var(--sans);letter-spacing:.14em;text-transform:uppercase;margin-top:'.$px(10).';color:'.$muted.'">'.e(implode(' · ', array_filter([$title !== '' ? $title : 'REALTOR®', $phone]))).'</div></div>';
@endphp

@if ($slide === 'photo')
{{-- Inner carousel slide: one photograph. Bleed styles fill the frame; framed styles hang it on the ground. --}}
    @if ($t['slide'] === 'bleed')
        <img class="ph" src="{{ $photo(0) }}" alt="">
        <div class="abs" style="inset:0;background:linear-gradient(180deg,rgba(0,0,0,0) 62%,rgba(0,0,0,.5) 100%)"></div>
        <div class="abs cap light shadow" style="left:{{ $px(64) }};bottom:{{ $bot(72) }}">{{ $nn }} — {{ $city !== '' ? $city : $kit['brokerage'] }}</div>
        <div class="abs" style="right:{{ $px(64) }};bottom:{{ $bot(66) }}">{!! $mark(false, 28) !!}</div>
    @else
        <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ $top(56) }};left:{{ $px(56) }};width:{{ $w - round(112 * $u) }}px;height:{{ $h - round(250 * $u) - $safeT - $safeB }}px;border-radius:{{ round($t['radius'] * 0.6) }}px">
        <div class="abs" style="left:{{ $px(56) }};right:{{ $px(56) }};bottom:{{ $bot(76) }};display:flex;justify-content:space-between;align-items:center">
            <div class="cap" style="color:var(--muted)">{{ $nn }} &nbsp;/&nbsp; {{ $hl }}</div>
            {!! $mark(! $dark, 26) !!}
        </div>
    @endif

@elseif ($slide === 'details')
{{-- Last carousel slide: the facts, the price and the agent, with one call to action. --}}
    <div class="abs" style="inset:0;background:{{ $dark ? 'var(--bg)' : 'var(--paper)' }}"></div>
    <div class="abs" style="top:{{ $top(96) }};left:{{ $px(72) }};right:{{ $px(72) }};bottom:{{ $bot(76) }};display:flex;flex-direction:column">
        <div class="cap" style="color:var(--muted)">{{ $kicker }}@if ($city !== '') · {{ $city }}@endif</div>
        <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:{{ $px(30) }};margin-top:{{ $px(26) }}">
            <div class="display" style="font-size:{{ $fit(76, 22) }};line-height:1.02">{{ $hl }}</div>
            @if ($price !== '')<div class="display" style="font-size:{{ $px(52) }};color:var(--accent);text-transform:none;letter-spacing:0;white-space:nowrap">{{ $price }}</div>@endif
        </div>
        @if ($facts !== [])
            <div style="display:grid;grid-template-columns:repeat({{ count($facts) }},1fr);margin-top:{{ $px(34) }};border-top:1px solid var(--rule);padding-top:{{ $px(24) }}">
                @foreach ($facts as $f)
                    @php $parts = explode(' ', $f, 2); @endphp
                    <div><div class="display" style="font-size:{{ $px(52) }};text-transform:none;letter-spacing:0">{{ $parts[0] }}</div>
                        <div style="font:400 {{ $px(14) }}/1 var(--sans);letter-spacing:.16em;text-transform:uppercase;color:var(--muted);margin-top:{{ $px(10) }}">{{ $parts[1] ?? '' }}</div></div>
                @endforeach
            </div>
        @endif
        @if ($photo(0))
            <div style="flex:1;min-height:0;margin-top:{{ $px(36) }};border-radius:{{ round($t['radius'] * 0.6) }}px;overflow:hidden;position:relative"><img class="ph" src="{{ $photo(0) }}" alt=""></div>
        @else
            <div style="flex:1"></div>
        @endif
        @if (! empty($c['body']))<div class="ital" style="font-size:{{ $px(30) }};line-height:1.3;margin-top:{{ $px(28) }};color:var(--ink)">{{ $c['body'] }}</div>@endif
        <div style="display:flex;align-items:center;gap:{{ $px(24) }};margin-top:{{ $px(34) }}">
            {!! $avatar(96, 'var(--accent)') !!}
            <div style="flex:1">{!! $who('var(--ink)', 'var(--muted)') !!}</div>
            <div style="display:grid;justify-items:end;gap:{{ $px(16) }}"><div class="pill" style="border:1px solid var(--accent);color:var(--accent)">Book a tour</div>{!! $mark(! $dark, 24) !!}</div>
        </div>
    </div>

@elseif ($layout === 'statement')
{{-- Words, numbers, quotes and the agent: typography on the ground; the agent's cut-out portrait when it's about them. --}}
@php $isAgent = $type === 'agent' && ($cutout || $headshot); $isQuote = ($c['moment'] ?? '') === 'testimonial'; @endphp
    <div class="abs" style="inset:0;background:{{ $dark ? 'var(--bg)' : 'var(--paper)' }}"></div>
    @if ($isAgent)
        <div class="abs" style="right:0;bottom:0;width:{{ round($w * 0.5) }}px;height:{{ $hh($square ? 0.8 : 0.72) }}px;background:{{ $dark ? 'var(--paper)' : 'var(--bg)' }};border-radius:{{ round($w * 0.25) }}px {{ round($w * 0.25) }}px 0 0;overflow:hidden">
            <img src="{{ $cutout ?? $headshot }}" alt="" style="position:absolute;bottom:0;left:50%;transform:translateX(-50%);height:{{ $cutout ? '94%' : '100%' }};{{ $cutout ? 'object-fit:contain' : 'width:100%;object-fit:cover;object-position:50% 15%' }}">
        </div>
    @endif
    <div class="abs" style="top:{{ $top(84) }};left:{{ $px(72) }};right:{{ $px(72) }};display:flex;justify-content:space-between;align-items:center">
        {!! $mark(! $dark, 32) !!}<div class="cap" style="color:var(--muted)">{{ $kicker }}</div>
    </div>
    <div class="abs" style="left:{{ $px(72) }};right:{{ $isAgent ? round($w * 0.5) + 20 .'px' : $px(72) }};top:{{ $isAgent ? $hh(0.2) + $safeT * 0.5 .'px' : '50%' }};{{ $isAgent ? '' : 'transform:translateY(-54%)' }}">
        @if ($isQuote)
            <div class="display" style="font-size:{{ $px(200) }};line-height:.7;height:{{ $px(100) }};color:var(--accent)">&ldquo;</div>
            <div class="ital" style="font-size:{{ $px(mb_strlen((string) ($c['body'] ?? '')) > 160 ? 40 : 50) }};line-height:1.24;margin-top:{{ $px(20) }}">{{ $c['body'] ?? $hl }}</div>
            @if (! empty($c['by']))<div class="cap" style="margin-top:{{ $px(36) }};color:var(--muted)">— {{ $c['by'] }}</div>@endif
        @else
            <div class="display" style="font-size:{{ $fit($isAgent ? 84 : 112, $isAgent ? 14 : 18) }};line-height:1.02">{{ $hl }}</div>
            @if (! empty($c['stats']))
                <div style="display:flex;margin-top:{{ $px(56) }};border-top:1px solid var(--rule);padding-top:{{ $px(30) }}">
                    @foreach ($c['stats'] as $i => $st)
                        <div style="flex:1;{{ $i > 0 ? 'padding-left:'.$px(28).';border-left:1px solid var(--rule)' : '' }}">
                            <div class="display" style="font-size:{{ $px(72) }};color:var(--accent);text-transform:none;letter-spacing:-.02em">{{ $st['value'] }}</div>
                            <div style="font:400 {{ $px(14) }}/1.3 var(--sans);letter-spacing:.16em;text-transform:uppercase;color:var(--muted);margin-top:{{ $px(12) }}">{{ $st['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if (! empty($c['body']))<div class="ital" style="font-size:{{ $px(36) }};line-height:1.3;margin-top:{{ $px(40) }};color:var(--ink)">{{ $c['body'] }}</div>@endif
        @endif
    </div>
    <div class="abs" style="left:{{ $px(72) }};bottom:{{ $bot(96) }};display:flex;align-items:center;gap:{{ $px(22) }}">
        @unless ($isAgent){!! $avatar(84, 'var(--accent)') !!}@endunless
        {!! $who('var(--ink)', 'var(--muted)') !!}
    </div>

@else
{{-- Covers: the sixteen signature layouts. --}}
@switch($layout)

@case('frosted')
    <img class="ph" src="{{ $photo(0) }}" alt="">
    <div class="abs" style="inset:0;background:linear-gradient(180deg,rgba(0,0,0,.32) 0%,rgba(0,0,0,0) 18%)"></div>
    <div class="abs" style="top:{{ $top(58) }};left:0;right:0;display:flex;justify-content:center">{!! $mark(false, 38) !!}</div>
    <div class="abs light" style="left:{{ $px(52) }};right:{{ $px(52) }};bottom:{{ $bot(80) }};padding:{{ $px(38) }} {{ $px(44) }};border-radius:{{ $px(30) }};background:rgba(18,18,18,.3);backdrop-filter:blur(30px) saturate(.85);border:1px solid rgba(255,255,255,.3)">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:{{ $px(20) }}">
            <div style="display:flex;align-items:center;gap:{{ $px(22) }}">@if ($agent){!! $avatar(76, 'rgba(255,255,255,.7)') !!}@endif<div class="display" style="font-size:{{ $px(60) }}">{{ $kicker }}</div></div>
            @if ($price !== '')<div style="font:500 {{ $px(38) }}/1 var(--sans);letter-spacing:-.01em;white-space:nowrap">{{ $price }}</div>@endif
        </div>
        <div style="height:1px;background:rgba(255,255,255,.4);margin:{{ $px(24) }} 0"></div>
        <div style="display:flex;justify-content:space-between;gap:{{ $px(20) }};font:400 {{ $px(20) }}/1.3 var(--sans);letter-spacing:.02em">
            <span>{{ $hl }}{{ $city !== '' ? ', '.explode(',', $city)[0] : '' }}</span><span style="white-space:nowrap">{{ implode(' · ', $facts) }}</span></div>
    </div>
@break

@case('mat')
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ $top(64) }};left:{{ $px(64) }};width:{{ $w - round(128 * $u) }}px;height:{{ $h - round(420 * $u) - $safeT - $safeB }}px">
    <div class="abs" style="left:0;right:0;bottom:{{ $bot(132) }};text-align:center">
        <div class="cap" style="color:var(--muted);letter-spacing:.4em">{{ $kicker }}</div>
        <div class="display" style="font-size:{{ $fit(58, 26) }};margin-top:{{ $px(18) }}">{{ $hl }}</div>
        <div style="font:400 {{ $px(17) }}/1 var(--sans);letter-spacing:.14em;margin-top:{{ $px(20) }};color:var(--muted);text-transform:uppercase">{{ implode('   ·   ', array_filter([$city, $price])) }}</div>
    </div>
    <div class="abs" style="right:{{ $px(64) }};bottom:{{ $bot(58) }}">{!! $mark(true, 26) !!}</div>
@break

@case('monograph')
    <img class="ph" src="{{ $photo(0) }}" alt="">
    <div class="abs" style="inset:0;background:linear-gradient(180deg,rgba(0,0,0,.32) 0%,rgba(0,0,0,0) 20%,rgba(0,0,0,0) 46%,rgba(0,0,0,.66) 100%)"></div>
    <div class="abs" style="top:{{ $top(58) }};left:{{ $px(64) }}">{!! $mark(false, 34) !!}</div>
    @if ($city !== '')<div class="abs cap light" style="top:{{ $top(70) }};right:{{ $px(64) }}">{{ $city }}</div>@endif
    @php $words = preg_split('/\s+/', $kicker) ?: [$kicker]; @endphp
    <div class="abs display light" style="left:{{ $px(50) }};right:{{ $px(40) }};bottom:{{ $bot($square ? 120 : 150) }};font-size:{{ $px(mb_strlen($kicker) > 14 ? 150 : 196) }};line-height:.86">{{ $words[0] }}@if (count($words) > 1)<br><i>{{ implode(' ', array_slice($words, 1)) }}</i>@endif</div>
    <div class="abs light" style="left:{{ $px(64) }};right:{{ $px(64) }};bottom:{{ $bot(82) }};display:flex;justify-content:space-between;gap:{{ $px(20) }};font:400 {{ $px(20) }}/1 var(--sans);letter-spacing:.08em;text-transform:uppercase">
        <span>{{ $hl }}</span><span>{{ $price }}</span></div>
@break

@case('swiss')
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:0;left:0;width:{{ $w }}px;height:{{ $h - round(($square ? 330 : ($len > 16 ? 470 : 400)) * $u) - $safeB }}px">
    <div class="abs" style="bottom:{{ $bot(76) }};left:{{ $px(64) }};right:{{ $px(64) }}">
        <div style="display:flex;justify-content:space-between;align-items:center"><div class="cap" style="font-size:{{ $px(14) }};color:var(--muted)">{{ $kicker }}@if ($city !== '') — {{ $city }}@endif</div>{!! $mark(! $dark, 28) !!}</div>
        <div class="display" style="font-size:{{ $fit($square ? 76 : 92, 16) }};font-stretch:125%;line-height:.95;margin-top:{{ $px(24) }}">{{ $hl }}</div>
        <div class="grid4" style="border-top:1px solid var(--rule);margin-top:{{ $px(30) }};padding-top:{{ $px(18) }};font:400 {{ $px(14) }}/1.5 var(--sans);color:var(--muted);letter-spacing:.12em;text-transform:uppercase">
            @if ($price !== '')<div>Price<br><b style="font:600 {{ $px(26) }}/1.5 var(--sans);color:var(--ink);letter-spacing:0">{{ $price }}</b></div>@endif
            @foreach (array_slice($facts, 0, $price !== '' ? 3 : 4) as $f)@php $parts = explode(' ', $f, 2); @endphp<div>{{ $parts[1] ?? '' }}<br><b style="font:600 {{ $px(26) }}/1.5 var(--sans);color:var(--ink);letter-spacing:0">{{ $parts[0] }}</b></div>@endforeach
        </div>
    </div>
@break

@case('diptych')
    @php $ph = $h - round(270 * $u) - $safeT - $safeB; @endphp
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ $top(40) }};left:{{ $px(40) }};width:{{ round(($w - 92 * $u) / 2) }}px;height:{{ $ph }}px">
    <img class="pic abs" src="{{ $photo(1) }}" alt="" style="top:{{ $top(40) }};right:{{ $px(40) }};width:{{ round(($w - 92 * $u) / 2) }}px;height:{{ $ph }}px">
    <div class="abs" style="left:{{ $px(40) }};right:{{ $px(40) }};bottom:{{ $bot(96) }};display:flex;justify-content:space-between;align-items:flex-end;gap:{{ $px(30) }}">
        <div><div class="cap" style="font-size:{{ $px(14) }};color:var(--muted)">{{ $kicker }}</div><div class="display" style="font-size:{{ $fit(58, 24) }};margin-top:{{ $px(14) }}">{{ $hl }}</div></div>
        <div style="text-align:right;font:400 {{ $px(18) }}/1.6 var(--sans);color:var(--muted);white-space:nowrap">{{ $city }}<br><b style="font-weight:600;color:var(--ink)">{{ $price }}</b></div>
    </div>
    <div class="abs" style="left:{{ $px(40) }};bottom:{{ $bot(48) }}">{!! $mark(true, 22) !!}</div>
@break

@case('cinema')
    @php $band = $hh($square ? 0.42 : ($story ? 0.3 : 0.34)); @endphp
    <div class="abs cap" style="top:{{ round(($h - $band) / 2) - round(180 * $u) }}px;left:0;right:0;text-align:center;color:var(--muted);letter-spacing:.5em;font-size:{{ $px(14) }}">{{ $kit['brokerage'] }} presents</div>
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ round(($h - $band) / 2) - round(40 * $u) }}px;left:0;width:{{ $w }}px;height:{{ $band }}px">
    <div class="abs" style="top:{{ round(($h + $band) / 2) + round(30 * $u) }}px;left:{{ $px(40) }};right:{{ $px(40) }};text-align:center">
        <div class="display" style="font-size:{{ $fit(62, 14) }};margin-right:-.42em">{{ $hl }}</div>
        <div class="ital" style="font-size:{{ $px(34) }};color:var(--muted);margin-top:{{ $px(28) }}">{{ implode(' · ', array_filter([$kicker.($city !== '' ? ' in '.explode(',', $city)[0] : ''), $price])) }}</div>
    </div>
    <div class="abs" style="bottom:{{ $bot(80) }};left:0;right:0;display:flex;justify-content:center">{!! $mark(false, 28) !!}</div>
@break

@case('arch')
    @php $aw = round($w * ($square ? 0.62 : 0.72)); $ah = $hh($square ? 0.62 : 0.65) - $safeT; @endphp
    <div class="abs" style="top:{{ $top(90) }};left:{{ round(($w - $aw) / 2) }}px;width:{{ $aw }}px;height:{{ $ah }}px;border-radius:{{ round($aw / 2) }}px {{ round($aw / 2) }}px 0 0;overflow:hidden"><img class="ph" src="{{ $photo(0) }}" alt=""></div>
    <div class="abs" style="left:0;right:0;bottom:{{ $bot(140) }};text-align:center">
        <div class="display" style="font-size:{{ $px(70) }}">{{ $kicker }}</div>
        <div class="cap" style="margin-top:{{ $px(22) }};color:var(--muted);font-size:{{ $px(15) }}">{{ implode(' · ', array_filter([$hl, explode(',', $city)[0] ?? '', $price])) }}</div>
    </div>
    <div class="abs" style="bottom:{{ $bot(66) }};left:0;right:0;display:flex;justify-content:center">{!! $mark(true, 26) !!}</div>
@break

@case('noir')
    @php $ph = $h - round(470 * $u) - $safeT - $safeB; @endphp
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ $top(60) }};left:{{ $px(60) }};width:{{ $w - round(120 * $u) }}px;height:{{ $ph }}px;border-radius:var(--r)">
    <div class="abs" style="top:{{ round(60 * $u) + $safeT + $ph + round(44 * $u) }}px;left:{{ $px(68) }};right:{{ $px(68) }};display:flex;justify-content:space-between;align-items:flex-end;gap:{{ $px(24) }}">
        <div><div class="cap" style="font-size:{{ $px(14) }};color:var(--muted)">{{ $kicker }}@if ($city !== '') · {{ explode(',', $city)[0] }}@endif</div><div class="display" style="font-size:{{ $fit(66, 20) }};margin-top:{{ $px(16) }}">{{ $hl }}</div></div>
        @if ($price !== '')<div class="display" style="font-size:{{ $px(40) }};color:var(--accent);white-space:nowrap">{{ $price }}</div>@endif
    </div>
    <div class="abs" style="left:{{ $px(68) }};right:{{ $px(68) }};bottom:{{ $bot(70) }};display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--rule);padding-top:{{ $px(26) }}">
        <div style="display:flex;align-items:center;gap:{{ $px(18) }}">{!! $avatar(56, 'var(--accent)') !!}<div style="font:400 {{ $px(17) }}/1 var(--sans);letter-spacing:.12em;color:var(--muted);text-transform:uppercase">{{ $name }}</div></div>
        {!! $mark(false, 26) !!}
    </div>
@break

@case('collage')
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ $top(84) }};left:{{ $px(64) }};width:{{ $px(720) }};height:{{ $px($square ? 520 : 620) }};box-shadow:0 30px 60px rgba(60,40,20,.18)">
    <img class="pic abs" src="{{ $photo(1) }}" alt="" style="top:{{ $top($square ? 400 : 470) }};left:{{ $px(560) }};width:{{ $px(450) }};height:{{ $px($square ? 360 : 430) }};border:{{ $px(14) }} solid #fff;box-shadow:0 30px 60px rgba(60,40,20,.22);transform:rotate(2deg)">
    <img class="pic abs" src="{{ $photo(2) }}" alt="" style="top:{{ $top($square ? 640 : 760) }};left:{{ $px(110) }};width:{{ $px(400) }};height:{{ $px($square ? 250 : 300) }};border:{{ $px(14) }} solid #fff;box-shadow:0 26px 50px rgba(60,40,20,.2);transform:rotate(-2.5deg)">
    <div class="abs" style="top:{{ $top($square ? 820 : 1010) }};left:{{ $px(590) }};right:{{ $px(40) }}"><div class="display" style="font-size:{{ $px(62) }}">{{ $kicker }}</div><div class="cap" style="font-size:{{ $px(14) }};margin-top:{{ $px(16) }};color:var(--muted)">{{ implode(' · ', array_filter([explode(',', $city)[0] ?? '', $price])) }}</div></div>
    <div class="abs" style="top:{{ $top(36) }};right:{{ $px(64) }}">{!! $mark(true, 26) !!}</div>
@break

@case('block')
    @php $cut = $hh($square ? 0.56 : 0.62) + $safeT; @endphp
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:0;left:0;width:{{ $w }}px;height:{{ $cut }}px">
    <div class="abs" style="top:{{ $cut }}px;left:0;right:0;bottom:0;background:var(--field)"></div>
    @if ($cutout)
        <img class="abs" src="{{ $cutout }}" alt="" style="right:{{ $px(20) }};bottom:0;height:{{ $hh($square ? 0.52 : 0.5) }}px;object-fit:contain;filter:drop-shadow(0 20px 40px rgba(0,0,0,.35))">
    @endif
    <div class="abs pill" style="top:{{ $cut - round(32 * $u) }}px;left:{{ $px(64) }};background:#fff;color:var(--field);font-weight:600">● {{ $kicker }}</div>
    <div class="abs light" style="top:{{ $cut + round(66 * $u) }}px;left:{{ $px(64) }};right:{{ $cutout ? $px(380) : $px(64) }}">
        <div class="display" style="font-size:{{ $px($price !== '' ? ($square ? 130 : 160) : 90) }};line-height:.85">{{ $price !== '' ? preg_replace('/,000$/', 'K', $price) : $hl }}</div>
        <div style="font:500 {{ $px(22) }}/1.5 var(--sans);opacity:.82;margin-top:{{ $px(22) }}">{{ $price !== '' ? $hl.($city !== '' ? ', '.explode(',', $city)[0] : '') : $city }}<br>{{ implode(' · ', $facts) }}</div>
    </div>
    <div class="abs" style="bottom:{{ $bot(70) }};left:{{ $px(64) }}">{!! $mark(false, 28) !!}</div>
@break

@case('index')
    <div class="abs" style="top:{{ $top(60) }};left:{{ $px(60) }};right:{{ $px(60) }};display:flex;justify-content:space-between;font:500 {{ $px(15) }}/1 var(--sans);letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid var(--ink);padding-bottom:{{ $px(16) }}">
        <span>(01) {{ $kicker }}</span><span>{{ $sub }}</span></div>
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ $top(120) }};left:{{ $px(300) }};width:{{ $w - round(360 * $u) }}px;height:{{ $hh($square ? 0.6 : 0.64) - $safeT }}px">
    <div class="abs" style="top:{{ $top(120) }};left:{{ $px(60) }};width:{{ $px(210) }};font:400 {{ $px(15) }}/1.7 var(--sans);color:var(--muted)">
        <div style="color:var(--ink);font-weight:500">Residence</div>{{ $hl }}<br><br>
        @if ($facts !== [])<div style="color:var(--ink);font-weight:500">Program</div>{!! implode('<br>', array_map('e', $facts)) !!}<br><br>@endif
        @if ($price !== '')<div style="color:var(--ink);font-weight:500">Offered</div>{{ $price }}@endif
    </div>
    <div class="abs display" style="left:{{ $px(56) }};right:{{ $px(56) }};bottom:{{ $bot(110) }};font-size:{{ $fit(116, 14) }}">{{ $hl }}</div>
    <div class="abs" style="right:{{ $px(60) }};bottom:{{ $bot(56) }}">{!! $mark(true, 24) !!}</div>
@break

@case('horizon')
    @php $cut = $hh($square ? 0.52 : 0.56) + $safeT; @endphp
    <div class="abs" style="inset:0;background:linear-gradient(180deg,var(--bg),var(--paper))"></div>
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:0;left:0;width:{{ $w }}px;height:{{ $cut }}px">
    <div class="abs" style="top:{{ $cut - round(130 * $u) }}px;left:0;right:0;height:{{ $px(132) }};background:linear-gradient(180deg,rgba(0,0,0,0),var(--bg))"></div>
    <div class="abs" style="top:{{ $cut + round(54 * $u) }}px;left:{{ $px(40) }};right:{{ $px(40) }};text-align:center">
        <div class="cap" style="color:var(--muted);font-size:{{ $px(15) }}">{{ $kicker }}</div>
        <div class="display" style="font-size:{{ $fit(88, 18) }};margin-top:{{ $px(20) }}">{{ $hl }}</div>
        <div style="font:400 {{ $px(19) }}/1 var(--sans);margin-top:{{ $px(28) }};color:var(--muted);letter-spacing:.06em">{{ implode('  —  ', array_filter([$city, $price, implode(' · ', array_slice($facts, 0, 2))])) }}</div>
    </div>
    <div class="abs" style="bottom:{{ $bot(66) }};left:0;right:0;display:flex;justify-content:center">{!! $mark(true, 26) !!}</div>
@break

@case('seal')
    <img class="ph" src="{{ $photo(0) }}" alt="">
    <div class="abs" style="inset:0;background:linear-gradient(180deg,rgba(0,0,0,.25) 0%,rgba(0,0,0,0) 18%,rgba(0,0,0,0) 58%,rgba(0,0,0,.58) 100%)"></div>
    @php $ring = mb_strtoupper($kicker.' • '.($city !== '' ? explode(',', $city)[0] : $kit['brokerage']).' • '); $ring = str_repeat($ring, max(1, (int) floor(60 / max(1, mb_strlen($ring))))); @endphp
    <svg viewBox="0 0 200 200" width="{{ round(240 * $u) }}" height="{{ round(240 * $u) }}" style="position:absolute;top:{{ $top(54) }};right:{{ $px(54) }}" aria-hidden="true">
        <circle cx="100" cy="100" r="96" fill="var(--paper)"/><defs><path id="ring" d="M100,100 m-70,0 a70,70 0 1,1 140,0 a70,70 0 1,1 -140,0"/></defs>
        <text font-family="Inter Tight" font-weight="600" font-size="14.5" letter-spacing="4.4" fill="#1A1A1A"><textPath href="#ring" textLength="436">{{ $ring }}</textPath></text>
        <text x="100" y="114" text-anchor="middle" font-family="Instrument Serif" font-style="italic" font-size="40" fill="#1A1A1A">new</text></svg>
    <div class="abs" style="top:{{ $top(64) }};left:{{ $px(64) }}">{!! $mark(false, 32) !!}</div>
    <div class="abs light" style="left:{{ $px(64) }};right:{{ $px(64) }};bottom:{{ $bot(100) }}">
        <div class="display shadow" style="font-size:{{ $fit(76, 24) }}">{{ $hl }}</div>
        <div style="font:400 {{ $px(20) }}/1 var(--sans);letter-spacing:.1em;margin-top:{{ $px(20) }};text-transform:uppercase;opacity:.92">{{ implode(' · ', array_filter([$price, ...$facts])) }}</div>
    </div>
@break

@case('wide')
    <img class="ph" src="{{ $photo(0) }}" alt="" style="object-position:50% 70%">
    <div class="abs" style="inset:0;background:linear-gradient(180deg,rgba(10,16,28,.62) 0%,rgba(10,16,28,.3) 22%,rgba(0,0,0,0) 40%,rgba(0,0,0,0) 70%,rgba(0,0,0,.5) 100%)"></div>
    <div class="abs light" style="top:{{ $top(104) }};left:{{ $px(30) }};right:{{ $px(30) }};text-align:center">
        <div style="font:300 {{ $px(20) }}/1 var(--display);letter-spacing:.6em;margin-right:-.6em;text-transform:uppercase">{{ $kicker }}</div>
        <div class="display shadow" style="font-size:{{ $fit(84, 12) }};margin-top:{{ $px(26) }}">{{ $hl }}</div>
    </div>
    <div class="abs light" style="left:{{ $px(64) }};right:{{ $px(64) }};bottom:{{ $bot(90) }};display:flex;justify-content:space-between;align-items:center;font:400 {{ $px(19) }}/1 var(--sans);letter-spacing:.12em;text-transform:uppercase">
        <span>{{ implode(' · ', array_filter([$city, $price])) }}</span>{!! $mark(false, 28) !!}</div>
@break

@case('heritage')
    <div class="abs" style="inset:{{ $px(36) }};border:1px solid var(--accent);opacity:.7"></div><div class="abs" style="inset:{{ $px(44) }};border:1px solid var(--rule)"></div>
    <div class="abs" style="top:{{ $top(84) }};left:0;right:0;display:flex;justify-content:center">{!! $mark(true, 30) !!}</div>
    <img class="pic abs" src="{{ $photo(0) }}" alt="" style="top:{{ $top(150) }};left:{{ $px(96) }};width:{{ $w - round(192 * $u) }}px;height:{{ $h - round(560 * $u) - $safeT - $safeB }}px">
    <div class="abs" style="left:{{ $px(60) }};right:{{ $px(60) }};bottom:{{ $bot(130) }};text-align:center">
        <div class="cap" style="color:var(--accent);letter-spacing:.42em;font-size:{{ $px(18) }}">{{ $kicker }}</div>
        <div class="display" style="font-size:{{ $fit(74, 24) }};margin-top:{{ $px(20) }}">{{ $hl }}</div>
        <div class="ital" style="font-size:{{ $px(30) }};margin-top:{{ $px(18) }};color:var(--muted)">{{ $city }}@if ($price !== '') &nbsp;·&nbsp; offered at {{ $price }}@endif</div>
    </div>
@break

@default {{-- gallery --}}
    <img class="ph" src="{{ $photo(0) }}" alt="">
    <div class="abs" style="inset:0;background:linear-gradient(180deg,rgba(0,0,0,.36) 0%,rgba(0,0,0,0) 22%,rgba(0,0,0,0) 58%,rgba(0,0,0,.62) 100%)"></div>
    <div class="abs cap light" style="top:{{ $top(66) }};left:{{ $px(68) }}">{{ $kicker }}</div>
    <div class="abs" style="top:{{ $top(56) }};right:{{ $px(68) }}">{!! $mark(false, 34) !!}</div>
    <div class="abs light" style="left:{{ $px(68) }};right:{{ $px(68) }};bottom:{{ $bot(112) }};display:flex;justify-content:space-between;align-items:flex-end;gap:{{ $px(24) }}">
        <div><div class="display shadow" style="font-size:{{ $fit(82, 22) }}">{{ $hl }}</div>
            <div class="cap" style="font-size:{{ $px(16) }};margin-top:{{ $px(24) }};opacity:.92">{{ implode('  ·  ', array_filter([$city, $price])) }}</div></div>
        @if ($agent){!! $avatar(88, 'rgba(255,255,255,.8)') !!}@endif
    </div>
@endswitch
@endif

<div class="legal"><span>{{ $legal }}@if ($name !== '') · {{ $name }}@endif</span><span style="display:flex;align-items:center;gap:6px;white-space:nowrap">{!! $eho !!} Equal Housing Opportunity</span></div>
</body></html>
