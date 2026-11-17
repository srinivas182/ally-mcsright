<header class="brand">
    @if (! empty($brand['logo']))<img class="logo" src="{{ $brand['logo'] }}" alt="{{ $brand['brokerage'] ?? '' }}">@else<strong>{{ $brand['brokerage'] ?? '' }}</strong>@endif
    <div class="who">
        <strong>{{ $brand['details']['display_name'] ?? '' }}</strong><br>
        {{ $brand['details']['phone'] ?? '' }}@if (! empty($brand['details']['phone']) && ! empty($brand['details']['email'])) · @endif{{ $brand['details']['email'] ?? '' }}
        @if (! empty($brand['details']['licence_line']))<br>{{ $brand['details']['licence_line'] }}@endif
    </div>
    @if (! empty($brand['headshot']))<img class="head" src="{{ $brand['headshot'] }}" alt="">@endif
</header>
