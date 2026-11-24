@props(['url'])
@php($b = $mailBrand ?? \Modules\Core\Mail\MailBrand::current())
{{-- EM3: the sender's logo (or name), on a thin band of their colour. --}}
<tr>
<td class="header" style="border-top: 4px solid {{ $b['color'] }};">
<a href="{{ $b['url'] }}" style="display: inline-block; text-decoration: none;">
@if (! empty($b['logo']))
<img src="{{ $b['logo'] }}" class="logo" alt="{{ $b['name'] }}" height="40" style="height: 40px; max-height: 40px; width: auto; border: 0;">
@else
<span class="brand-name" style="color: #111827;">{{ $b['name'] }}</span>
@endif
</a>
</td>
</tr>
