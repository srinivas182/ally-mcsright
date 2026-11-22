{{-- SU1: the state, from the states RightAlly works in (HQ › IDX coverage › States). Params: $signupStates (code => name), $value. --}}
<label>State
    <select name="state" autocomplete="address-level1">
        <option value="">Choose your state</option>
        @foreach ($signupStates ?? [] as $code => $name)
            <option value="{{ $code }}" @selected(strtoupper((string) $value) === $code)>{{ $name }}</option>
        @endforeach
    </select>
</label>
