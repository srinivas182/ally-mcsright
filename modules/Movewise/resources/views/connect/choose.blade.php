@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:32px">
    <h1>{{ $t('connect.choose_h') }}</h1>
    @include('movewise::connect._errors')
    @if ($open)
        <p class="note">{{ $t('account.agent') }}: <a href="{{ $u('/account') }}#agent">{{ $t('nav.account') }}</a></p>
    @elseif ($cards === [])
        <p class="lead">{{ $t('connect.none') }}</p>
    @else
        <p class="lead">{{ $t('connect.choose_lead') }}</p>
        @if ($person->phone_verified_at === null)
            <section class="note" style="display:grid;gap:10px;max-width:560px">
                <h2 style="margin:0">{{ $t('connect.phone_h') }}</h2>
                <p class="small muted" style="margin:0">{{ $t('connect.phone_lead') }}</p>
                @if ($code_sent)
                    <p role="status" style="margin:0">{{ $t('connect.code_sent') }}</p>
                    <form class="tool" method="post" action="{{ $u('/connect/'.$show.'/phone') }}">
                        <label>{{ $t('connect.code') }} <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>
                        <button class="btn">{{ $t('connect.verify') }}</button>
                    </form>
                @else
                    <form class="tool" method="post" action="{{ $u('/connect/'.$show.'/phone') }}">
                        <label>{{ $t('connect.phone') }} <input name="phone" type="tel" autocomplete="tel" required maxlength="20"></label>
                        <button class="btn">{{ $t('connect.send_code') }}</button>
                    </form>
                @endif
            </section>
        @else
            <p class="small muted">{{ $t('connect.verified', ['phone' => \Modules\Movewise\Matching\Connections::mask((string) $person->phone)]) }}</p>
        @endif
        <ul class="grid" style="margin-top:20px">
            @foreach ($cards as $c)
                @php($p = $c['profile'])
                <li class="card" itemscope itemtype="https://schema.org/RealEstateAgent">
                    <div class="body" style="gap:8px">
                        <div style="display:flex;gap:12px;align-items:center">
                            @if (! empty($p['photo']))<img src="{{ $p['photo'] }}" alt="" width="72" height="72" style="width:72px;height:72px;border-radius:50%;aspect-ratio:1">@endif
                            <div><strong itemprop="name" style="font-size:19px">{{ $p['display_name'] ?? '' }}</strong><br><span class="small muted">{{ $c['brokerage'] }}</span></div>
                        </div>
                        <span class="small">{{ $t('connect.licence', ['n' => $c['licence']]) }} @if ($c['lookup'])· <a href="{{ $c['lookup'] }}" rel="noopener" target="_blank">{{ $t('connect.check_licence') }}</a>@endif</span>
                        <span class="small muted">{{ $t('connect.years', ['n' => $p['years'] ?? 0]) }} · {{ collect($p['languages'] ?? ['en'])->map(fn ($l) => $l === 'es' ? 'Español' : 'English')->join(', ') }}</span>
                        @if ($c['rating']['count'] > 0)
                            <a class="small" href="{{ $c['profile_url'] }}" target="_blank">{{ str_repeat('★', (int) round((float) $c['rating']['average'])) }} {{ $t('agent.rating', ['avg' => $c['rating']['average'], 'n' => $c['rating']['count']]) }}</a>
                        @else
                            <span class="small muted">{{ $t('connect.new_agent') }}</span>
                        @endif
                        <p itemprop="description" style="margin:0">{{ $p['bio'] ?? '' }}</p>
                        @if ($person->phone_verified_at !== null)
                            <form method="post" action="{{ $u('/connect/'.$show.'/choose') }}" style="display:grid;gap:8px">
                                <input type="hidden" name="agent" value="{{ $c['ulid'] }}">
                                <fieldset style="border:0;padding:0;display:grid;gap:4px"><legend class="small">{{ $t('connect.how') }}</legend>
                                    <label><input type="radio" name="method" value="text" checked> {{ $t('connect.text') }}</label>
                                    <label><input type="radio" name="method" value="call"> {{ $t('connect.call') }}</label>
                                    @if ($c['slots'] !== [])
                                        <label><input type="radio" name="method" value="time"> {{ $t('connect.time') }}</label>
                                        <select name="slot" aria-label="{{ $t('connect.time') }}">@foreach ($c['slots'] as $s)<option value="{{ $s->toIso8601String() }}">{{ $s->setTimezone('America/Chicago')->format('D j M, g:i a T') }}</option>@endforeach</select>
                                    @else
                                        <span class="small muted">{{ $t('connect.no_slots') }}</span>
                                    @endif
                                </fieldset>
                                <button class="btn">{{ $t('connect.choose', ['name' => $p['display_name'] ?? '']) }}</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="small muted">{{ $t('connect.consent_note') }}</p>
        <p><a href="{{ $u('/homes') }}">{{ $t('connect.not_now') }}</a></p>
    @endif
</div>
@endsection
