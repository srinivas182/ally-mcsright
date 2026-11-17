<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Transactions\DealInbox;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* LM1 (specs/transactions/30): per-deal email-in, the listings board, the week-by-week cash forecast, and the franchise and royalty report. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->other = User::factory()->create(['first_name' => 'Quinn']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20 franchise', ['split' => ['mode' => 'percentage', 'agent_percent' => '80'],
        'pre_split' => [['code' => 'royalty', 'name' => 'Franchise royalty', 'calc' => 'percent', 'amount' => '6', 'payable_to' => 'franchisor']]]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function lmDeal(User $agent, string $type = 'listing', string $street = '9 Elm St'): Deal
{
    test()->actingAs($agent)->post('/deals', ['type' => $type, 'street' => $street, 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000'])->assertRedirect();

    return Deal::query()->latest('id')->firstOrFail();
}

function lmContract(User $agent, Deal $deal, string $closing): Deal
{
    test()->actingAs($agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => $closing])
        ->assertSessionHasNoErrors();

    return $deal->fresh() ?? $deal;
}

it('gives each deal an email address that files attachments into its unsorted documents', function (): void {
    config(['crm.inbound_email_secret' => 'inbound-secret']);
    $deal = lmDeal($this->agent);
    $page = $this->actingAs($this->agent)->get("/deals/{$deal->ulid}")->assertOk();
    $address = '';
    $page->assertInertia(function (Assert $p) use (&$address): void {
        $p->where('emailIn', function ($a) use (&$address): bool {
            $address = (string) $a;

            return str_starts_with($address, 'deal-') && str_ends_with($address, '@inbound.rightally.app');
        });
    });
    // The same address every time.
    $this->actingAs($this->agent)->get("/deals/{$deal->ulid}")->assertInertia(fn (Assert $p) => $p->where('emailIn', $address));

    $pdf = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $payload = ['OriginalRecipient' => $address, 'From' => 'Title Co <closer@title.test>', 'Subject' => 'Commitment', 'TextBody' => 'Attached.', 'MessageID' => 'm-1',
        'Attachments' => [['Name' => 'title commitment.pdf', 'ContentType' => 'application/pdf', 'Content' => $pdf], ['Name' => 'evil.exe', 'ContentType' => 'application/octet-stream', 'Content' => base64_encode('MZ...')]]];
    $this->withBasicAuth('postmark', 'inbound-secret')->postJson('/webhooks/inbound/lead-email', $payload)->assertOk()->assertJson(['status' => 'filed']);
    $doc = Document::query()->where('deal_id', $deal->id)->sole();
    expect($doc->via)->toBe('email')->and($doc->item_id)->toBeNull()->and($doc->filename)->toBe('title commitment.pdf');

    // The same file again is kept once; a wrong token or secret does nothing.
    $this->withBasicAuth('postmark', 'inbound-secret')->postJson('/webhooks/inbound/lead-email', $payload)->assertJson(['status' => 'no_files']);
    $this->withBasicAuth('postmark', 'inbound-secret')->postJson('/webhooks/inbound/lead-email', [...$payload, 'OriginalRecipient' => 'deal-'.str_repeat('x', 40).'@inbound.rightally.app'])
        ->assertJson(['status' => 'ignored']);
    $this->withBasicAuth('postmark', 'wrong')->postJson('/webhooks/inbound/lead-email', $payload)->assertUnauthorized();
    expect(Document::query()->where('deal_id', $deal->id)->count())->toBe(1);
    expect(app(DealInbox::class)->address($deal->fresh() ?? $deal))->toBe($address);
});

it('shows a listings board from getting ready to sold, with agreements about to end', function (): void {
    $ready = lmDeal($this->agent, 'listing', '1 Ready Rd');
    $active = lmDeal($this->agent, 'listing', '2 Active Ave');
    $this->actingAs($this->agent)->post("/deals/{$active->ulid}/listing", ['listing_expires_on' => '2026-06-15', 'mls_number' => 'MLS77'])->assertSessionHasNoErrors();
    $pending = lmContract($this->agent, lmDeal($this->agent, 'listing', '3 Pending Pl'), '2026-06-20');
    lmDeal($this->agent, 'buyer', '4 Buyer Blvd'); // not a listing
    $theirs = lmDeal($this->other, 'listing', '5 Other St');

    $this->actingAs($this->agent)->get('/deals/listings')->assertOk()->assertInertia(fn (Assert $p) => $p->component('deals/listings')
        ->where('board.columns.getting_ready.0.street', '1 Ready Rd')->where('board.columns.active.0.street', '2 Active Ave')
        ->where('board.columns.active.0.warning', 'Listing agreement ends in 14 days')->where('board.columns.under_contract.0.street', '3 Pending Pl')
        ->where('board.expiring', 1)->where('board.totals.getting_ready.count', 1)->where('mine', true));
    $this->actingAs($this->owner)->get('/deals/listings')->assertInertia(fn (Assert $p) => $p->where('board.totals.getting_ready.count', 2)->where('mine', false));
    $this->actingAs($this->agent)->post("/deals/{$theirs->ulid}/listing", ['listing_expires_on' => '2026-07-01'])->assertForbidden();

    DB::table('tx_deals')->where('id', $pending->id)->update(['status' => 'closed', 'closed_on' => '2026-05-25']);
    $this->actingAs($this->agent)->get('/deals/listings')->assertInertia(fn (Assert $p) => $p->where('board.columns.sold.0.street', '3 Pending Pl'));
    assertPageContract($this->actingAs($this->owner)->get('/deals/listings'), 'deals/listings');
    expect($ready->ulid)->not->toBe('');
});

it('forecasts money week by week and reports what is owed to the franchisor', function (): void {
    $a = lmContract($this->agent, lmDeal($this->agent, 'listing', '10 Week One'), '2026-06-03');
    lmContract($this->agent, lmDeal($this->agent, 'buyer', '11 Week Three'), '2026-06-17');
    DB::table('tx_dates')->insert(['tenant_id' => $this->tenant->id, 'deal_id' => $a->id, 'label' => 'Inspection', 'due_on' => '2026-05-30', 'source' => 'manual',
        'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->owner)->get('/deals/money')->assertOk()->assertInertia(fn (Assert $p) => $p->component('deals/money')
        ->where('weekly.0.week_of', '2026-06-01')->where('weekly.0.deals.0.street', '10 Week One')->where('weekly.0.deals.0.at_risk', true)
        ->where('weekly.2.deals.0.street', '11 Week Three')->where('weekly.2.deals.0.at_risk', false)
        // $15,000 GCI, 6% royalty first ($900), then 20% of $14,100 = $2,820 company dollar.
        ->where('weekly.0.company_cents', 282_000));
    $csv = $this->actingAs($this->owner)->get('/deals/money/forecast.csv')->assertOk()->streamedContent();
    expect($csv)->toContain('10 Week One')->toContain('2820.00')->toContain('Yes');

    // Close one in May: the royalty report for May.
    DB::table('tx_deals')->where('id', $a->id)->update(['status' => 'closed', 'closed_on' => '2026-05-28']);
    $this->actingAs($this->owner)->get('/deals/money?month=2026-05')->assertInertia(fn (Assert $p) => $p->where('royalties.total_cents', 90_000)
        ->where('royalties.deals', 1)->where('royalties.by_fee.0.fee', 'Franchise royalty')->where('month', '2026-05'));
    $royalties = $this->actingAs($this->owner)->get('/deals/money/royalties.csv?month=2026-05')->assertOk()->streamedContent();
    expect($royalties)->toContain('Franchise royalty')->toContain('900.00')->toContain('10 Week One');
    $this->actingAs($this->owner)->get('/deals/money?month=2026-04')->assertInertia(fn (Assert $p) => $p->where('royalties.total_cents', 0));

    // Agents never see the brokerage's money.
    $this->actingAs($this->agent)->get('/deals/money')->assertForbidden();
    $this->actingAs($this->agent)->get('/deals/money/royalties.csv')->assertForbidden();
    assertPageContract($this->actingAs($this->owner)->get('/deals/money'), 'deals/money');
});
