<section class="section" id="{{ $block['id'] }}">
    <div class="wrap narrow prose">
        @if ($p['title'] !== '')<h2 class="section-title">{{ $p['title'] }}</h2>@endif
        @foreach (preg_split("/\n{2,}/", $p['body']) ?: [] as $para)<p>{!! nl2br(e($para)) !!}</p>@endforeach
    </div>
</section>
