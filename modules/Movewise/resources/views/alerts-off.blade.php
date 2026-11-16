@extends('movewise::layout')
@section('main')
<div class="wrap prose" style="padding-top:32px">
    <h1>{{ $t('alerts.off') }}</h1>
    <p class="lead">{{ $t('alerts.off_body') }}</p>
    <p><a class="btn" href="{{ $u('/account') }}">{{ $t('nav.account') }}</a></p>
</div>
@endsection
