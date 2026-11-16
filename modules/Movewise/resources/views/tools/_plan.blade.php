{{-- The move plan, dated back from moving day. Tickable when it is the person's own saved plan. --}}
@foreach ($plan as $step)
    <h3>{{ $step['weeks'] > 0 ? $t('tools.weeks_before', ['n' => $step['weeks']]) : ($step['weeks'] === 0 ? $t('tools.week_of') : $t('tools.after')) }}@if ($step['date']) <span class="muted small">({{ $step['date'] }})</span>@endif</h3>
    <ul class="tasks">
        @foreach ($step['tasks'] as $task)
            <li>@if ($editable ?? false)<label><input type="checkbox" name="done[]" value="{{ $task['key'] }}" @checked($task['done'])> {{ $task['label'] }}</label>@else{{ $task['label'] }}@endif</li>
        @endforeach
    </ul>
@endforeach
