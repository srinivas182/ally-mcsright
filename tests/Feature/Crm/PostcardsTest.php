<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Postcards\PostcardRun;
use Modules\Crm\SmartLists;

/* Sprint C7c: postcards — to a smart list, price first, Fair Housing checked, addresses verified, mailed by Lob
   the next business day, paid with credits, refunds for undeliverable and cancelled cards. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC')); // Monday
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('direct_mail'), true, ['api_key' => 'live_abc', 'price_4x6_cents' => '100', 'price_6x9_cents' => '150'], null,
        User::factory()->make(['id' => 999999]));
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    app(CreditLedger::class)->add($this->ann->id, 1_000, 'adjust', 'Test credits', $this->owner);
    $contacts = app(ManageContacts::class);
    foreach ([['Amy', '12 Oak Ln'], ['Bob', '1 Nowhere Rd'], ['Cal', null], ['Dee', '5 Elm St']] as [$name, $street]) {
        $c = $contacts->create(['first_name' => $name, 'last_name' => 'Owner', 'email' => strtolower($name).'@x.test'], $this->ann);
        if ($street !== null) {
            $contacts->update($c, ['address_line1' => $street, 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704'], $this->ann);
        }
        if ($name === 'Dee') {
            app(Tags::class)->attach($c, 'Do not mail');
        }
    }
    $this->list = app(SmartLists::class)->save($this->ann, 'Oak farm', ['lifecycle' => ['lead', 'active', 'sphere', 'past_client']], false)->ulid;
    Http::fake([
        'api.lob.com/v1/us_verifications' => fn (HttpRequest $r) => Http::response($r['primary_line'] === '1 Nowhere Rd' ? ['deliverability' => 'undeliverable']
            : ['deliverability' => 'deliverable', 'primary_line' => strtoupper((string) $r['primary_line']), 'secondary_line' => '', 'components' => ['city' => 'AUSTIN', 'state' => 'TX', 'zip_code' => '78704']]),
        'api.lob.com/v1/postcards' => Http::response(['id' => 'psc_123', 'send_date' => '2026-06-02']),
        'api.lob.com/v1/postcards/*' => Http::response(['id' => 'psc_123', 'deleted' => true]),
    ]);
});

function postcard(array $over = []): array
{
    return $over + ['kind' => 'just_listed', 'size' => '4x6', 'smart_list' => test()->list, 'headline' => 'Just listed on Oak Lane', 'message' => 'A bright 3 bed home just came up near you.',
        'link' => 'https://acme.rightally.site/homes/x', 'from_name' => 'Acme Realty', 'from_line1' => '1 Main St', 'from_city' => 'Austin', 'from_state' => 'TX', 'from_zip' => '78701'];
}

it('shows the card and the price first, then mails deliverable addresses next business day and refunds the rest', function (): void {
    $this->actingAs($this->ann)->post('/postcards', postcard(['message' => 'Perfect for families!']))->assertSessionHasErrors('message');
    $this->actingAs($this->ann)->post('/postcards', postcard())->assertRedirect();
    $run = PostcardRun::query()->sole();
    expect($run->only(['status', 'cards', 'cents_per_card', 'credits']))->toBe(['status' => 'draft', 'cards' => 2, 'cents_per_card' => 100, 'credits' => 200]) // Amy and Bob: Cal has no address, Dee is "Do not mail"
        ->and($run->link)->toContain('utm_source=postcard&utm_medium=direct_mail&utm_campaign=rightally-');
    Http::assertNothingSent();
    $back = (string) $this->actingAs($this->ann)->get('/postcards/'.$run->ulid.'/preview?side=back')->assertOk()->getContent();
    expect($back)->toContain('Hi Jane')->toContain('<svg')->toContain('Equal Housing Opportunity');
    $this->actingAs($this->ann)->get('/postcards?run='.$run->ulid)->assertInertia(fn (Assert $p) => $p->component('grow/postcards')->where('runs.0.status', 'draft'));

    $this->actingAs($this->ann)->post('/postcards/'.$run->ulid.'/approve')->assertSessionHasNoErrors();
    $run->refresh();
    expect($run->only(['status', 'cards', 'undeliverable', 'credits', 'credits_refunded']))->toBe(['status' => 'scheduled', 'cards' => 1, 'undeliverable' => 1, 'credits' => 100, 'credits_refunded' => 0]) // only the deliverable card is paid for
        ->and($run->send_date?->toDateString())->toBe('2026-06-02')
        ->and(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe(900);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.lob.com/v1/postcards' && $r['to']['address_line1'] === '12 OAK LN' && $r['use_type'] === 'marketing'
        && $r['size'] === '4x6' && $r['send_date'] === '2026-06-02' && $r->hasHeader('Authorization', 'Basic '.base64_encode('live_abc:')) && str_contains((string) $r['back'], 'Hi Amy'));
    $this->actingAs($this->ann)->post('/postcards/'.$run->ulid.'/approve')->assertSessionHasErrors('run'); // only once
});

it('cancels before printing and gives the credits back', function (): void {
    $this->actingAs($this->ann)->post('/postcards', postcard());
    $run = PostcardRun::query()->sole();
    $this->actingAs($this->ann)->post('/postcards/'.$run->ulid.'/approve');
    $this->actingAs($this->owner)->post('/postcards/'.$run->ulid.'/cancel')->assertNotFound(); // only the sender
    $this->actingAs($this->ann)->post('/postcards/'.$run->ulid.'/cancel')->assertSessionHasNoErrors();
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE' && $r->url() === 'https://api.lob.com/v1/postcards/psc_123');
    expect($run->fresh()?->status)->toBe('cancelled')->and(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe(1_000);

    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC'));
    $this->actingAs($this->ann)->post('/postcards', postcard());
    $late = PostcardRun::query()->latest('id')->firstOrFail();
    $this->actingAs($this->ann)->post('/postcards/'.$late->ulid.'/approve');
    $this->travelTo(CarbonImmutable::parse('2026-06-05 15:00', 'UTC'));
    $this->actingAs($this->ann)->post('/postcards/'.$late->ulid.'/cancel')->assertSessionHasErrors('run'); // printing
});
