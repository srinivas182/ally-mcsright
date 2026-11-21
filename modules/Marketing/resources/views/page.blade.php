@extends('marketing::layout')
@section('content')
@php($es = $L === 'es')
@foreach ($blocks as $i => $b)
    @switch($b['type'])
        @case('hero')
            @php($homeHero = $i === 0 && ($slug ?? '') === '')
            <section class="hero{{ $homeHero ? ' split' : '' }}"><div class="wrap">
                <div>
                    @if ($i === 0)<h1>{{ $b['heading'] }}</h1>@else<h2>{{ $b['heading'] }}</h2>@endif
                    @if ($b['text'] !== '')<p class="lead">{{ $b['text'] }}</p>@endif
                    @if ($b['cta_label'] !== '' && $b['cta_href'] !== '')<a class="btn" href="{{ $b['cta_href'] === '/start' ? $start : $b['cta_href'] }}">{{ $b['cta_label'] }}</a>@endif
                    @if ($b['second_label'] !== '' && $b['second_href'] !== '')<a class="btn ghost" href="{{ $b['second_href'] === '/health-check' ? $healthCheck : $b['second_href'] }}">{{ $b['second_label'] }}</a>@endif
                    @if ($homeHero)
                        <ul class="facts">
                            <li>{{ $es ? 'Precios públicos' : 'Public prices' }}</li>
                            <li>{{ $es ? 'Le mudamos sus datos' : 'We move your data in' }}</li>
                            <li>{{ $es ? 'Inglés y español' : 'English and Spanish' }}</li>
                            <li>{{ $es ? 'Sus prospectos nunca se venden' : 'Your leads are never sold' }}</li>
                        </ul>
                    @endif
                </div>
                @if ($homeHero)
                    {{-- An illustration of the daily brief (made-up names), not a screenshot of anyone's account. --}}
                    <div class="preview-app" aria-hidden="true">
                        <div class="band"><span>{{ $es ? 'Lunes' : 'Monday' }}</span><b>{{ $es ? 'Buenos días, Dana' : 'Good morning, Dana' }}</b></div>
                        <ul>
                            <li><i style="background:#EAF0FF;color:#0B3FB8">JM</i><span>{{ $es ? 'Nuevo prospecto: Jordan M.' : 'New lead: Jordan M.' }}<small>{{ $es ? 'Pidió una visita el sábado' : 'Asked to tour on Saturday' }}</small></span><span class="tag">{{ $es ? 'Llamar' : 'Call' }}</span></li>
                            <li><i style="background:#FFF4DE;color:#8A5A00">14</i><span>{{ $es ? 'Cierre: 14 Oak Lane' : 'Closing: 14 Oak Lane' }}<small>{{ $es ? 'Falta 1 firma, vence el viernes' : '1 signature missing, due Friday' }}</small></span><span class="tag">{{ $es ? 'Revisar' : 'Review' }}</span></li>
                            <li><i style="background:#E6F6EE;color:#0B6B3A">$</i><span>{{ $es ? 'Comisión lista para pagar' : 'Commission ready to pay' }}<small>{{ $es ? 'Calculada con su plan' : 'Worked out from your plan' }}</small></span><span class="tag">{{ $es ? 'Aprobar' : 'Approve' }}</span></li>
                            <li><i style="background:#F1ECFF;color:#4B2BB8">AI</i><span>{{ $es ? 'Borrador de seguimiento listo' : 'Follow-up draft ready' }}<small>{{ $es ? 'Para 3 clientes de la semana pasada' : 'For 3 clients from last week' }}</small></span><span class="tag">{{ $es ? 'Enviar' : 'Send' }}</span></li>
                        </ul>
                    </div>
                @endif
            </div></section>
            @break
        @case('answer')
            <section><div class="wrap"><div class="answer"><h2>{{ $b['question'] }}</h2><p>{{ $b['answer'] }}</p></div></div></section>
            @break
        @case('features')
            <section class="{{ $i % 2 === 0 ? 'tinted' : '' }}"><div class="wrap">
                @if ($b['heading'] !== '')<div class="head"><h2>{{ $b['heading'] }}</h2></div>@endif
                <div class="grid">@foreach ($b['items'] as $k => $item)<div class="card feature"><span class="ico" aria-hidden="true">{!! \Modules\Marketing\Support\BrandLogo::icon($k, (string) $item['title']) !!}</span><h3>{{ $item['title'] }}</h3>@if ($item['text'] !== '')<p class="muted">{{ $item['text'] }}</p>@endif</div>@endforeach</div>
            </div></section>
            @break
        @case('steps')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<div class="head"><h2>{{ $b['heading'] }}</h2></div>@endif
                <ol class="steps">@foreach ($b['items'] as $item)<li><h3>{{ $item['title'] }}</h3>@if ($item['text'] !== '')<p class="muted">{{ $item['text'] }}</p>@endif</li>@endforeach</ol>
            </div></section>
            @break
        @case('faq')
            <section class="tinted"><div class="wrap" style="max-width:880px">
                <div class="head"><h2>{{ $b['heading'] !== '' ? $b['heading'] : ($es ? 'Preguntas frecuentes' : 'Questions and answers') }}</h2></div>
                @foreach ($b['items'] as $item)<details><summary>{{ $item['q'] }}</summary><p>{{ $item['a'] }}</p></details>@endforeach
            </div></section>
            @break
        @case('compare')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<div class="head"><h2>{{ $b['heading'] }}</h2></div>@endif
                <div class="tablewrap"><table>
                    <thead><tr><th scope="col"><span class="small muted">{{ $es ? 'Comparado el' : 'Checked on' }} {{ $b['checked_on'] }}</span></th>@foreach ($b['columns'] as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($b['rows'] as $row)<tr><th scope="row">{{ $row['label'] }}</th>@foreach ($row['values'] as $v)<td>{{ $v }}</td>@endforeach</tr>@endforeach</tbody>
                </table></div>
                @if ($b['note'] !== '')<p class="small muted">{{ $b['note'] }}</p>@endif
            </div></section>
            @break
        @case('pricing')
            <section><div class="wrap">
                <div class="head"><h2>{{ $b['heading'] !== '' ? $b['heading'] : ($es ? 'Precios' : 'Prices') }}</h2>
                    <p class="lead">{{ $es ? 'Un precio por mes para su correduría. Cada plan muestra lo que incluye.' : 'One monthly price for your brokerage. Each plan shows what is included.' }}</p></div>
                @php($plans = ($byAudience[$b['audience'] ?? 'brokerage'] ?? $packages))
                <div class="grid plans">
                    @php($start2 = ($b['audience'] ?? 'brokerage') === 'agent' ? preg_replace('#/start$#', '/agents', $start) : $start)
                    @forelse ($plans as $pi => $p)
                        <div class="card{{ count($plans) === 3 && $pi === 1 ? ' best' : '' }}" data-best="{{ $es ? 'Recomendado' : 'Recommended' }}">
                            <h3>{{ $p->name }}</h3>
                            <p class="price">${{ number_format(((int) $p->monthly_cents) / 100, 0) }}<span class="small muted"> / {{ $es ? 'mes' : 'month' }}</span></p>
                            @if ((int) $p->yearly_cents > 0)<p class="small muted">{{ $es ? 'o' : 'or' }} ${{ number_format(((int) $p->yearly_cents) / 100, 0) }} / {{ $es ? 'año' : 'year' }}</p>@endif
                            @include('marketing::partials.includes', ['lines' => $includes[(string) ($p->ulid ?? '')] ?? []])
                            <a class="btn" href="{{ $start2 }}?package={{ $p->ulid ?? '' }}">{{ $es ? 'Elegir' : 'Choose' }} {{ $p->name }}</a>
                        </div>
                    @empty
                        <p class="muted">{{ $es ? 'Pronto publicaremos los precios.' : 'Prices are coming soon.' }}</p>
                    @endforelse
                </div>
                @if ($b['note'] !== '')<p class="small muted">{{ $b['note'] }}</p>@endif
            </div></section>
            @break
        @case('seats')
            <section><div class="wrap">
                <div class="head"><h2>{{ $b['heading'] !== '' ? $b['heading'] : ($es ? 'Precios para agentes' : 'Prices for agents') }}</h2>
                    <p class="lead">{{ $es ? 'Los agentes de una correduría en RightAlly pagan menos. Los agentes por su cuenta pagan el precio público.' : 'Agents whose brokerage is on RightAlly pay less. Agents on their own pay the public price.' }}</p></div>
                <div class="grid plans">
                    @forelse (($seats ?? []) as $si => $seat)
                        <div class="card{{ count($seats) === 3 && $si === 1 ? ' best' : '' }}" data-best="{{ $es ? 'Recomendado' : 'Recommended' }}">
                            <h3>{{ $seat->name }}</h3>
                            <p class="muted small">{{ $seat->description }}</p>
                            <p class="price">${{ number_format(((int) $seat->floor_cents) / 100, 0) }}<span class="per">{{ $es ? 'al mes con su correduría' : 'a month with your brokerage' }}</span></p>
                            <p class="small muted">${{ number_format(((int) $seat->list_cents) / 100, 0) }} / {{ $es ? 'mes por su cuenta' : 'month on your own' }}</p>
                            <p class="small muted">{{ $es ? 'Con correduría: su broker le invita.' : 'With a brokerage: your broker invites you.' }}</p>
                            @include('marketing::partials.includes', ['lines' => $seatIncludes[$si] ?? []])
                            <a class="btn ghost" href="{{ preg_replace('#/start$#', '/agents', $start) }}">{{ $es ? 'Empezar por su cuenta' : 'Start on your own' }}</a>
                        </div>
                    @empty
                        <p class="muted">{{ $es ? 'Pronto publicaremos los precios.' : 'Prices are coming soon.' }}</p>
                    @endforelse
                </div>
                @if ($b['note'] !== '')<p class="small muted">{{ $b['note'] }}</p>@endif
            </div></section>
            @break
        @case('text')
            <section><div class="wrap prose">
                @if ($b['heading'] !== '')<h2>{{ $b['heading'] }}</h2>@endif
                @foreach (preg_split('/\n{2,}/', $b['body']) as $para)<p>{{ $para }}</p>@endforeach
            </div></section>
            @break
        @case('testimonials')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<div class="head"><h2>{{ $b['heading'] }}</h2></div>@endif
                <div class="grid">@foreach ($b['items'] as $item)<figure class="card"><blockquote>“{{ $item['quote'] }}”</blockquote><figcaption class="muted small">— {{ $item['name'] }}@if ($item['role'] !== ''), {{ $item['role'] }}@endif</figcaption></figure>@endforeach</div>
            </div></section>
            @break
        @case('doors')
            <section><div class="wrap">
                @if ($b['heading'] !== '')<div class="head"><h2>{{ $b['heading'] }}</h2></div>@endif
                <div class="grid doors">
                    <a class="card door" href="{{ $b['broker_href'] }}"><h3>{{ $b['broker_heading'] }}</h3>@if ($b['broker_text'] !== '')<p>{{ $b['broker_text'] }}</p>@endif<span class="go">{{ $es ? 'Ver cómo funciona' : 'See how it works' }}</span></a>
                    <a class="card door" href="{{ $b['agent_href'] }}"><h3>{{ $b['agent_heading'] }}</h3>@if ($b['agent_text'] !== '')<p>{{ $b['agent_text'] }}</p>@endif<span class="go">{{ $es ? 'Ver cómo funciona' : 'See how it works' }}</span></a>
                </div>
            </div></section>
            @break
        @case('modules')
            @php($show = $showcase[$b['audience']] ?? ['modules' => []])
            <section class="modules"><div class="wrap">
                <div class="head"><h2>{{ $b['heading'] !== '' ? $b['heading'] : ($es ? 'Lo que incluye' : 'What is included') }}</h2></div>
                <div class="grid">
                    @foreach ($show['modules'] as $mi => $m)
                        <div class="card feature"><span class="ico" aria-hidden="true">{!! \Modules\Marketing\Support\BrandLogo::icon($mi, (string) $m['label']) !!}</span>
                            <h3>{{ $m['label'] }}</h3>
                            <p>{{ $m['about'] }}</p>
                            @if ($m['packages'] !== [])<p class="small muted">{{ $es ? 'Incluido en' : 'In' }}: {{ implode(', ', $m['packages']) }}</p>@endif
                            @if ($m['video'] !== null)<p><a href="{{ $m['video'] }}" target="_blank" rel="noopener">{{ $es ? 'Ver en 1 minuto' : 'Watch in 1 minute' }} ▶</a></p>@endif
                        </div>
                    @endforeach
                </div>
            </div></section>
            @break
        @case('savings')
            @php($show = $showcase[$b['audience']] ?? ['categories' => [], 'packages' => []])
            <section><div class="wrap"><div class="card savings" data-savings='@json(['packages' => $show['packages'], 'categories' => $show['categories']])'>
                <h2>{{ $b['heading'] !== '' ? $b['heading'] : ($es ? '¿Cuánto paga hoy?' : 'What do you pay today?') }}</h2>
                @if ($b['text'] !== '')<p class="lead">{{ $b['text'] }}</p>@endif
                <div class="grid">
                    @foreach ($show['categories'] as $c)
                        <label><span>{{ $c['label'] }} ({{ $es ? 'al mes' : 'a month' }})</span><input type="number" min="0" step="1" inputmode="numeric" name="{{ $c['key'] }}" placeholder="$0"></label>
                    @endforeach
                </div>
                <p class="lead" data-savings-result aria-live="polite">{{ $es ? 'Escriba lo que paga hoy por cada herramienta.' : 'Type what you pay today for each tool.' }}</p>
            </div></div></section>
            <script nonce="{{ Vite::cspNonce() }}">(function(){document.querySelectorAll('[data-savings]').forEach(function(box){var d=JSON.parse(box.getAttribute('data-savings')),out=box.querySelector('[data-savings-result]'),es={{ $es ? 'true' : 'false' }};
function money(c){return '$'+Math.round(c/100).toLocaleString('en-US')}
function calc(){var total=0,need={};d.categories.forEach(function(c){var v=parseFloat((box.querySelector('[name="'+c.key+'"]')||{}).value||'0')||0;if(v>0){total+=v*100;c.modules.forEach(function(m){need[m]=1})}});
if(total<=0){return}var best=null;d.packages.forEach(function(p){var ok=Object.keys(need).every(function(m){return p.modules.indexOf(m)>-1});if(ok&&(best===null||p.monthly<best.monthly)){best=p}});
if(best===null){out.textContent=(es?'Hoy paga ':'Today you pay ')+money(total)+(es?' al mes. Hable con nosotros para un paquete con todo esto.':' a month. Talk to us for a package with all of this.');return}
var diff=total-best.monthly;out.textContent=(es?'Hoy paga ':'Today you pay ')+money(total)+(es?' al mes en herramientas separadas. ':' a month for separate tools. ')+best.name+(es?' incluye todo esto por ':' includes all of this for ')+money(best.monthly)+(es?' al mes':' a month')+(diff>0?(es?' — ahorra ':' — you save ')+money(diff)+(es?' al mes, con una sola factura.':' a month, on one bill.'):(es?', con una sola factura y todo en un lugar.':', on one bill with everything in one place.'))}
box.addEventListener('input',calc)})})();</script>
            @break
        @case('cta')
            <section><div class="wrap"><div class="cta-band">
                <h2>{{ $b['heading'] }}</h2>
                @if ($b['text'] !== '')<p class="lead">{{ $b['text'] }}</p>@endif
                <a class="btn" href="{{ $b['href'] === '/start' ? $start : ($b['href'] === '/health-check' ? $healthCheck : $b['href']) }}">{{ $b['label'] }}</a>
            </div></div></section>
            @break
    @endswitch
@endforeach
@endsection
