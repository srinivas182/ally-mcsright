<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Crm\Models\Contact;
use Modules\Network\Mail\NetworkMail;
use Modules\Transactions\Models\Deal;

/*
| AR1 (specs/network/01): agent-to-agent referrals between RightAlly brokerages. Client details only after the
| receiving agent accepts; the agreement is recorded; RightAlly takes no share and moves no money.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->pine = $this->onTenantHost('pine');
    $this->pineOwner = User::factory()->owner()->create();
    $this->rosa = User::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Diaz', 'email' => 'rosa@pine.test']);
    $this->acme = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
});

/** Makes $tenant the current host for requests and factories. */
function onNetworkHost(Tenant $tenant): void
{
    test()->useTenantHost($tenant);
}

function joinNetwork(User $owner, User $agent, array $profile): void
{
    test()->actingAs($owner)->post('/settings/network', ['enabled' => true, 'default_fee_percent' => '25'])->assertRedirect();
    test()->actingAs($agent)->post('/network/profile', $profile + ['sides' => 'both', 'languages' => ['English'], 'state_code' => 'TX'])->assertSessionHasNoErrors();
}

it('needs the brokerage to switch the network on, then lets agents find each other by area, side and language', function (): void {
    $this->actingAs($this->ann)->post('/network/profile', ['areas' => ['Austin, TX'], 'sides' => 'both', 'state_code' => 'TX'])->assertSessionHasErrors('network');
    $this->actingAs($this->ann)->get('/settings/network')->assertForbidden();
    joinNetwork($this->owner, $this->ann, ['areas' => ['Austin, TX']]);

    onNetworkHost($this->pine);
    joinNetwork($this->pineOwner, $this->rosa, ['areas' => ['Denver, CO', '80202'], 'sides' => 'buyers', 'languages' => ['English', 'Spanish'], 'state_code' => 'CO',
        'licence' => 'FA100', 'phone' => '(303) 555-0100', 'share_phone' => true, 'public' => true]);
    $rosa = DB::connection(Connections::control())->table('network_profiles')->where('user_id', $this->rosa->id)->where('tenant_id', $this->pine->id)->sole();
    $this->actingAs($this->pineOwner)->post("/settings/network/{$rosa->ulid}/verify", ['checked' => true])->assertRedirect();

    onNetworkHost($this->acme);
    $this->actingAs($this->ann)->get('/network?tab=find&area=Denver&side=buyer&language=Spanish')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('network/index')->has('results', 1)->where('results.0.name', 'Rosa Diaz')
            ->where('results.0.verified', true)->where('results.0.phone', '3035550100')->where('results.0.trust.score', null)->missing('results.0.email'));
    $this->actingAs($this->ann)->get('/network?tab=find&area=Denver&side=seller')->assertInertia(fn (Assert $page) => $page->has('results', 0));

    // Her public page, for search and answer engines, never shows her email.
    auth()->logout();
    $html = (string) $this->get('http://'.config('core.hq_host').'/agents/'.$rosa->code)->assertOk()->getContent();
    expect($html)->toContain('"@type":"RealEstateAgent"')->toContain('Denver, CO')->toContain('Spanish')->toContain('rel="canonical"')
        ->toContain('Licence checked')->not->toContain('rosa@pine.test');
    DB::connection(Connections::control())->table('network_profiles')->where('id', $rosa->id)->update(['public' => false]);
    $this->get('http://'.config('core.hq_host').'/agents/'.$rosa->code)->assertNotFound();
});

