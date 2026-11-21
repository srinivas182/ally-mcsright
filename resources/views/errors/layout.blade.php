{{-- BR2: RightAlly's own error pages (instead of the framework's plain ones). No technical detail is ever shown. --}}
<!DOCTYPE html>
<html lang="en"><head>
@include('marketing::partials.plain-head')
<title>@yield('title') · RightAlly</title>
</head>
<body>
<aside class="panel">
    <a href="/" style="color:#fff">@include('marketing::partials.brand-logo', ['class' => 'logo', 'id' => 'panel'])</a>
    <p class="say">The whole brokerage, run from one calm place.</p>
    <p class="safe">@yield('code')</p>
</aside>
<main><div class="box">
    <a class="top" href="/">@include('marketing::partials.brand-logo', ['class' => 'logo', 'id' => 'top'])</a>
    <div class="card">
        <h1>@yield('title')</h1>
        <p class="lead">@yield('message')</p>
        <a class="btn" href="@yield('href', '/')">@yield('action', 'Go to your home page')</a>
        <p class="help">@yield('help', 'If this keeps happening, tell your broker or RightAlly support what you were doing.')</p>
    </div>
</div></main>
</body></html>
