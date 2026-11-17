@extends('sites::layout')

@php($money = fn (int $v): string => '$'.number_format($v))
@section('content')
<main id="main">
    <section class="section">
        <div class="wrap contact-grid">
            <div>
                <h1 class="section-title">{{ $meta['title'] }}</h1>
                <p class="answer">{{ $meta['description'] }}</p>
                <div class="form-card calc-result" role="status" aria-live="polite" data-calc-result>
                    @if ($kind === 'mortgage')
                        <p class="value-range"><strong>{{ $money($result['total']) }}</strong> a month</p>
                        <table class="calc-table">
                            <tr><th scope="row">Principal and interest</th><td>{{ $money($result['principal_interest']) }}</td></tr>
                            <tr><th scope="row">Property tax</th><td>{{ $money($result['tax']) }}</td></tr>
                            <tr><th scope="row">Home insurance</th><td>{{ $money($result['insurance']) }}</td></tr>
                            @if ($result['hoa'] > 0)<tr><th scope="row">HOA dues</th><td>{{ $money($result['hoa']) }}</td></tr>@endif
                            @if ($result['pmi'] > 0)<tr><th scope="row">Mortgage insurance</th><td>{{ $money($result['pmi']) }}</td></tr>@endif
                            <tr><th scope="row">Down payment</th><td>{{ $money($result['down']) }}</td></tr>
                        </table>
                    @elseif ($kind === 'affordability')
                        <p class="value-range">About <strong>{{ $money($result['price']) }}</strong></p>
                        <p class="answer">A monthly housing payment of about {{ $money($result['monthly']) }}, with a loan of {{ $money($result['loan']) }}.</p>
                        <p><a class="btn btn-primary" href="/homes?max_price={{ $result['price'] }}">See homes up to {{ $money($result['price']) }}</a></p>
                    @else
                        <p class="value-range"><strong>{{ $result['better'] === 'buy' ? 'Buying' : 'Renting' }}</strong> costs {{ $money($result['difference']) }} less over {{ $in['stay'] }} {{ $in['stay'] === 1 ? 'year' : 'years' }}</p>
                        <table class="calc-table">
                            <tr><th scope="row">Renting, total</th><td>{{ $money($result['rent_total']) }}</td></tr>
                            <tr><th scope="row">Buying, total after selling</th><td>{{ $money($result['buy_total']) }}</td></tr>
                            <tr><th scope="row">Equity when you sell</th><td>{{ $money($result['equity']) }}</td></tr>
                        </table>
                        <p class="answer">{{ $result['break_even_year'] !== null ? 'Buying pulls ahead in year '.$result['break_even_year'].'.' : 'Buying does not pull ahead within '.$in['stay'].' years.' }}</p>
                    @endif
                    <ul class="lead-muted">@foreach ($result['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
                    <p class="lead-muted">An estimate to help you plan, not a loan offer. A lender can confirm your numbers.</p>
                </div>
            </div>
            <form class="form-card" method="get" action="/calculators/{{ $kind }}#main">
                <h2 class="form-title">Your numbers</h2>
                @if ($kind === 'mortgage')
                    <label>Home price ($) <input name="price" inputmode="numeric" value="{{ number_format($in['price']) }}" required></label>
                    <div class="form-row">
                        <label>Down payment (%) <input name="down_pct" type="number" min="0" max="100" step="0.5" inputmode="decimal" value="{{ $in['down_pct'] }}"></label>
                        <label>Interest rate (%) <input name="rate" type="number" min="0" max="20" step="0.125" inputmode="decimal" value="{{ $in['rate'] }}"></label>
                    </div>
                @elseif ($kind === 'affordability')
                    <label>Yearly income before tax ($) <input name="income" inputmode="numeric" value="{{ number_format($in['income']) }}" required></label>
                    <div class="form-row">
                        <label>Monthly debt payments ($) <input name="debts" inputmode="numeric" value="{{ number_format($in['debts']) }}"></label>
                        <label>Down payment savings ($) <input name="down" inputmode="numeric" value="{{ number_format($in['down']) }}"></label>
                    </div>
                    <label>Interest rate (%) <input name="rate" type="number" min="0" max="20" step="0.125" inputmode="decimal" value="{{ $in['rate'] }}"></label>
                @else
                    <div class="form-row">
                        <label>Monthly rent ($) <input name="rent" inputmode="numeric" value="{{ number_format($in['rent']) }}" required></label>
                        <label>Home price ($) <input name="price" inputmode="numeric" value="{{ number_format($in['price']) }}" required></label>
                    </div>
                    <div class="form-row">
                        <label>Down payment (%) <input name="down_pct" type="number" min="0" max="100" step="0.5" inputmode="decimal" value="{{ $in['down_pct'] }}"></label>
                        <label>Interest rate (%) <input name="rate" type="number" min="0" max="20" step="0.125" inputmode="decimal" value="{{ $in['rate'] }}"></label>
                    </div>
                    <div class="form-row">
                        <label>Years you'll stay <input name="stay" type="number" min="1" max="30" inputmode="numeric" value="{{ $in['stay'] }}"></label>
                        <label>Home value growth (% a year) <input name="appreciation" type="number" min="0" max="15" step="0.5" inputmode="decimal" value="{{ $in['appreciation'] }}"></label>
                    </div>
                    <label>Rent increase (% a year) <input name="rent_growth" type="number" min="0" max="15" step="0.5" inputmode="decimal" value="{{ $in['rent_growth'] }}"></label>
                @endif
                <div class="form-row">
                    <label>Loan term <select name="years">@foreach ([30, 20, 15, 10] as $y)<option value="{{ $y }}" @selected($in['years'] === $y)>{{ $y }} years</option>@endforeach</select></label>
                    <label>Property tax (% a year) <input name="tax_rate" type="number" min="0" max="5" step="0.05" inputmode="decimal" value="{{ $in['tax_rate'] }}"></label>
                </div>
                <div class="form-row">
                    <label>Insurance ($ a month) <input name="insurance" inputmode="numeric" value="{{ $in['insurance'] }}"></label>
                    @if ($kind !== 'rent-vs-buy')<label>HOA ($ a month) <input name="hoa" inputmode="numeric" value="{{ $in['hoa'] }}"></label>@endif
                </div>
                <button class="btn btn-primary" type="submit">Work it out</button>
                <p class="lead-muted">Questions? <a href="/contact">Talk to an agent</a>, no obligation.</p>
            </form>
        </div>
    </section>

    <section class="section section-alt">
        <div class="wrap narrow">
            <h2 class="section-title">Common questions</h2>
            <div class="faq">
                @foreach ($faqs as $f)<details @if ($loop->first) open @endif><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>@endforeach
            </div>
            <p>More calculators: @foreach ($others as $key => $o)<a href="/calculators/{{ $key }}">{{ $o['short'] }}</a>@if (! $loop->last) · @endif @endforeach</p>
        </div>
    </section>
</main>
@endsection
