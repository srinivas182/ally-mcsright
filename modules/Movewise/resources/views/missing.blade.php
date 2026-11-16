@extends('movewise::layout', ['seo' => ['title' => 'Page not found | Movewise Homes', 'description' => 'This page has moved or the home is no longer for sale.', 'indexable' => false, 'canonical' => $site['base'].'/', 'jsonld' => '{}']])
@section('main')
<div class="wrap" style="padding:48px 16px">
    <h1>{{ $t('missing.title') }}</h1>
    <p class="lead">{{ $t('missing.lead') }}</p>
    <p><a class="btn" href="{{ $u('/homes') }}">{{ $t('missing.cta') }}</a></p>
</div>
@endsection
