@if ($pages['last'] > 1)
    <nav class="pager" aria-label="Pages">
        @if ($pages['prev'])<a class="btn btn-ghost" rel="prev" href="{{ $pages['prev'] }}">Previous</a>@endif
        <span>Page {{ $pages['page'] }} of {{ number_format($pages['last']) }}</span>
        @if ($pages['next'])<a class="btn btn-ghost" rel="next" href="{{ $pages['next'] }}">Next</a>@endif
    </nav>
@endif
