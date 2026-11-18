@php
    $t = fn (string $k, array $w = []) => \Modules\Billing\HealthCheck\HealthCopy::t($lang, $k, $w);
    $faq = array_map(fn ($n) => [$t("faq{$n}q"), $t("faq{$n}a")], [1, 2, 3, 4, 5]);
    $jsonld = json_encode(['@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'Organization', '@id' => 'https://'.config('core.hq_host').'/#org', 'name' => 'RightAlly', 'url' => 'https://'.config('core.hq_host').'/'],
        ['@type' => 'WebPage', '@id' => $canonical, 'url' => $canonical, 'name' => $t('h1'), 'inLanguage' => $lang, 'publisher' => ['@id' => 'https://'.config('core.hq_host').'/#org'], 'description' => $t('meta_desc')],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $old = fn ($k, $d = null) => old($k, $d);
@endphp
@extends('billing::health.layout', ['title' => $t('meta_title'), 'indexable' => true, 'canonical' => $canonical, 'alternates' => $alternates, 'jsonld' => $jsonld, 'lang' => $lang,
    'description' => $t('meta_desc'), 'home' => $lang === 'es' ? '/health-check/es' : '/health-check'])

@section('main')
<section class="hero"><div class="wrap">
    <p class="small"><a href="{{ $t('other_lang_href') }}" lang="{{ $lang === 'es' ? 'en' : 'es' }}" hreflang="{{ $lang === 'es' ? 'en' : 'es' }}">{{ $t('other_lang') }}</a></p>
    <h1>{{ $t('h1') }}</h1>
    <p class="lead">{{ $t('lead') }}</p>
    @if (session('deleted'))<p class="card" role="status">{{ $t('deleted') }}</p>@endif
    <ul class="promises">
        @foreach ([1, 2, 3, 4] as $n)<li><strong>{{ $t("p{$n}t") }}</strong>{{ $t("p{$n}d") }}</li>@endforeach
    </ul>
</div></section>

<div class="wrap">
    @if ($errors->any())
        <div class="errors" role="alert"><strong>{{ $t('check_these') }}</strong><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="post" action="/health-check" enctype="multipart/form-data" novalidate>
        @csrf
        <input type="hidden" name="lang" value="{{ $lang }}">
        <div class="hp" aria-hidden="true"><label>Fax<input type="text" name="fax" tabindex="-1" autocomplete="off"></label></div>

        <fieldset><legend>{{ $t('s1') }}</legend>
            <div class="two">
                <label>{{ $t('brokerage') }}<input type="text" name="brokerage" required maxlength="160" value="{{ $old('brokerage') }}" autocomplete="organization"></label>
                <label>{{ $t('agents') }}<input type="number" name="agents" required min="1" max="20000" inputmode="numeric" value="{{ $old('agents') }}"></label>
                <label>{{ $t('your_name') }}<input type="text" name="contact_name" required maxlength="120" value="{{ $old('contact_name') }}" autocomplete="name"></label>
                <label>{{ $t('your_email') }}<input type="email" name="email" required maxlength="190" value="{{ $old('email') }}" autocomplete="email"></label>
                <label>{{ $t('state') }}<input type="text" name="state" maxlength="2" value="{{ $old('state') }}" autocomplete="address-level1"></label>
            </div>
        </fieldset>

        <fieldset><legend>{{ $t('s2') }}</legend>
            @foreach ($questions as $key => $q)
                <p id="q-{{ $key }}"><strong>{{ $t("q.{$key}") }}</strong></p>
                <div class="choices" role="radiogroup" aria-labelledby="q-{{ $key }}">
                    @foreach ($q['answers'] as $value => $answer)
                        <label><input type="radio" name="answers[{{ $key }}]" value="{{ $value }}" @checked($old('answers.'.$key) === $value) required> {{ $t("a.{$key}.{$value}") }}</label>
                    @endforeach
                </div>
            @endforeach
            <p id="q-who"><strong>{{ $t('who_q') }}</strong></p>
            <div class="choices" role="radiogroup" aria-labelledby="q-who">
                @foreach ($whoPays as $value => $label)
                    <label><input type="radio" name="who_pays" value="{{ $value }}" @checked($old('who_pays', 'brokerage') === $value)> {{ $t("w.{$value}") }}</label>
                @endforeach
            </div>
        </fieldset>

        <fieldset><legend>{{ $t('s3') }}</legend>
            <p class="muted small">{{ $t('s3_help') }}</p>
            @for ($i = 0; $i < 8; $i++)
                <div class="toolrow">
                    <label>{{ $t('tool_n', ['n' => $i + 1]) }}
                        <select name="tools[{{ $i }}][key]">
                            <option value="">{{ $t('choose') }}</option>
                            @foreach ($tools as $key => $tool)<option value="{{ $key }}" @selected($old('tools.'.$i.'.key') === $key)>{{ $key === 'other' && $lang === 'es' ? 'Otra cosa' : $tool['name'] }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ $t('monthly_cost') }}<input type="number" name="tools[{{ $i }}][cost]" min="0" step="0.01" inputmode="decimal" value="{{ $old('tools.'.$i.'.cost') }}"></label>
                    <label class="check"><input type="checkbox" name="tools[{{ $i }}][per_agent]" value="1" @checked($old('tools.'.$i.'.per_agent'))> {{ $t('per_agent') }}</label>
                </div>
            @endfor
            <label>{{ $t('other_name') }}<input type="text" name="other_name" maxlength="60" value="{{ $old('other_name') }}"></label>
        </fieldset>

        <fieldset><legend>{{ $t('s4') }}</legend>
            <label>{{ $t('website') }}<input type="text" name="website_url" maxlength="255" placeholder="yourbrokerage.com" value="{{ $old('website_url') }}" inputmode="url"></label>
            <label class="check"><input type="checkbox" name="website_ok" value="1" @checked($old('website_ok'))> {{ $t('website_ok') }}</label>
            <div class="two">
                <label>{{ $t('brand_color') }}<input type="color" name="brand_color" value="{{ $old('brand_color', '#4338ca') }}"></label>
                <label>{{ $t('logo') }}<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
            </div>
        </fieldset>

        <fieldset><legend>{{ $t('s5') }}</legend>
            <label class="check"><input type="checkbox" name="contact_ok" value="1" @checked($old('contact_ok'))> {{ $t('contact_ok') }}</label>
            <p class="muted small">{{ $t('contact_help') }}</p>
            @include('core::bot-check', ['bot' => app(\Modules\Core\Security\BotProtection::class)->widget()])
            <button class="btn" type="submit">{{ $t('submit') }}</button>
        </fieldset>
    </form>

    <section class="faq" aria-labelledby="faq">
        <h2 id="faq">{{ $t('faq_title') }}</h2>
        @foreach ($faq as [$q, $a])<details><summary>{{ $q }}</summary><p>{{ $a }}</p></details>@endforeach
    </section>
</div>
@endsection
