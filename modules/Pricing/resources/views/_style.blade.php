@php
    $c = $brand['colors'] ?? [];
    $primary = $c['primary'] ?? '#111111';
    $accent = $c['accent'] ?? '#C9A227';
    $heading = $pdf ? 'DejaVu Serif' : (($brand['fonts']['heading'] ?? 'Georgia').', Georgia, serif');
    $body = $pdf ? 'DejaVu Sans' : (($brand['fonts']['body'] ?? 'Arial').', Arial, sans-serif');
@endphp
<style>
    :root { --primary: {{ $primary }}; --accent: {{ $accent }}; --bg: #f7f6f2; --card: #ffffff; --ink: #1f2328; --muted: #5b616b; --line: #e3e1db; }
    @if (! $pdf)
    @media (prefers-color-scheme: dark) { :root { --bg: #121417; --card: #1b1e23; --ink: #ecedef; --muted: #a7adb6; --line: #2c3037; } }
    @endif
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--ink); font-family: {!! $body !!}; font-size: {{ $pdf ? '11pt' : '17px' }}; line-height: 1.55; }
    .wrap { max-width: 860px; margin: 0 auto; padding: {{ $pdf ? '0' : '24px 16px 48px' }}; }
    header.brand { display: flex; align-items: center; gap: 16px; padding: 18px 0; border-bottom: 3px solid var(--accent); margin-bottom: 20px; }
    header.brand img.logo { max-height: 46px; max-width: 180px; }
    header.brand .who { margin-left: auto; text-align: right; font-size: .9em; color: var(--muted); }
    header.brand img.head { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; }
    h1, h2 { font-family: {!! $heading !!}; color: var(--ink); line-height: 1.2; }
    h1 { font-size: {{ $pdf ? '22pt' : '30px' }}; margin: 0 0 6px; }
    h2 { font-size: {{ $pdf ? '15pt' : '22px' }}; margin: 28px 0 10px; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 18px; margin-bottom: 14px; }
    .range { font-size: {{ $pdf ? '20pt' : '32px' }}; font-weight: 700; color: var(--primary); }
    .muted { color: var(--muted); font-size: .9em; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { font-weight: 600; color: var(--muted); font-size: .85em; }
    .num { text-align: right; white-space: nowrap; }
    .comp { display: {{ $pdf ? 'block' : 'grid' }}; grid-template-columns: 160px 1fr; gap: 14px; }
    .comp img { width: 160px; height: 110px; object-fit: cover; border-radius: 6px; }
    .grid3 { display: {{ $pdf ? 'block' : 'grid' }}; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .pill { display: inline-block; padding: 2px 10px; border-radius: 999px; background: var(--accent); color: #fff; font-size: .8em; }
    footer { margin-top: 30px; font-size: .8em; color: var(--muted); }
    @media (max-width: 640px) { .comp { grid-template-columns: 1fr; } .comp img { width: 100%; height: 180px; } .grid3 { grid-template-columns: 1fr; } }
    @media print { body { background: #fff; } .card { break-inside: avoid; } }
</style>
