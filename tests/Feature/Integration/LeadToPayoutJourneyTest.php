<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Payouts\BankAccounts;
use Modules\Payouts\PayoutRuns;
use Modules\Payouts\Receipts;
use Modules\Sites\SiteSetup;
use Modules\Transactions\Models\Deal;

/*
| Sprint 58 (integration across modules): one journey through six modules, the way a brokerage uses RightAlly.
| A visitor enquires on the website (Sites) → the lead and contact reach the agent (CRM) → the agent opens a deal
| for that contact and it goes under contract (Transactions) → the commission plan splits it (Compensation) → the
| money arrives and the agent is paid in a payout run (Payouts) → every journal balances (Ledger). A second
| brokerage sees none of it.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->second = User::factory()->owner()->create(['first_name' => 'Second']);
    $this->agent = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', ['area' => 'Austin', 'phone' => '(512) 555-0100', 'email' => 'hello@acme.test'], $this->owner), true, $this->owner);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

it('takes a website enquiry all the way to the agent\'s payout, with balanced books and nothing seen by another brokerage', function (): void {
    // 1. Website → CRM.
    $this->post('http://acme.rightally.site/_lead', ['name' => 'Jane Buyer', 'email' => 'jane@x.test', 'phone' => '(512) 555-0199', 'intent' => 'buy',
        'message' => 'Looking in 78704', 'return' => '/contact', 't' => Crypt::encryptString((string) (time() - 30))])->assertRedirect();
    $lead = Lead::query()->sole();
    $contact = Contact::query()->where('primary_email', 'jane@x.test')->sole();
    $lead->forceFill(['assigned_user_id' => $this->agent->id])->save();
    $contact->forceFill(['owner_user_id' => $this->agent->id])->save();
    $this->actingAs($this->agent)->get('/contacts/'.$contact->ulid)->assertOk();

    // 2. CRM → Transactions: a deal for that contact, under contract.
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '12 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000',
        'contact' => $contact->ulid])->assertSessionHasNoErrors();
    $deal = Deal::query()->sole();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000',
        'contract_date' => '2026-06-01', 'closing_date' => '2026-06-01'])->assertSessionHasNoErrors();
    $deal = $deal->fresh() ?? $deal;

    // 3. Compensation: the 80/20 split.
    $calc = Calculation::query()->findOrFail($deal->calculation_id);
    $receipts = app(Receipts::class);
    $expected = $receipts->expected($deal, $calc);

    // 4. Payouts: the commission arrives, the agent is paid by direct deposit.
    app(BankAccounts::class)->set($this->agent, 'Ann Agent', '021000021', '111122223333', 'checking', $this->agent);
    $this->travel(73)->hours();
    $receipts->record($deal, $expected, CarbonImmutable::parse('2026-06-01'), 'wire', null, $this->owner);
    $runs = app(PayoutRuns::class);
    $run = $runs->build(CarbonImmutable::today(), false, $this->owner);
    $line = DB::connection(Connections::cell())->table('pay_run_lines')->where('run_id', DB::connection(Connections::cell())->table('pay_runs')->where('ulid', $run)->value('id'))->sole();
    expect((int) $line->amount_cents)->toBe(1_200_000)->and($line->method)->toBe('ach'); // 80% of $15,000
    $runs->approve($run, $this->second);

    // 5. Ledger: every journal balances.
    $journals = DB::connection(Connections::cell())->table('ledger_lines')->selectRaw('journal_id, sum(debit_cents) as d, sum(credit_cents) as c')->groupBy('journal_id')->get();
    expect($journals)->not->toBeEmpty()->and($journals->every(fn ($j): bool => (int) $j->d === (int) $j->c))->toBeTrue();

    // 6. Another brokerage sees none of it, by page or by query.
    $theirOwner = app(TenantContext::class)->runAs($this->other, fn () => User::factory()->owner()->create());
    app(TenantContext::class)->runAs($this->other, function () use ($contact, $deal, $run): void {
        expect(Contact::query()->whereKey($contact->id)->exists())->toBeFalse()
            ->and(Deal::query()->whereKey($deal->id)->exists())->toBeFalse()
            ->and(DB::connection(Connections::cell())->table('pay_runs')->where('ulid', $run)->exists())->toBeFalse()
            ->and(DB::connection(Connections::cell())->table('ledger_lines')->count())->toBe(0);
    });
    $this->useTenantHost($this->other);
    $this->actingAs($theirOwner)->get('/contacts/'.$contact->ulid)->assertNotFound();
    $this->actingAs($theirOwner)->get('/deals/'.$deal->ulid)->assertNotFound();
});
