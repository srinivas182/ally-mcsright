@extends('movewise::layout')
@section('main')
<div class="wrap" style="padding-top:32px">
    <nav class="small muted" aria-label="{{ $t('tools.title') }}"><a href="{{ $u('/afford') }}">{{ $t('tools.afford') }}</a> · <a href="{{ $u('/net-sheet') }}">{{ $t('tools.net') }}</a> · <a href="{{ $u('/move-planner') }}">{{ $t('tools.planner') }}</a></nav>
    <h1>{{ $title }}</h1>
    @yield('tool')
    <p class="small muted">{{ $t('tools.not_advice') }}</p>
    @foreach ($seo['faqs'] as [$q, $a])
        <h2>{{ $q }}</h2><p class="prose">{{ $a }}</p>
    @endforeach
</div>
@endsection