it('sends a referral, shares the client only once accepted, and tracks it to the fee', function (): void {
    joinNetwork($this->owner, $this->ann, ['areas' => ['Austin, TX']]);
    onNetworkHost($this->pine);
    joinNetwork($this->pineOwner, $this->rosa, ['areas' => ['Denver, CO']]);
    $rosa = DB::connection(Connections::control())->table('network_profiles')->where('tenant_id', $this->pine->id)->sole();

    onNetworkHost($this->acme);
    $send = ['to' => $rosa->ulid, 'side' => 'buyer', 'area' => 'Denver, CO', 'price_band' => '$500k-$600k', 'first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@example.test'];
    $this->actingAs($this->ann)->post('/network/referrals', $send)->assertSessionHasErrors('client_agreed');
    $this->actingAs($this->ann)->post('/network/referrals', $send + ['client_agreed' => true])->assertRedirect('/network?tab=sent');
    $ref = DB::connection(Connections::control())->table('network_referrals')->sole();
    expect($ref->client)->not->toContain('sam@example.test')->and((string) $ref->fee_percent)->toStartWith('25');
    Notification::assertSentTo($this->rosa, NetworkMail::class, fn (NetworkMail $m): bool => $m->kind === 'received' && ! str_contains(implode(' ', $m->lines), 'Sam'));

    onNetworkHost($this->pine);
    $this->actingAs($this->rosa)->get('/network?tab=received')->assertInertia(fn (Assert $page) => $page->where('referrals.received.0.client', null)
        ->where('referrals.received.0.price_band', '$500k-$600k')->where('referrals.received.0.other.name', 'Ann Agent'));
    $this->actingAs($this->pineOwner)->post("/network/referrals/{$ref->ulid}/accept", ['agree' => true])->assertNotFound(); // not hers
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/accept")->assertSessionHasErrors('agree');
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/accept", ['agree' => true])->assertRedirect();
    $this->actingAs($this->rosa)->get('/network?tab=received')->assertInertia(fn (Assert $page) => $page->where('referrals.received.0.client.email', 'sam@example.test')
        ->where('referrals.received.0.agreement', fn (string $a): bool => str_contains($a, 'agrees to pay 25%')));
    expect(Contact::query()->where('primary_email', 'sam@example.test')->value('owner_user_id'))->toBe($this->rosa->id);
    Notification::assertSentTo($this->ann, NetworkMail::class, fn (NetworkMail $m): bool => $m->kind === 'answered');

    // Under contract on her deal: the referral fee is added to the deal's commission.
    $this->actingAs($this->rosa)->post('/deals', ['type' => 'buyer', 'street' => '9 Pine Rd', 'city' => 'Denver', 'state_code' => 'CO', 'price' => '550000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/update", ['status' => 'closed'])->assertSessionHasErrors('status');
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/update", ['status' => 'under_contract', 'deal' => $deal->ulid])->assertSessionHasNoErrors();
    expect(DB::connection(Connections::cell())->table('tx_referrals')->where('deal_id', $deal->id)->value('brokerage'))->toBe($this->acme->name);
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/update", ['status' => 'closed'])->assertSessionHasErrors('fee_due');
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/update", ['status' => 'closed', 'fee_due' => '4125', 'closed_on' => '2026-10-15'])->assertSessionHasNoErrors();
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/fee", ['step' => 'paid'])->assertSessionHasErrors('fee'); // not hers to confirm
    $this->actingAs($this->rosa)->post("/network/referrals/{$ref->ulid}/fee", ['step' => 'sent'])->assertRedirect();

    onNetworkHost($this->acme);
    $this->actingAs($this->ann)->post("/network/referrals/{$ref->ulid}/fee", ['step' => 'paid'])->assertRedirect();
    $row = DB::connection(Connections::control())->table('network_referrals')->sole();
    expect($row->status)->toBe('closed')->and((int) $row->fee_due_cents)->toBe(412_500)->and($row->fee_paid_at)->not->toBeNull();
});

it('removes the client\'s details when a referral is declined or not answered in 72 hours', function (): void {
    joinNetwork($this->owner, $this->ann, ['areas' => ['Austin, TX']]);
    onNetworkHost($this->pine);
    joinNetwork($this->pineOwner, $this->rosa, ['areas' => ['Denver, CO']]);
    $rosa = DB::connection(Connections::control())->table('network_profiles')->where('tenant_id', $this->pine->id)->sole();
    onNetworkHost($this->acme);
    foreach (['Sam', 'Kim'] as $name) {
        $this->actingAs($this->ann)->post('/network/referrals', ['to' => $rosa->ulid, 'side' => 'seller', 'area' => 'Denver', 'first_name' => $name, 'phone' => '303-555-0111', 'client_agreed' => true]);
    }
    [$first, $second] = DB::connection(Connections::control())->table('network_referrals')->orderBy('id')->pluck('ulid')->all();

    onNetworkHost($this->pine);
    $this->actingAs($this->rosa)->post("/network/referrals/{$first}/decline", ['reason' => 'Fully booked this month'])->assertRedirect();
    $this->travel(73)->hours();
    $this->artisan('network:expire')->assertSuccessful();
    $rows = DB::connection(Connections::control())->table('network_referrals')->orderBy('id')->get();
    expect($rows->pluck('status')->all())->toBe(['declined', 'expired'])->and($rows->pluck('client')->filter()->all())->toBe([])
        ->and($rows[0]->reason)->toBe('Fully booked this month');
    $this->actingAs($this->rosa)->post("/network/referrals/{$second}/accept", ['agree' => true])->assertSessionHasErrors('referral');
});
