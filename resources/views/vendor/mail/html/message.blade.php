@php($b = $mailBrand ?? \Modules\Core\Mail\MailBrand::current())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$b['url']">
{{ $b['name'] }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer: EM3, who it is from and how to reach them. --}}
<x-slot:footer>
<x-mail::footer>
@if ($b['platform'])
**RightAlly** · The all-in-one platform for US real estate brokerages

[{{ preg_replace('#^https?://#', '', $b['url']) }}]({{ $b['url'] }}) · Questions? Just reply to this email.
@else
**{{ $b['name'] }}** · [{{ preg_replace('#^https?://#', '', $b['url']) }}]({{ $b['url'] }})

Sent with RightAlly on behalf of {{ $b['name'] }}.
@endif

© {{ date('Y') }} {{ $b['name'] }}. All rights reserved.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
