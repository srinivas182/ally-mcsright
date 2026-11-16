@php
    $usd = fn (int $c) => \Modules\Billing\HealthCheck\HealthScore::usd($c);
    $s = $r['savings'];
    $best = $s['best'] !== null ? $s['options'][$s['best']] : null;
    $base = $hq ? '/hq/health-checks/'.$r['ulid'] : '/health-check/r/'.$token;
    $statusText = ['good' => 'Good', 'fix' => 'Worth fixing', 'urgent' => 'Fix first', 'not_checked' => 'Not checked'];
    $scanning = $r['website_url'] !== null && $r['website'] === null;
@endphp
@extends('billing::health.layout', ['title' => 'Health Check: '.$r['brokerage'].' | RightAlly', 'description' => 'Private Brokerage Health Check report.'])

@if ($scanning)
    @push('head')<meta http-equiv="refresh" content="6">@endpush
@endif

@section('main')
<section class="hero"><div class="wrap">
    @if ($hq)<p class="small"><a href="/hq/health-checks">← All health checks</a></p>@endif
    <h1>{{ $r['brokerage'] }}: Health Check</h1>
    <div class="actions">
        <span class="score" aria-label="Overall score {{ $r['scores']['overall'] }} out of 100">{{ $r['scores']['overall'] }}</span>
        <p class="lead">Hello {{ $r['contact_name'] }}. Here is how your brokerage of {{ $r['agents'] }} agent{{ $r['agents'] === 1 ? '' : 's' }} does today, and the first thing to fix in each area.</p>
    </div>
</div></section>

