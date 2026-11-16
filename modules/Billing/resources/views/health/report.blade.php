@php
    $lang = $r['lang'];
    $t = fn (string $k, array $w = []) => \Modules\Billing\HealthCheck\HealthCopy::t($lang, $k, $w);
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $note = fn (array $row) => isset($row['key']) ? \Modules\Billing\HealthCheck\HealthCopy::toolNote($lang, $row['key']) : $row['note'];
    $s = $r['savings'];
    $best = $s['best'] !== null ? $s['options'][$s['best']] : null;
    $base = $hq ? '/hq/health-checks/'.$r['ulid'] : '/health-check/r/'.$token;
    $scanning = $r['website_url'] !== null && $r['website'] === null;
@endphp
@extends('billing::health.layout', ['title' => $t('r.title', ['name' => $r['brokerage']]).' | RightAlly', 'description' => $t('section'), 'lang' => $lang,
    'home' => $lang === 'es' ? '/health-check/es' : '/health-check'])

@if ($scanning)
    @push('head')<meta http-equiv="refresh" content="6">@endpush
@endif

@section('main')
<section class="hero"><div class="wrap">
    @if ($hq)<p class="small"><a href="/hq/health-checks">← All health checks</a></p>@endif
    <h1>{{ $t('r.title', ['name' => $r['brokerage']]) }}</h1>
    <div class="actions">
        <span class="score" aria-label="{{ $t('r.overall', ['score' => $r['scores']['overall']]) }}">{{ $r['scores']['overall'] }}</span>
        <p class="lead">{{ $t('r.hello', ['name' => $r['contact_name'], 'agents' => $r['agents']]) }}</p>
    </div>
</div></section>

