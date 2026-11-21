{{-- SITE1: what a price includes, read live from HQ › Packages; the first few, then "and N more". --}}
@if (($lines ?? []) !== [])
    @php($shownLines = array_slice($lines, 0, \Modules\Marketing\PlanIncludes::SHOWN))
    <ul class="includes" aria-label="{{ ($L ?? 'en') === 'es' ? 'Incluye' : 'Includes' }}">
        @foreach ($shownLines as $line)<li>{{ $line }}</li>@endforeach
        @if (count($lines) > count($shownLines))<li class="more">{{ ($L ?? 'en') === 'es' ? 'y '.(count($lines) - count($shownLines)).' más' : 'and '.(count($lines) - count($shownLines)).' more' }}</li>@endif
    </ul>
@endif
