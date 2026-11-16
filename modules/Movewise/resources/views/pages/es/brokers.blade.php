@extends('movewise::pages._page')
@section('body')
@include('movewise::pages.es._courtesy')
<p class="lead">En cada estado donde opera Movewise, un corretaje con licencia es el corredor responsable de Movewise y supervisa su actividad de bienes raíces allí.</p>
@forelse ($site['states'] as $s)
    <h2>{{ $s['name'] }}</h2>
    @if ($s['partner'])
        <p><strong>{{ $s['partner']['name'] }}</strong><br>Licencia del corretaje {{ $s['partner']['licence'] }}<br>Corredor administrador: {{ $s['partner']['broker'] }}
        @if ($s['partner']['phone'])<br>Teléfono: {{ $s['partner']['phone'] }}@endif
        @if ($s['partner']['email'])<br>Correo: <a href="mailto:{{ $s['partner']['email'] }}">{{ $s['partner']['email'] }}</a>@endif</p>
    @endif
    @if ($s['code'] === 'TX')
        <p><a href="{{ $s['partner']['iabs'] ?? 'https://www.trec.texas.gov/forms/information-about-brokerage-services' }}" lang="en">Texas Real Estate Commission Information About Brokerage Services</a> (en inglés)<br>
        <a href="https://www.trec.texas.gov/forms/consumer-protection-notice" lang="en">Texas Real Estate Commission Consumer Protection Notice</a> (en inglés)</p>
    @endif
@empty
    <p>Movewise todavía no está abierto en ningún estado.</p>
@endforelse
<h2>Agentes</h2>
<p>Cada agente en Movewise tiene licencia en el estado de la casa, a través de su propio corretaje. Su número de licencia está en su perfil, con un enlace a la búsqueda de licencias del estado.</p>
@endsection
