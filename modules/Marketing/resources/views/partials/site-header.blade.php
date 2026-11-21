{{-- SITE2: RightAlly's public header (logo, menu, language, log in, start now). --}}
<header class="top"><div class="wrap">
    <a class="brand" href="{{ $home }}">@include('marketing::partials.brand-logo', ['id' => 'head'])</a>
    @php($links = array_merge($nav, [['label' => 'Blog', 'href' => rtrim($home, '/').'/blog']]))
    <nav class="main" aria-label="{{ $L === 'es' ? 'Principal' : 'Main' }}">
        <span class="links">@foreach ($links as $item)<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@endforeach</span>
        @php($langs = ['en' => ['English', $L === 'en' ? $canonical : $other], 'es' => ['Español', $L === 'es' ? $canonical : $other]])
        <details class="lang"><summary aria-label="{{ $L === 'es' ? 'Idioma: Español' : 'Language: English' }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/></svg>{{ $L === 'es' ? 'ES' : 'EN' }}<span aria-hidden="true">▾</span></summary>
            <div>@foreach ($langs as $code => [$label, $href])<a href="{{ $href }}" hreflang="{{ $code }}" lang="{{ $code }}" @if ($code === $L) aria-current="true" @endif>{{ $label }}</a>@endforeach</div>
        </details>
        <a class="login" href="{{ $login ?? '/login' }}">{{ $L === 'es' ? 'Iniciar sesión' : 'Log in' }}</a>
        <a class="btn small" href="{{ $start }}">{{ $L === 'es' ? 'Empezar' : 'Start now' }}</a>
        <details class="menu"><summary aria-label="{{ $L === 'es' ? 'Menú' : 'Menu' }}"><svg class="closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg><svg class="open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></summary>
            <div>@foreach ($links as $item)<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@endforeach
                <a href="{{ $login ?? '/login' }}">{{ $L === 'es' ? 'Iniciar sesión' : 'Log in' }}</a>
                <span class="lang-row" aria-label="{{ $L === 'es' ? 'Idioma' : 'Language' }}">@foreach ($langs as $code => [$label, $href])<a href="{{ $href }}" hreflang="{{ $code }}" lang="{{ $code }}" @if ($code === $L) aria-current="true" @endif>{{ $label }}{{ $code === $L ? ' ✓' : '' }}</a>@endforeach</span></div>
        </details>
    </nav>
</div></header>
