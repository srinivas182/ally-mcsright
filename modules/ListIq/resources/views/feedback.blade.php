<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Showing feedback</title>
<style>
    :root { --bg:#f6f6f4; --fg:#1f2328; --card:#fff; --muted:#5b616b; --line:#d9d7d0; --accent:#1d4ed8 }
    @media (prefers-color-scheme: dark) { :root { --bg:#111317; --fg:#e8e9eb; --card:#1b1e24; --muted:#a3a9b3; --line:#30343b; --accent:#7aa2ff } }
    body { margin:0; background:var(--bg); color:var(--fg); font:18px/1.55 system-ui, sans-serif; }
    main { max-width:560px; margin:6vh auto; padding:24px 16px; background:var(--card); border-radius:10px; }
    fieldset { border:1px solid var(--line); border-radius:8px; margin:0 0 14px; padding:12px; }
    label { display:block; margin:6px 0; } textarea { width:100%; font:inherit; padding:8px; border:1px solid var(--line); border-radius:6px; background:transparent; color:inherit; }
    button { font:inherit; padding:12px 20px; border:0; border-radius:8px; background:var(--accent); color:#fff; cursor:pointer; }
    .err { color:#b42318; } .muted { color:var(--muted); font-size:15px; }
</style></head>
<body><main>
@if ($showing === null)
    <h1>This link isn't valid</h1><p>Please use the link in the latest email.</p>
@elseif ($showing->feedback_at !== null)
    <h1>Thank you!</h1><p>Your feedback on {{ $prep?->street }} has been passed on to the listing agent and the sellers.</p>
@else
    <h1>{{ $prep?->street }}</h1>
    <p class="muted">Three quick questions. Your answers go to the listing agent and the sellers, without your name.</p>
    @if ($error)<p class="err" role="alert">{{ $error }}</p>@endif
    <form method="post">
        <fieldset><legend>How interested were your buyers?</legend>
            @foreach ([5 => 'Very — may make an offer', 4 => 'Interested', 3 => 'Maybe', 2 => 'Not very', 1 => 'Not at all'] as $v => $l)
                <label><input type="radio" name="interest" value="{{ $v }}" required> {{ $l }}</label>
            @endforeach
        </fieldset>
        <fieldset><legend>What did they think of the price?</legend>
            @foreach (['low' => 'Good value', 'right' => 'About right', 'high' => 'Too high'] as $v => $l)
                <label><input type="radio" name="price" value="{{ $v }}" required> {{ $l }}</label>
            @endforeach
        </fieldset>
        <label>Anything else? (optional)<textarea name="comments" rows="3" maxlength="1000"></textarea></label>
        <p><button type="submit">Send</button></p>
    </form>
@endif
</main></body></html>