<div class="wrap">
    <h2>Your scores</h2>
    <ul class="areas">
        @foreach ($r['scores']['areas'] as $a)
            <li class="{{ $a['status'] }}">
                <h3>{{ $a['title'] }} <span class="pill {{ $a['status'] }}">{{ $statusText[$a['status']] }}{{ $a['score'] !== null ? ' · '.$a['score'] : '' }}</span></h3>
                <p class="muted">{{ $a['finding'] }}</p>
                <p><strong>With RightAlly:</strong> {{ $a['fix'] }}</p>
            </li>
        @endforeach
    </ul>

    <h2>Website check</h2>
    @if ($r['website_url'] === null)
        <p class="muted">You did not give a website address.</p>
    @elseif ($scanning)
        <p role="status">Checking {{ $r['website_url'] }}… this page refreshes by itself.</p>
    @elseif ($r['website']['status'] !== 'done')
        <p>We could not check {{ $r['website_url'] }}: {{ $r['website']['message'] }}</p>
    @else
        <p>{{ $r['website']['url'] }} passed checks worth <strong>{{ $r['website']['score'] }}%</strong>. Things to fix are listed first.</p>
        <div class="tablewrap"><table>
            <thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">What to do</th></tr></thead>
            <tbody>
            @foreach (collect($r['website']['checks'])->sortBy('ok') as $c)
                <tr><td>{{ $c['label'] }}</td><td>@if ($c['ok'])<span class="ok">✓ Yes</span>@else<span class="no">✗ No</span>@endif</td><td>{{ $c['ok'] ? '' : $c['fix'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    <h2>Savings comparison</h2>
    @if ($s['now_cents'] === 0)
        <p class="muted">You did not list any software costs, so there is nothing to compare yet.</p>
    @else
        <p>Today you pay about <strong>{{ $usd($s['now_cents']) }} a month</strong> ({{ $usd(intdiv($s['now_cents'], max(1, $r['agents']))) }} per agent).</p>
        <div class="tablewrap"><table>
            <thead><tr><th scope="col">Software</th><th scope="col" class="amt">Monthly</th><th scope="col">With RightAlly</th></tr></thead>
            <tbody>
            @foreach ($s['replaced'] as $t)<tr><td>{{ $t['name'] }}</td><td class="amt">{{ $usd($t['monthly_cents']) }}</td><td><span class="ok">Replaced.</span> {{ $t['note'] }}</td></tr>@endforeach
            @foreach ($s['kept'] as $t)<tr><td>{{ $t['name'] }}</td><td class="amt">{{ $usd($t['monthly_cents']) }}</td><td><strong>{{ $t['partly'] ? 'Partly covered, kept in the total.' : 'Kept.' }}</strong> {{ $t['note'] }}</td></tr>@endforeach
            </tbody>
        </table></div>

        @if ($s['options'] === [])
            <p class="muted">RightAlly prices are being updated; we'll email you the comparison.</p>
        @else
            <h3 style="margin-top:20px">After switching</h3>
            <div class="tablewrap"><table>
                <thead><tr><th scope="col">RightAlly package</th><th scope="col" class="amt">Package</th>@if ($s['who_pays'] === 'brokerage')<th scope="col" class="amt">Agent plans</th>@endif<th scope="col" class="amt">Kept tools</th><th scope="col" class="amt">New monthly total</th><th scope="col" class="amt">Difference</th></tr></thead>
                <tbody>
                @foreach ($s['options'] as $i => $o)
                    <tr @if ($i === $s['best']) style="background:var(--brand-2)" @endif>
                        <td>{{ $o['package'] }}@if ($i === $s['best']) <span class="pill good">Best fit</span>@endif</td>
                        <td class="amt">{{ $usd($o['package_cents']) }}</td>
                        @if ($s['who_pays'] === 'brokerage')<td class="amt">{{ $usd($o['agents_cents']) }}</td>@endif
                        <td class="amt">{{ $usd($s['kept_cents']) }}</td>
                        <td class="amt">{{ $usd($o['after_cents']) }}</td>
                        <td class="amt">{!! $o['saves_cents'] >= 0 ? '<span class="ok">Save '.e($usd($o['saves_cents'])).'</span>' : '<span class="no">Costs '.e($usd(-$o['saves_cents'])).' more</span>' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($best !== null)
                <p class="lead" style="margin-top:14px">
                    @if ($best['saves_cents'] > 0)
                        With {{ $best['package'] }} you would save about <strong>{{ $usd($best['saves_cents']) }} a month</strong>, or <strong>{{ $usd($best['saves_yearly_cents']) }} a year</strong>, and your agents sign in to one place.
                    @else
                        RightAlly would not lower your software bill. The value would come from the fixes above: faster replies to leads, cleaner files and faster payouts.
                    @endif
                </p>
            @endif
            @if ($s['who_pays'] === 'agents' && $s['agent_plan'] !== null)
                <p class="muted">Your agents would pay for their own RightAlly plan ({{ $s['agent_plan']['name'] }}, {{ $usd($s['agent_plan']['list_cents']) }} a month at list price). Your brokerage earns about {{ $usd($s['agent_plan']['earn_cents']) }} a month from each agent's plan. That is not counted in the savings above.</p>
            @elseif ($s['who_pays'] === 'brokerage' && $s['agent_plan'] !== null)
                <p class="muted">Agent plans are counted at list price ({{ $s['agent_plan']['name'] }}, {{ $usd($s['agent_plan']['list_cents']) }} a month per agent).</p>
            @endif
        @endif
    @endif

    <h2>RightAlly in your colours</h2>
    <p>See the screen your agents would open each morning, with your name, colour and logo.</p>
    <p class="actions"><a class="btn" href="{{ $base }}/preview">See my preview</a> <a class="btn ghost" href="{{ $base }}/preview?lang=es">Ver en español</a></p>

    @unless ($hq)
        <h2>Your report</h2>
        <p class="muted small">This page has its own private link, which we emailed you. It works for 180 days.</p>
        <form method="post" action="{{ $base }}/delete" class="actions">
            @csrf
            <button class="btn ghost" type="submit">Delete my report</button>
        </form>
    @endunless
</div>
@endsection
