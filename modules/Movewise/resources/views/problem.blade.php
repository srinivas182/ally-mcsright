@extends('movewise::layout')
@section('main')
<div class="wrap prose" style="padding-top:32px">
    <h1>{{ \Modules\Movewise\I18n::locale() === 'es' ? 'No se pudo hacer' : "That didn't go through" }}</h1>
    <div class="note" role="alert"><ul style="margin:0;padding-left:18px">@foreach ($messages as $m)<li>{{ $m }}</li>@endforeach</ul></div>
    <p><a class="btn" href="{{ $u('/account') }}">{{ $t('nav.account') }}</a></p>
</div>
@endsection
