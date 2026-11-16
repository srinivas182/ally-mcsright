@extends('movewise::layout', ['seo' => ['title' => 'Page not found | Movewise Homes', 'description' => 'This page has moved or the home is no longer for sale.', 'indexable' => false, 'canonical' => $site['base'].'/', 'jsonld' => '{}']])
@section('main')
<div class="wrap" style="padding:48px 16px">
    <h1>That page isn't here</h1>
    <p class="lead">The home may have sold or come off the market.</p>
    <p><a class="btn" href="/homes">See homes for sale</a></p>
</div>
@endsection
