{{-- SITE2: RightAlly's public footer. --}}
<footer class="site"><div class="wrap">
    <div class="cols">
        <div>
            <a class="brand" href="{{ $home }}">@include('marketing::partials.brand-logo', ['id' => 'foot', 'class' => 'foot-logo'])</a>
            <p>{{ $L === 'es' ? 'Todo lo que su correduría necesita, en un solo lugar.' : 'Everything your brokerage runs on, in one place.' }}</p>
        </div>
        <div><h3>{{ $L === 'es' ? 'Producto' : 'Product' }}</h3><ul>
            @foreach ($nav as $item)<li><a href="{{ $item['href'] }}">{{ $item['label'] }}</a></li>@endforeach
            <li><a href="{{ rtrim($home, '/').'/blog' }}">Blog</a></li>
        </ul></div>
        <div><h3>{{ $L === 'es' ? 'Empiece' : 'Get started' }}</h3><ul>
            <li><a href="{{ $start }}">{{ $L === 'es' ? 'Registrarse' : 'Sign up' }}</a></li>
            <li><a href="{{ $healthCheck }}">{{ $L === 'es' ? 'Chequeo gratuito' : 'Free Health Check' }}</a></li>
            <li><a href="{{ $login ?? '/login' }}">{{ $L === 'es' ? 'Iniciar sesión' : 'Log in' }}</a></li>
        </ul></div>
        <div><h3>{{ $L === 'es' ? 'Idioma' : 'Language' }}</h3><ul>
            <li><a href="{{ $other }}" hreflang="{{ $L === 'es' ? 'en' : 'es' }}">{{ $L === 'es' ? 'English' : 'Español' }}</a></li>
            <li><a href="{{ rtrim($home, '/') }}/sitemap.xml">Sitemap</a></li>
        </ul></div>
    </div>
    <div class="legal">
        <p>© {{ date('Y') }} RightAlly</p>
        <p><a href="{{ $legal['privacy'] }}">{{ $L === 'es' ? 'Política de privacidad' : 'Privacy policy' }}</a></p>
        <p><a href="{{ $legal['terms'] }}">{{ $L === 'es' ? 'Términos de uso' : 'Terms of use' }}</a></p>
    </div>
</div></footer>
