@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:32px">
    <h1>{{ $t('account.title') }}</h1>
    <p class="muted">{{ $t('account.signed_in_as', ['email' => $person->email]) }}</p>

    @if ($agents !== [])
        <h2 id="agent">{{ $t('account.agent') }}</h2>
        @foreach ($agents as $a)
            <div class="note" style="margin-bottom:8px">
                @if ($a['status'] === 'offered')
                    {{ $t('account.waiting', ['name' => $a['name'], 'time' => $a['by']]) }}
                @elseif ($a['status'] === 'accepted')
                    {{ $t('account.working', ['name' => $a['name']]) }}
                @else
                    {{ $t('account.'.$a['status'], ['name' => $a['name']]) }}
                    @if ($a['status'] === 'expired' && ! $a['waited'])
                        <form method="post" action="{{ $u('/account/connections/'.$a['ulid'].'/wait') }}" style="display:inline"><button class="save">{{ $t('account.wait', ['name' => $a['name']]) }}</button></form>
                    @endif
                    @if ($a['show'])<a class="save" style="display:inline-flex;align-items:center;text-decoration:none" href="{{ $u('/connect/'.$a['show']) }}">{{ $t('account.choose_other') }}</a>@endif
                @endif
            </div>
        @endforeach
    @endif

    <h2>{{ $t('account.homes') }}</h2>
    @if ($homes === [])
        <p>{{ $t('account.none_homes') }} <a href="{{ $u('/homes') }}">{{ $t('nav.homes') }}</a></p>
    @else
        @include('movewise::_cards', ['homes' => $homes])
    @endif

    <h2>{{ $t('account.searches') }}</h2>
    @forelse ($searches as $s)
        <form class="note" method="post" action="{{ $u('/account/searches/'.$s->ulid) }}" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:8px">
            <strong style="flex:1 1 220px">{{ $s->name }}</strong>
            <label>{{ $t('account.alerts') }}
                <select name="frequency">@foreach (['daily', 'weekly', 'off'] as $f)<option value="{{ $f }}" @selected($s->frequency === $f)>{{ $t('account.'.$f) }}</option>@endforeach</select>
            </label>
            <button class="save">{{ $t('save') }}</button>
            <button class="save" name="delete" value="1">{{ $t('account.remove') }}</button>
        </form>
    @empty
        <p>{{ $t('account.none_searches') }}</p>
    @endforelse

    <h2 id="plan">{{ $t('account.plan') }}</h2>
    <form class="plan" method="post" action="{{ $u('/account/plan') }}">
        <label>{{ $t('account.move_date') }} <input type="date" name="move_date" value="{{ $move_date }}"></label>
        @include('movewise::tools._plan', ['plan' => $plan, 'editable' => true])
        <button class="btn">{{ $t('account.save_plan') }}</button>
    </form>

    <h2>{{ $t('account.privacy') }}</h2>
    <p>{{ $t('account.privacy_body') }}</p>
    <p><a class="btn ghost" href="{{ $u('/account/export') }}">{{ $t('account.export') }}</a></p>
    <form method="post" action="{{ $u('/account/delete') }}" class="note" style="display:grid;gap:8px;max-width:520px">
        <label><input type="checkbox" name="confirm" value="1" required> {{ $t('account.delete_confirm') }}</label>
        <button class="btn">{{ $t('account.delete') }}</button>
    </form>
    <form method="post" action="{{ $u('/account/sign-out') }}" style="margin-top:16px"><button class="btn ghost">{{ $t('account.signout') }}</button></form>
</div>
@endsection
