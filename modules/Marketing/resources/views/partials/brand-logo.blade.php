{{-- BR1: the RightAlly logo (resources/brand/rightally-logo.svg). The wordmark takes the text colour. --}}
<svg class="{{ $class ?? '' }}" viewBox="4.79 4.8 97.4 22.4" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="RightAlly">
    <defs><linearGradient id="ra-{{ $id ?? 'logo' }}" x1="15.97" y1="4.8" x2="15.97" y2="27.2" gradientUnits="userSpaceOnUse"><stop stop-color="#155DFC"/><stop offset="1" stop-color="#06369E"/></linearGradient></defs>
    {!! \Modules\Marketing\Support\BrandLogo::paths($id ?? 'logo') !!}
</svg>
