@extends('movewise::layout')
@section('main')
<div class="wrap prose" style="padding-top:32px">
    @if ($done)
        <h1>{{ $t('connect.withdrawn') }}</h1>
    @else
        <h1>{{ $t('connect.withdraw_h') }}</h1>
        <p class="lead">{{ $t('connect.withdraw_b') }}</p>
        <form method="post"><button class="btn">{{ $t('connect.withdraw') }}</button></form>
    @endif
</div>
@endsection
