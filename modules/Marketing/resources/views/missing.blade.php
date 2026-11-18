<!DOCTYPE html>
<html lang="{{ $L }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{{ $L === 'es' ? 'Página no encontrada' : 'Page not found' }} · RightAlly</title>
<style>body{font:19px/1.6 system-ui,sans-serif;margin:0;padding:48px 16px;max-width:40rem;margin:auto}a{color:#4338ca}</style></head>
<body><h1>{{ $L === 'es' ? 'No encontramos esa página' : 'We could not find that page' }}</h1>
<p><a href="{{ $home }}">{{ $L === 'es' ? 'Ir al inicio de RightAlly' : 'Go to the RightAlly home page' }}</a></p></body></html>
