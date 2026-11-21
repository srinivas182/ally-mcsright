<!DOCTYPE html>
<html lang="{{ $L }}"><head>
@include('marketing::partials.plain-head')
<title>{{ $L === 'es' ? 'Página no encontrada' : 'Page not found' }} · RightAlly</title>
</head>
<body>
<aside class="panel">
    <a href="{{ $home }}" style="color:#fff">@include('marketing::partials.brand-logo', ['class' => 'logo', 'id' => 'panel'])</a>
    <p class="say">{{ $L === 'es' ? 'Todo lo que su correduría necesita, en un solo lugar.' : 'The whole brokerage, run from one calm place.' }}</p>
    <p class="safe">rightally.app</p>
</aside>
<main><div class="box">
    <a class="top" href="{{ $home }}">@include('marketing::partials.brand-logo', ['class' => 'logo', 'id' => 'top'])</a>
    <div class="card">
        <h1>{{ $L === 'es' ? 'No encontramos esa página' : 'We could not find that page' }}</h1>
        <p class="lead">{{ $L === 'es' ? 'Puede que el enlace sea antiguo o tenga un error.' : 'The link may be old or mistyped.' }}</p>
        <a class="btn" href="{{ $home }}">{{ $L === 'es' ? 'Ir al inicio de RightAlly' : 'Go to the RightAlly home page' }}</a>
        <p class="help">{{ $L === 'es' ? '¿Busca su cuenta?' : 'Looking for your account?' }} <a href="/login">{{ $L === 'es' ? 'Iniciar sesión' : 'Log in' }}</a></p>
    </div>
</div></main>
</body></html>
