<!DOCTYPE html>
@php($readingAttributes = \Modules\Core\Simple\EasierReading::htmlAttributes(auth()->user()))
<html lang="{{ \Modules\Core\Simple\SimpleMode::locale(auth()->user()) === 'es' ? 'es' : str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark']) @foreach ($readingAttributes as $k => $v) {{ $k }}="{{ $v }}" @endforeach>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.255 0.012 267.4);
            }
        </style>

        {{-- Tenant brand accent (back office: primary and focus colours only) --}}
        @php($tenantThemeCss = app(\Modules\Core\Branding\TenantTheme::class)->css())
        @if ($tenantThemeCss)
            <style>{!! $tenantThemeCss !!}</style>
        @endif

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        {{-- PW1: installable app (specs/core/17) --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#06369E">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
