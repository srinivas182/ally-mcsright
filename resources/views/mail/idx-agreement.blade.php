<x-mail::message>
Hello,

Please find attached the signed IDX agreement from **{{ $facts['Brokerage'] ?? 'our brokerage' }}** for {{ $board }}, with its signature certificate.

<x-mail::table>
| | |
|:--|:--|
@foreach ($facts as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
</x-mail::table>

RightAlly is the brokerage's technology provider and sends this on its behalf. If anything is missing, simply reply to this email.

Kind regards,<br>
The RightAlly onboarding team
</x-mail::message>
