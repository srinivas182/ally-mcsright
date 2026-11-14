@extends('sites::layout')

@section('content')
<main id="main">
    @foreach ($blocks as $block)
        @include('sites::sections.'.$block['type'], ['p' => $block['props'], 'block' => $block, 'first' => $loop->first])
    @endforeach
</main>
@endsection
