<x-mail::message>
{{-- Greeting: EM4, a plain, professional "Hello Maria Lopez," (not a heading). --}}
@if (! empty($greeting))
{{ $greeting }}
@elseif ($level === 'error')
@lang('Something needs your attention.')
@else
@lang('Hello,')
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Kind regards,')<br>
{{ ($mailBrand ?? \Modules\Core\Mail\MailBrand::current())['team'] }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If the \":actionText\" button doesn't work, copy this link into your web browser:",
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
