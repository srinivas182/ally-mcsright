<!doctype html><html lang="{{ $lang }}"><body style="font-family:system-ui,sans-serif;color:#14211f;max-width:600px;margin:0 auto;padding:24px">
<p style="font-size:20px;font-weight:700">Movewise Homes</p>
<p>{{ $lang === 'es' ? 'Casas nuevas o con precio rebajado para' : 'New homes and price drops for' }} <strong>{{ $name }}</strong>:</p>
@foreach ($homes as $h)
    @php $label = \Modules\Listings\Search\ListingSearch::label($h); @endphp
    <p style="border-top:1px solid #dfe6e4;padding-top:12px"><a href="{{ $base }}/homes/{{ $h['ulid'] }}/{{ \Modules\Movewise\Alerts::slug($label) }}" style="font-weight:700;color:#0f3d3e">{{ $label }}</a><br>
    {{ isset($h['price']) ? '$'.number_format((int) $h['price']) : '' }} · {{ $h['beds'] ?? '?' }} bd · {{ isset($h['sqft']) ? number_format((int) $h['sqft']).' sq ft' : '' }}<br>
    <span style="font-size:12px;color:#566360">{{ $h['attribution'] ?? '' }}</span></p>
@endforeach
@if ($total > count($homes))<p><a href="{{ $base }}/homes">{{ $lang === 'es' ? 'Ver todas' : 'See all '.$total }}</a></p>@endif
<p style="font-size:12px;color:#566360;border-top:1px solid #dfe6e4;padding-top:12px">{{ $lang === 'es' ? 'Recibes esto porque guardaste esta búsqueda en Movewise.' : 'You get this because you saved this search on Movewise.' }}
<a href="{{ $stop }}">{{ $lang === 'es' ? 'Dejar de recibir estos correos' : 'Stop these emails' }}</a>. Movewise Homes{{ $address !== '' ? ', '.$address : '' }}.</p>
</body></html>
