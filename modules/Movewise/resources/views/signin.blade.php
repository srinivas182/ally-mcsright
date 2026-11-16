@extends('movewise::layout')
@section('main')
<div class="wrap prose" style="padding-top:32px">
    <h1>{{ $t('signin.title') }}</h1>
    <div data-signin-page>@include('movewise::_signin', ['suffix' => '-page'])</div>
</div>
@endsection
