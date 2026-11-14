@if ($p['url'])
@php($yt = preg_match('#youtube\.com/watch\?v=([A-Za-z0-9_-]{11})#', $p['url'], $m) === 1 ? $m[1] : null)
@php($vimeo = $yt === null && preg_match('#vimeo\.com/(\d+)#', $p['url'], $v) === 1 ? $v[1] : null)
<section class="section" id="{{ $block['id'] }}">
    <div class="wrap narrow">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        {{-- A light placeholder: the player (privacy-enhanced) loads only when the visitor presses play. --}}
        <div class="video" data-video="{{ $yt ? 'https://www.youtube-nocookie.com/embed/'.$yt.'?autoplay=1&rel=0' : 'https://player.vimeo.com/video/'.$vimeo.'?autoplay=1&dnt=1' }}"
             @if ($yt) style="--poster: url('https://i.ytimg.com/vi/{{ $yt }}/hqdefault.jpg')" @endif>
            <button type="button" class="video-play" aria-label="Play video{{ $p['title'] !== '' ? ': '.$p['title'] : '' }}">▶</button>
        </div>
        @if ($p['caption'] !== '')<p class="fine">{{ $p['caption'] }}</p>@endif
    </div>
</section>
@endif
