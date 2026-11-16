@extends('movewise::tools._layout')
@section('tool')
<form class="tool" method="get" action="{{ $u('/move-planner') }}">
    <label>{{ $t('account.move_date') }} <input type="date" name="move_date" value="{{ $move_date }}" required></label>
    <button class="btn">{{ $t('tools.calculate') }}</button>
</form>
@include('movewise::tools._plan', ['plan' => $plan, 'editable' => false])
<p class="note"><a href="{{ $u('/account') }}#plan">{{ $t('tools.save_plan') }}</a></p>
@endsection
