@if (($errors_ ?? []) !== [])
    <div class="note" role="alert"><ul style="margin:0;padding-left:18px">@foreach ($errors_ as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif
