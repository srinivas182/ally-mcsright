@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
@php($b = $mailBrand ?? \Modules\Core\Mail\MailBrand::current())
{{-- EM3: the main button in the sender's colour. --}}
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener" @if ($color === 'primary') style="background-color: {{ $b['color'] }}; border-bottom: 12px solid {{ $b['color'] }}; border-left: 24px solid {{ $b['color'] }}; border-right: 24px solid {{ $b['color'] }}; border-top: 12px solid {{ $b['color'] }};" @endif>{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
