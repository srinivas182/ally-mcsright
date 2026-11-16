@extends('movewise::layout')
@section('main')
<div class="wrap prose" style="padding-top:32px">
    <h1>{{ $title }}</h1>
    @yield('body')
</div>
@endsection
