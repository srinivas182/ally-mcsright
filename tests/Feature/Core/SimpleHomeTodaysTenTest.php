<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Consent;

/*
| SX4: one simple home — "Today's 10" with big Call and Text buttons, texts only with consent and between 8am and
| 9pm where the person is.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // 10am in Austin
    $this->onTenantHost('acme');
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'ui_mode' => 'simple']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
    $contacts = app(ManageContacts::class);
    $this->maria = $contacts->create(['first_name' => 'Maria', 'last_name' => 'Lopez', 'phone' => '5125550100', 'lifecycle' => 'lead'], $this->agent);
    $this->sam = $contacts->create(['first_name' => 'Sam', 'last_name' => 'Noconsent', 'phone' => '5125550101', 'lifecycle' => 'lead'], $this->agent);
    $contacts->create(['first_name' => 'Zed', 'last_name' => 'Bobs', 'phone' => '5125550102', 'lifecycle' => 'lead'], $this->bob);
    app(Consent::class)->optInSms($this->maria, 'verbal', 'Agreed to texts on the phone', $this->agent);
});

it('puts today\'s people first, with Call for everyone we may call and Text only for people who agreed', function (): void {
    $this->actingAs($this->agent)->get('/simple')->assertOk()->assertInertia(fn (Assert $p) => $p->component('simple/home')
        ->has('people', 2)
        ->where('people', fn ($people): bool => collect($people)->pluck('name')->sort()->values()->all() === ['Maria Lopez', 'Sam Noconsent'])
        ->where('people', fn ($people): bool => collect($people)->firstWhere('name', 'Maria Lopez')['text'] === true
            && collect($people)->firstWhere('name', 'Sam Noconsent')['text'] === false
            && collect($people)->every(fn ($x): bool => $x['call'] === true && $x['reason'] === 'New lead, not contacted yet'))
        ->where('simple.words.call', 'Call'));
});

it('hides Text outside 8am to 9pm where the person is, and says when it can be used', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-02 03:30', 'UTC')); // 10:30pm in Austin
    $this->actingAs($this->agent)->get('/simple')->assertInertia(fn (Assert $p) => $p
        ->where('people', fn ($people): bool => ($m = collect($people)->firstWhere('name', 'Maria Lopez')) !== null && $m['text'] === false
            && str_contains((string) $m['text_wait'], 'between 8am and 9pm')));
});

it('drops a person off once the call is logged', function (): void {
    $this->actingAs($this->agent)->post('/contacts/'.$this->maria->ulid.'/activities', ['type' => 'call', 'direction' => 'out', 'outcome' => 'connected'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/simple')->assertInertia(fn (Assert $p) => $p->has('people', 1)->where('people.0.name', 'Sam Noconsent'));
});
