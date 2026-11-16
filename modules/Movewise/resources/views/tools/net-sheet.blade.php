@extends('movewise::tools._layout')
@section('tool')
<form class="tool" method="get" action="{{ $u('/net-sheet') }}">
    <label>{{ $t('tools.sale_price') }} <input name="price" inputmode="numeric" value="{{ $in['price'] ?: '' }}" required></label>
    <label>{{ $t('tools.payoff') }} <input name="payoff" inputmode="numeric" value="{{ $in['payoff'] ?: '' }}"></label>
    <label>{{ $t('tools.listing_pct') }} <input name="listing_pct" inputmode="decimal" value="{{ $in['listing_pct'] ?: '' }}"></label>
    <label>{{ $t('tools.buyer_pct') }} <input name="buyer_pct" inputmode="decimal" value="{{ $in['buyer_pct'] ?: '' }}"></label>
    <label>{{ $t('tools.repairs') }} <input name="repairs" inputmode="numeric" value="{{ $in['repairs'] ?: '' }}"></label>
    @include('movewise::tools._state')
    <button class="btn">{{ $t('tools.calculate') }}</button>
</form>
@if ($result)
    <section aria-live="polite">
        <table class="cost" style="margin-top:20px"><tbody>
            @foreach ($result['lines'] as $line)
                <tr><td><strong>{{ $line['label'] }}</strong><br><span class="small muted">{{ $line['note'] }}</span></td><td class="amt">{{ $line['amount'] < 0 ? '−$'.number_format(-$line['amount']) : '$'.number_format($line['amount']) }}</td></tr>
            @endforeach
        </tbody><tfoot><tr><td>{{ $t('tools.net_total') }}</td><td class="amt">{{ $result['net'] < 0 ? '−$'.number_format(-$result['net']) : '$'.number_format($result['net']) }}</td></tr></tfoot></table>
    </section>
@endif
@endsection
