{{--
    Ally Studio YouTube listing tour frame (specs/grow/13, SA3b): 1920x1080, in the person's style. Slides: intro (the
    cover photo with the address), chapter (one photo with its room name as a lower third), outro (the agent and how
    to book a tour). Inputs: $slide, $t, $p, $grade, $fonts, $kit, $c, $photo, $chapter, $n, $total, $headshot,
    $logoLight, $logoInk. Everything is escaped; no scripts.
--}}
@php
    $name = (string) ($kit['details']['display_name'] ?? '');
    $title = (string) ($kit['details']['title'] ?? '');
    $phone = (string) ($kit['details']['phone'] ?? '');
    $sub = (string) ($c['sub'] ?? '');
    $price = (string) ($c['price'] ?? '');
    $facts = array_values(array_filter(array_map('strval', (array) ($c['facts'] ?? []))));
    $legal = implode(' · ', array_filter([! empty($c['enhanced']) ? 'Photos virtually enhanced' : null, $c['attribution'] ?? null, $kit['details']['licence_line'] ?? null, $kit['brokerage']]));
    $initials = mb_strtoupper(implode('', array_map(fn (string $w): string => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($name)) ?: [], 0, 2))));
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<style>
{!! $fonts !!}
:root{--display:{!! $t['display'] !!};--dw:{{ $t['dw'] }};--ds:{{ $t['ds'] }};--dt:{{ $t['dt'] }};--dl:{{ $t['dl'] }};--sans:{!! $t['sans'] !!};
  --bg:{{ $p['bg'] }};--paper:{{ $p['paper'] }};--ink:{{ $p['fg'] }};--muted:{{ $p['muted'] }};--rule:{{ $p['rule'] }};--accent:{{ $p['metal'] }}}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:1920px;height:1080px;overflow:hidden}
