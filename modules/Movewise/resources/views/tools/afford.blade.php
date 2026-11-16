@extends('movewise::tools._layout')
@section('tool')
<form class="tool" method="get" action="{{ $u('/afford') }}">
    <label>{{ $t('tools.income') }} <input name="income" inputmode="numeric" value="{{ $in['income'] ?: '' }}" required></label>
    <label>{{ $t('tools.debts') }} <input name="debts" inputmode="numeric" value="{{ $in['debts'] ?: '' }}"></label>
    <label>{{ $t('tools.down') }} <input name="down" inputmode="numeric" value="{{ $in['down'] ?: '' }}"></label>
    @include('movewise::tools._state')
    <button class="btn">{{ $t('tools.calculate') }}</button>
</form>
@if ($result)
    <section aria-live="polite">
        <h2>{{ $t('tools.budget') }}: ${{ number_format($result['budget']) }}</h2>
        <p class="price">{{ $t('tools.price') }}: ${{ number_format($result['price']) }}</p>
        <h3>{{ $t('tools.how') }}</h3>
        <ol>@foreach ($result['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
        <table class="cost"><tbody>
            @foreach ($result['cost']['lines'] as $line)
                <tr><td><strong>{{ $line['label'] }}</strong><br><span class="small muted">{{ $line['note'] }}</span></td><td class="amt">{{ $line['amount'] !== null ? '$'.number_format($line['amount']) : '–' }}</td></tr>
            @endforeach
        </tbody><tfoot><tr><td>{{ $t('cost.total') }}</td><td class="amt">${{ number_format($result['cost']['total']) }}</td></tr></tfoot></table>
        <p><a class="btn" href="{{ $u('/homes') }}?{{ http_build_query(['max_price' => $result['price'], 'state' => $in['state']]) }}">{{ $t('nav.homes') }}</a></p>
    </section>
@endif
@endsection
