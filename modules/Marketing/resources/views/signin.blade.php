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
        <p class="lead">Every brokerage has its own RightAlly address. Type yours and we will take you to its log in page.</p>
        <form method="get" action="/login/go">
            <label for="address">Your brokerage's address</label>
            <div class="field">
                <input id="address" name="address" value="{{ $typed }}" placeholder="yourbrokerage" autocomplete="off" autocapitalize="none" spellcheck="false" required @if ($error) aria-invalid="true" aria-describedby="address-error" @endif autofocus>
                <span aria-hidden="true">.{{ $domain }}</span>
            </div>
            @if ($error)<p class="err" id="address-error" role="alert">{{ $error }}</p>@endif
            <button class="btn" type="submit">Continue to log in</button>
        </form>
        <p class="help">Not sure of the address? It is in your invitation email, or ask your broker.<br>New to RightAlly? <a href="{{ $home }}">See how it works</a>.</p>
    </div>
    <p class="foot">RightAlly team: <a href="{{ $hq }}">log in to HQ</a></p>
</div></main>
</body></html>