body{position:relative;background:var(--bg);color:var(--ink);font-family:var(--sans);-webkit-font-smoothing:antialiased}
.abs{position:absolute}
.ph{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:{{ $grade }} url(#sharpen)}
.display{font-family:var(--display);font-weight:var(--dw);font-style:var(--ds);text-transform:var(--dt);letter-spacing:var(--dl);line-height:1}
.cap{font:500 22px/1 var(--sans);text-transform:uppercase;letter-spacing:.3em}
.onph{color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.3),0 2px 18px rgba(0,0,0,.35)}
.veil{position:absolute;pointer-events:none;box-sizing:content-box;backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px)}
.veil-l{left:-48px;padding-left:48px;top:-48px;bottom:-48px;-webkit-mask-image:linear-gradient(270deg,transparent 0%,#000 45%);mask-image:linear-gradient(270deg,transparent 0%,#000 45%);
  background:linear-gradient(270deg,rgba(8,8,10,0) 0%,rgba(8,8,10,.42) 50%,rgba(8,8,10,.66) 100%)}
.veil-b{left:0;right:0;bottom:-48px;padding-bottom:48px;-webkit-mask-image:linear-gradient(180deg,transparent 0%,#000 45%);mask-image:linear-gradient(180deg,transparent 0%,#000 45%);
  background:linear-gradient(180deg,rgba(8,8,10,0) 0%,rgba(8,8,10,.42) 50%,rgba(8,8,10,.68) 100%)}
.logo{display:block;max-height:46px;max-width:340px;object-fit:contain}
.legal{position:absolute;left:80px;right:80px;bottom:28px;font:400 16px/1.2 var(--sans);letter-spacing:.06em;text-align:center}
</style></head><body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><filter id="sharpen"><feConvolveMatrix order="3" preserveAlpha="true" kernelMatrix="0 -.3 0 -.3 2.2 -.3 0 -.3 0"/></filter></svg>
@if ($slide === 'intro')
    <img class="ph" src="{{ $photo }}" alt="">
    <div class="veil veil-l" style="width:1050px"></div>
    <div class="abs" style="top:80px;left:96px">@if ($logoLight)<img class="logo" src="{{ $logoLight }}" alt="">@else<div class="cap onph">{{ $kit['brokerage'] }}</div>@endif</div>
    <div class="abs onph" style="left:96px;bottom:150px;max-width:900px">
        <div class="cap">{{ $c['kicker'] ?? 'Welcome' }}</div>
        <div class="display" style="font-size:{{ mb_strlen((string) ($c['headline'] ?? '')) > 24 ? 92 : 112 }}px;margin-top:26px">{{ $c['headline'] ?? '' }}</div>
        <div style="font:400 30px/1.4 var(--sans);margin-top:26px;opacity:.94">{{ implode('  ·  ', array_filter([$sub, $price])) }}</div>
        @if ($facts !== [])<div class="cap" style="font-size:20px;margin-top:22px;opacity:.9">{{ implode('   ·   ', $facts) }}</div>@endif
    </div>
    <div class="legal onph" style="color:rgba(255,255,255,.86)">{{ $legal }} · Equal Housing Opportunity</div>
@elseif ($slide === 'chapter')
    <img class="ph" src="{{ $photo }}" alt="">
    <div class="veil veil-b" style="height:360px"></div>
    <div class="abs onph" style="left:96px;bottom:110px">
        <div class="cap" style="font-size:20px;opacity:.9">{{ str_pad((string) $n, 2, '0', STR_PAD_LEFT) }} / {{ str_pad((string) $total, 2, '0', STR_PAD_LEFT) }}</div>
        <div class="display" style="font-size:76px;margin-top:16px">{{ $chapter }}</div>
    </div>
    <div class="abs" style="right:96px;bottom:118px">@if ($logoLight)<img class="logo" src="{{ $logoLight }}" alt="" style="max-height:36px">@endif</div>
    <div class="legal onph" style="color:rgba(255,255,255,.86)">{{ $legal }} · Equal Housing Opportunity</div>
@else
    <div class="abs" style="inset:0;background:{{ $p['dark'] ? 'var(--bg)' : 'var(--paper)' }}"></div>
    <img class="abs" src="{{ $photo }}" alt="" style="right:0;top:0;width:820px;height:1080px;object-fit:cover;filter:{{ $grade }}">
    <div class="abs" style="left:110px;top:150px;width:860px">
        <div class="cap" style="color:var(--muted)">Book a private tour</div>
        <div class="display" style="font-size:84px;margin-top:28px;line-height:1.02">{{ $c['headline'] ?? '' }}</div>
        @if ($price !== '')<div class="display" style="font-size:56px;color:var(--accent);margin-top:24px;text-transform:none;letter-spacing:0">{{ $price }}</div>@endif
        <div style="display:flex;align-items:center;gap:30px;margin-top:80px;border-top:1px solid var(--rule);padding-top:44px">
            @if ($headshot)
                <img src="{{ $headshot }}" alt="" style="width:150px;height:150px;border-radius:50%;object-fit:cover;object-position:50% 18%;box-shadow:0 0 0 2px var(--accent)">
            @else
                <div style="width:150px;height:150px;border-radius:50%;border:1px solid var(--accent);display:grid;place-items:center;font:400 56px/1 var(--display);color:var(--accent)">{{ $initials !== '' ? $initials : 'R' }}</div>
            @endif
            <div>
                <div class="display" style="font-size:52px;text-transform:none;letter-spacing:0">{{ $name }}</div>
                <div style="font:400 24px/1.5 var(--sans);letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-top:12px">{{ implode(' · ', array_filter([$title !== '' ? $title : 'REALTOR®', $phone])) }}</div>
            </div>
        </div>
        <div style="margin-top:56px">@if ($p['dark'] ? $logoLight : $logoInk)<img class="logo" src="{{ $p['dark'] ? $logoLight : $logoInk }}" alt="">@endif</div>
    </div>
    <div class="legal" style="color:var(--muted);right:900px">{{ $legal }} · Equal Housing Opportunity</div>
@endif
</body></html>
