@extends('movewise::layout')
@section('main')
<div class="wrap prose" style="padding-top:32px">
    <h1>{{ $t('connect.bring_agent_h') }}</h1>
    <p class="lead">{{ $t('connect.bring_agent_b') }}</p>
    <p><a class="btn" href="{{ $u('/account') }}">{{ $t('nav.account') }}</a></p>
</div>
@endsection
