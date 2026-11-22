<!DOCTYPE html>
<html lang="en"><head>
@include('marketing::partials.plain-head')
<title>Log in · RightAlly</title>
</head>
<body>
<aside class="panel">
    <a href="{{ $home }}" style="color:#fff">@include('marketing::partials.brand-logo', ['class' => 'logo', 'id' => 'panel'])</a>
    <p class="say">The whole brokerage, run from one calm place.</p>
    <p class="safe">Protected by two-step sign-in and encryption.</p>
</aside>
<main><div class="box">
    <a class="top" href="{{ $home }}">@include('marketing::partials.brand-logo', ['class' => 'logo', 'id' => 'top'])</a>
    <div class="card">
        <h1>Log in to RightAlly</h1>
        @if ($choices !== [])
            <p class="lead">{{ $typed }} signs in at more than one brokerage. Which one?</p>
            <ul class="choices">@foreach ($choices as $c)<li><a class="btn" href="{{ $c['url'] }}">Continue to {{ $c['name'] }}</a></li>@endforeach</ul>
            <p class="help"><a href="/login">Use a different email</a></p>
        @elseif ($mode === 'address')
            <p class="lead">Type your brokerage's RightAlly address and we will take you to its log in page.</p>
            <form method="get" action="/login/go">
                <label for="address">Your brokerage's address</label>
                <div class="field">
                    <input id="address" name="address" value="{{ $typed }}" placeholder="yourbrokerage" autocomplete="off" autocapitalize="none" spellcheck="false" required @if ($error) aria-invalid="true" aria-describedby="login-error" @endif autofocus>
                    <span aria-hidden="true">.{{ $domain }}</span>
                </div>
                @if ($error)<p class="err" id="login-error" role="alert">{{ $error }}</p>@endif
                <button class="btn" type="submit">Continue to log in</button>
            </form>
            <p class="help"><a href="/login">Use your email instead</a></p>
        @else
            <p class="lead">Type your email. We will find your brokerage and take you to its log in page.</p>
            <form method="get" action="/login/go">
                <label for="email">Your email</label>
                <input id="email" class="email" type="email" name="email" value="{{ $typed }}" placeholder="you@yourbrokerage.com" autocomplete="email" autocapitalize="none" spellcheck="false" required @if ($error) aria-invalid="true" aria-describedby="login-error" @endif autofocus>
                @if ($error)<p class="err" id="login-error" role="alert">{{ $error }}</p>@endif
                <button class="btn" type="submit">Continue</button>
            </form>
            <p class="help">Know your brokerage's address? <a href="/login?by=address">Use it instead</a>.<br>New to RightAlly? <a href="{{ $home }}">See how it works</a>.</p>
        @endif
    </div>
    <p class="foot">RightAlly team: <a href="{{ $hq }}">log in to HQ</a></p>
</div></main>
</body></html>
