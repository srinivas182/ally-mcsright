{{-- Lead-IQ (LQ1): the person picks a time with the agent, from the link the AI assistant sent. Private, not indexed. --}}
@php($es = $lang === 'es')
<!DOCTYPE html>
<html lang="{{ $es ? 'es' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $brand }} · {{ $es ? 'Escoger una hora' : 'Pick a time' }}</title>
    <style>
        :root{--ink:#111827;--muted:#4b5563;--line:#e5e7eb;--bg:#f8fafc;--card:#fff;--brand:#4338ca}
        @media (prefers-color-scheme:dark){:root{--ink:#f3f4f6;--muted:#a1a1aa;--line:#2e3340;--bg:#0b0f19;--card:#111827;--brand:#a5b4fc}}
        body{margin:0;background:var(--bg);color:var(--ink);font:400 18px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
        main{max-width:640px;margin:40px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:24px}
        h1{font-size:28px;line-height:1.2;margin:0 0 12px}.muted{color:var(--muted)}
        .slots{display:grid;gap:10px;margin:16px 0;padding:0;list-style:none}
        .slots label{display:flex;gap:12px;align-items:center;min-height:56px;padding:0 16px;border:1px solid var(--line);border-radius:12px;cursor:pointer}
        .slots input{width:22px;height:22px}
        button{min-height:56px;padding:0 26px;border-radius:14px;border:0;background:var(--brand);color:#fff;font:700 19px/1 inherit;cursor:pointer}
        @media (prefers-color-scheme:dark){button{color:#111827}}
        :focus-visible{outline:3px solid var(--brand);outline-offset:2px}.err{color:#b91c1c}
    </style>
</head>
<body>
<main>
    <div class="card">
        <p class="muted">{{ $brand }}</p>
        @if ($booked)
            <h1>{{ $es ? '¡Listo! Su hora está reservada.' : "You're booked." }}</h1>
            <p>{{ $es ? 'Le llamaremos a la hora que escogió. Si necesita cambiarla, responda al mensaje.' : "We'll call you at the time you chose. To change it, just reply to our message." }}</p>
        @elseif ($slots === [])
            <h1>{{ $es ? 'No hay horas libres esta semana' : 'No open times this week' }}</h1>
            <p>{{ $es ? 'Un agente le llamará para fijar una hora.' : 'An agent will call you to set a time.' }}</p>
        @else
            <h1>{{ $es ? 'Escoja una hora' : 'Pick a time' }}{{ $agent ? ($es ? ' con '.$agent : ' with '.$agent) : '' }}</h1>
            <p class="muted">{{ $es ? 'Hola' : 'Hi' }} {{ $name }}. {{ $es ? 'Es una llamada corta para conocer lo que busca.' : "It's a short call to understand what you're looking for." }}</p>
            @if ($errors->any())<p class="err" role="alert">{{ $errors->first() }}</p>@endif
            <form method="post" action="{{ $action }}">
                @csrf
                <ul class="slots" role="radiogroup" aria-label="{{ $es ? 'Horas libres' : 'Open times' }}">
                    @foreach ($slots as $i => $slot)
                        <li><label><input type="radio" name="slot" value="{{ $slot['value'] }}" @checked($i === 0)> {{ $slot['label'] }}</label></li>
                    @endforeach
                </ul>
                <button type="submit">{{ $es ? 'Reservar esta hora' : 'Book this time' }}</button>
            </form>
        @endif
    </div>
</main>
</body>
</html>
