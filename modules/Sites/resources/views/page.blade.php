@extends('sites::layout')

@section('content')
@php($builder = request()->is('_preview/*') && ! request()->is('_preview/post/*'))
<main id="main">
    @foreach ($blocks as $block)
        @if ($builder)<div class="ra-block" data-ra-block="{{ $block['id'] }}" style="display:contents">@endif
        @include('sites::sections.'.$block['type'], ['p' => $block['props'], 'block' => $block, 'first' => $loop->first])
        @if ($builder)</div>@endif
    @endforeach
</main>
@if ($builder)
    {{-- WB1: in the editor's preview, hovering a section outlines it and clicking it opens it in the editor. --}}
    <style>.ra-block>*{cursor:pointer}.ra-block>*:hover{outline:2px dashed #2563eb;outline-offset:-2px}.ra-block.ra-picked>*{outline:3px solid #2563eb;outline-offset:-3px}</style>
    <script nonce="{{ Vite::cspNonce() }}">
    (function () {
        if (window.parent === window) return;
        var blocks = Array.prototype.slice.call(document.querySelectorAll('.ra-block'));
        function pick(id, scroll) {
            blocks.forEach(function (b) {
                var on = b.getAttribute('data-ra-block') === id;
                b.classList.toggle('ra-picked', on);
                if (on && scroll && b.firstElementChild) b.firstElementChild.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        }
        blocks.forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                var id = b.getAttribute('data-ra-block');
                pick(id, false);
                window.parent.postMessage({ source: 'rightally-builder', block: id }, '*');
            }, true);
        });
        window.addEventListener('message', function (e) {
            if (e.source === window.parent && e.data && e.data.source === 'rightally-editor' && typeof e.data.block === 'string') pick(e.data.block, true);
        });
    })();
    </script>
@endif
@endsection