<div class="wrap">
    <h2>{{ $t('r.scores') }}</h2>
    <ul class="areas">
        @foreach ($r['scores']['areas'] as $a)
            <li class="{{ $a['status'] }}">
                <h3>{{ $a['title'] }} <span class="pill {{ $a['status'] }}">{{ $t('st.'.$a['status']) }}{{ $a['score'] !== null ? ' · '.$a['score'] : '' }}</span></h3>
                <p class="muted">{{ $a['finding'] }}</p>
                <p><strong>{{ $t('r.with') }}</strong> {{ $a['fix'] }}</p>
            </li>
        @endforeach
    </ul>

    <h2>{{ $t('r.website') }}</h2>
    @if ($r['website_url'] === null)
        <p class="muted">{{ $t('r.no_site') }}</p>
    @elseif ($scanning)
        <p role="status">{{ $t('r.checking', ['url' => $r['website_url']]) }}</p>
    @elseif ($r['website']['status'] !== 'done')
        <p>{{ $t('r.could_not', ['url' => $r['website_url'], 'message' => $lang === 'es' ? $t('r.website_failed') : $r['website']['message']]) }}</p>
    @else
        <p>{{ $t('r.passed', ['url' => $r['website']['url'], 'score' => $r['website']['score']]) }}</p>
        <div class="tablewrap"><table>
            <thead><tr><th scope="col">{{ $t('r.check') }}</th><th scope="col">{{ $t('r.result') }}</th><th scope="col">{{ $t('r.todo') }}</th></tr></thead>
            <tbody>
            @foreach (collect($r['website']['checks'])->sortBy('ok') as $c)
                @php($copy = \Modules\Billing\HealthCheck\HealthCopy::check($lang, $c['key']))
                <tr><td>{{ $copy['label'] }}</td><td>@if ($c['ok'])<span class="ok">{{ $t('r.yes') }}</span>@else<span class="no">{{ $t('r.no') }}</span>@endif</td><td>{{ $c['ok'] ? '' : $copy['fix'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    <h2>{{ $t('r.savings') }}</h2>
    @if ($s['now_cents'] === 0)
        <p class="muted">{{ $t('r.nothing') }}</p>
    @else
        <p>{{ $t('r.pay_now', ['total' => $usd($s['now_cents']), 'per' => $usd(intdiv($s['now_cents'], max(1, $r['agents'])))]) }}</p>
        <div class="tablewrap"><table>
            <thead><tr><th scope="col">{{ $t('r.software') }}</th><th scope="col" class="amt">{{ $t('r.monthly') }}</th><th scope="col">{{ $t('r.with_ra') }}</th></tr></thead>
            <tbody>
            @foreach ($s['replaced'] as $row)<tr><td>{{ $row['name'] }}</td><td class="amt">{{ $usd($row['monthly_cents']) }}</td><td><span class="ok">{{ $t('r.replaced') }}</span> {{ $note($row) }}</td></tr>@endforeach
            @foreach ($s['kept'] as $row)<tr><td>{{ $row['name'] }}</td><td class="amt">{{ $usd($row['monthly_cents']) }}</td><td><strong>{{ $row['partly'] ? $t('r.partly') : $t('r.kept') }}</strong> {{ $note($row) }}</td></tr>@endforeach
            </tbody>
        </table></div>

        @if ($s['options'] === [])
            <p class="muted">{{ $t('r.prices') }}</p>
        @else
            <h3 style="margin-top:20px">{{ $t('r.after') }}</h3>
            <div class="tablewrap"><table>
                <thead><tr><th scope="col">{{ $t('r.package') }}</th><th scope="col" class="amt">{{ $t('r.package_col') }}</th>@if ($s['who_pays'] === 'brokerage')<th scope="col" class="amt">{{ $t('r.agent_plans') }}</th>@endif<th scope="col" class="amt">{{ $t('r.kept_tools') }}</th><th scope="col" class="amt">{{ $t('r.new_total') }}</th><th scope="col" class="amt">{{ $t('r.difference') }}</th></tr></thead>
                <tbody>
                @foreach ($s['options'] as $i => $o)
                    <tr @if ($i === $s['best']) style="background:var(--brand-2)" @endif>
                        <td>{{ $o['package'] }}@if ($i === $s['best']) <span class="pill good">{{ $t('r.best') }}</span>@endif</td>
                        <td class="amt">{{ $usd($o['package_cents']) }}</td>
                        @if ($s['who_pays'] === 'brokerage')<td class="amt">{{ $usd($o['agents_cents']) }}</td>@endif
                        <td class="amt">{{ $usd($s['kept_cents']) }}</td>
                        <td class="amt">{{ $usd($o['after_cents']) }}</td>
                        <td class="amt">@if ($o['saves_cents'] >= 0)<span class="ok">{{ $t('r.save', ['amount' => $usd($o['saves_cents'])]) }}</span>@else<span class="no">{{ $t('r.costs_more', ['amount' => $usd(-$o['saves_cents'])]) }}</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($best !== null)
                <p class="lead" style="margin-top:14px">{{ $best['saves_cents'] > 0 ? $t('r.save_line', ['package' => $best['package'], 'month' => $usd($best['saves_cents']), 'year' => $usd($best['saves_yearly_cents'])]) : $t('r.no_save_line') }}</p>
            @endif
            @if ($s['who_pays'] === 'agents' && $s['agent_plan'] !== null)
                <p class="muted">{{ $t('r.agents_pay', ['plan' => $s['agent_plan']['name'], 'price' => $usd($s['agent_plan']['list_cents']), 'earn' => $usd($s['agent_plan']['earn_cents'])]) }}</p>
            @elseif ($s['who_pays'] === 'brokerage' && $s['agent_plan'] !== null)
                <p class="muted">{{ $t('r.brokerage_pays', ['plan' => $s['agent_plan']['name'], 'price' => $usd($s['agent_plan']['list_cents'])]) }}</p>
            @endif
        @endif
    @endif

    <h2>{{ $t('r.preview') }}</h2>
    <p>{{ $t('r.preview_p') }}</p>
    <p class="actions"><a class="btn" href="{{ $base }}/preview?lang={{ $lang }}">{{ $t('r.see_preview') }}</a> <a class="btn ghost" href="{{ $base }}/preview?lang={{ $lang === 'es' ? 'en' : 'es' }}" lang="{{ $lang === 'es' ? 'en' : 'es' }}">{{ $t('r.see_preview_other') }}</a></p>

    @unless ($hq)
        <h2>{{ $t('r.start') }}</h2>
        <p>{{ $t('r.start_p') }}</p>
        <p><a class="btn" href="/start?check={{ $token }}#signup">{{ $t('r.start_btn') }}</a></p>

        <h2>{{ $t('r.report') }}</h2>
        <p class="muted small">{{ $t('r.report_p') }}</p>
        <form method="post" action="{{ $base }}/delete" class="actions">
            @csrf
            <button class="btn ghost" type="submit">{{ $t('r.delete') }}</button>
        </form>
    @endunless
</div>
@endsection
