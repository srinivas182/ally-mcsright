@if (count($site['states']) > 1)
    <label>{{ $t('tools.state') }}
        <select name="state">@foreach ($site['states'] as $s)<option value="{{ $s['code'] }}" @selected($in['state'] === $s['code'])>{{ $s['name'] }}</option>@endforeach</select>
    </label>
@else
    <input type="hidden" name="state" value="{{ $in['state'] }}">
@endif
