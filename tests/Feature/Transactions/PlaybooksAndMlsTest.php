<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Listings\MlsStatus;
use Modules\Transactions\Deadlines\BrokerAlert;
use Modules\Transactions\DealIq\PartyMessageMail;
use Modules\Transactions\Deals;
use Modules\Transactions\Mls\MlsGuard;
use Modules\Transactions\Mls\MlsStatusMail;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Playbooks\AgentWeekly;
use Modules\Transactions\Playbooks\AgentWeeklyMail;
use Modules\Transactions\Playbooks\Playbooks;
use Modules\Transactions\Portal\PortalMail;
use Modules\Transactions\Risk\RiskRegister;

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    // Monday 10:00 in Austin.
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '450000', 'mls_number' => 'TX-1001']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    $this->deal->update(['mls_number' => 'TX-1001']);
    $this->mls = new class implements MlsStatus
    {
        public ?string $status = 'Active';

        public function status(int $tenantId, string $mlsNumber): ?string
        {
            return $mlsNumber === 'TX-1001' ? $this->status : null;
        }
    };
    app()->instance(MlsStatus::class, $this->mls);
});

function playbookParty(Deal $deal, string $role, string $name, ?string $email): void
{
    DB::table('tx_parties')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'role' => $role, 'name' => $name, 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, string> */
function playbookDates(Deal $deal): array
{
    return DB::table('tx_dates')->where('deal_id', $deal->id)->pluck('due_on', 'label')->map(fn ($d): string => substr((string) $d, 0, 10))->all();
}

function underContract(object $test, Deal $deal, string $contract, string $closing): void
{
    app(Deals::class)->moveTo($deal, 'under_contract', $test->agent, ['price_cents' => 45_000_000, 'gci_cents' => 1_350_000, 'contract_date' => $contract, 'closing_date' => $closing]);
}

it('chooses the playbook for the kind of deal, and a chosen playbook adds its documents once and its dates', function (): void {
    expect(Playbooks::keyFor($this->deal))->toBe('standard_sale');
    underContract($this, $this->deal, '2026-06-01', '2026-07-15');
    expect(playbookDates($this->deal->refresh()))->toMatchArray(['Order title (target)' => '2026-06-02', 'Appraisal ordered (target)' => '2026-06-10', 'Clear to close (target)' => '2026-07-10']);

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/playbook", ['playbook' => 'short_sale'])->assertRedirect();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/playbook", ['playbook' => 'short_sale']);
    expect(Item::query()->where('deal_id', $this->deal->id)->where('label', 'Short-sale lender approval letter')->count())->toBe(1)
        ->and(playbookDates($this->deal->refresh()))->toMatchArray(['Send the short-sale package to the lender (target)' => '2026-06-04'])
        ->and(playbookDates($this->deal))->not->toHaveKey('Order title (target)'); // the other playbook's targets go
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/playbook", ['playbook' => 'castle'])->assertSessionHasErrors('playbook');
});

it('keeps 1031 deadlines exact (never moved for weekends) and shows them on the linked purchase', function (): void {
    underContract($this, $this->deal, '2026-05-01', '2026-06-03');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/playbook", ['playbook' => 'exchange_1031']);
    $dates = playbookDates($this->deal->refresh());
    expect($dates['1031: identify the replacement property (45 days)'])->toBe('2026-07-18') // a Saturday: it does not move
        ->and($dates['1031: close on the replacement (180 days, or the tax return due date if sooner)'])->toBe('2026-11-30')
        ->and(Item::query()->where('deal_id', $this->deal->id)->where('label', 'Qualified intermediary exchange agreement')->exists())->toBeTrue();

    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '9 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '600000']);
    $purchase = Deal::query()->latest('id')->firstOrFail();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/link", ['deal' => $purchase->ulid])->assertRedirect();
    expect(playbookDates($purchase))->toMatchArray(['1031: identify the replacement property (45 days)' => '2026-07-18'])
        ->and($purchase->refresh()->getAttribute('linked_deal_id'))->toBe($this->deal->id);
    $this->actingAs($this->agent)->get("/deals/{$purchase->ulid}")->assertInertia(fn (Assert $page) => $page->where('playbook.linked.street', '12 Oak St'));

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/link", ['deal' => null]);
    expect(playbookDates($purchase))->not->toHaveKey('1031: identify the replacement property (45 days)');
});

it('readies playbook emails when due, sends them to the right people, and the brokerage can make one automatic', function (): void {
    playbookParty($this->deal, 'title', 'Lone Star Title', 'escrow@title.test');
    playbookParty($this->deal, 'lender', 'Big Bank', 'loans@bank.test');
    playbookParty($this->deal, 'seller', 'Sam Seller', 'sam@example.test');
    underContract($this, $this->deal, '2026-06-01', '2026-07-15');
    expect(DB::table('tx_playbook_steps')->where('step', 'introductions')->value('status'))->toBe('waiting');
    expect(app(Playbooks::class)->run())->toMatchArray(['ready' => 1, 'sent' => 0]); // due 9:00 on the contract date; now 10:00
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('playbook.steps.0.status', 'ready')->where('playbook.steps.0.recipients', 2));

    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/playbook/introductions/send")->assertRedirect();
    Notification::assertSentOnDemandTimes(PartyMessageMail::class, 2);
    Notification::assertSentOnDemand(PartyMessageMail::class, fn (PartyMessageMail $m, $c, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'escrow@title.test'
        && $m->subject === 'Introductions: 12 Oak St' && str_contains($m->body, 'closing Jul 15, 2026') && str_contains($m->body, 'Pat Lee'));
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/playbook/closing_week/skip");
    expect(DB::table('tx_playbook_steps')->pluck('status', 'step')->all())->toBe(['introductions' => 'sent', 'closing_week' => 'skipped']);

    // The brokerage edits the playbook: introductions send by themselves.
    $def = app(Playbooks::class)->definition('standard_sale');
    $def['emails'][0]['auto'] = true;
    $this->actingAs($this->agent)->post('/deals/playbooks/standard_sale', $def)->assertForbidden();
    $this->actingAs($this->owner)->post('/deals/playbooks/standard_sale', $def)->assertRedirect();
    $def['emails'][0]['body'] = 'Wire to routing 021000021 account 12345678';
    $this->actingAs($this->owner)->post('/deals/playbooks/standard_sale', $def)->assertSessionHasErrors('emails');
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '3 Pine St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '300000']);
    $second = Deal::query()->latest('id')->firstOrFail();
    playbookParty($second, 'title', 'Lone Star Title', 'escrow@title.test');
    underContract($this, $second, '2026-06-01', '2026-07-15');
    Notification::fake();
    expect(app(Playbooks::class)->run()['sent'])->toBe(1);
    Notification::assertSentOnDemandTimes(PartyMessageMail::class, 1);

    $this->actingAs($this->owner)->delete('/deals/playbooks/standard_sale');
    expect(app(Playbooks::class)->definition('standard_sale')['emails'][0]['auto'])->toBeFalse();
    $this->actingAs($this->agent)->get('/deals/playbooks')->assertInertia(fn (Assert $page) => $page->component('deals/playbooks')->where('canEdit', false)->has('playbooks', 9));
});

it('starts the contract steps when an offer is accepted: option period from the offer, introductions ready', function (): void {
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers", ['agent_name' => 'Ann', 'agent_email' => 'ann@buyers.test', 'price' => '455000', 'option_days' => 7,
        'financing' => 'conventional', 'closing_date' => '2026-07-15']);
    $offer = DB::table('tx_offers')->first();
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/offers/{$offer->ulid}/accept");
    expect(DB::table('tx_playbook_steps')->where('deal_id', $this->deal->id)->where('step', 'introductions')->value('status'))->toBe('ready')
        ->and(json_decode((string) $this->deal->refresh()->getAttribute('terms'), true))->toMatchArray(['option_days' => 7, 'accepted_on' => '2026-06-01']);
    underContract($this, $this->deal->refresh(), '2026-06-02', '2026-07-15');
    expect(playbookDates($this->deal))->toMatchArray(['Option period ends' => '2026-06-09']);
});

it('guards the MLS status: reminds the agent, escalates when late, and clears when the MLS catches up', function (): void {
    underContract($this, $this->deal, '2026-06-01', '2026-07-15'); // Monday; the MLS still shows Active
    $counts = app(MlsGuard::class)->run();
    expect($counts)->toMatchArray(['checked' => 1, 'reminded' => 1, 'escalated' => 0]);
    Notification::assertSentTo($this->agent, MlsStatusMail::class, fn (MlsStatusMail $m): bool => $m->expected === 'Pending' && $m->seen === 'Active' && $m->due->toDateString() === '2026-06-03');
    expect(app(MlsGuard::class)->run()['reminded'])->toBe(0); // once
    $this->actingAs($this->agent)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('playbook.mls.0.expected', 'Pending')->where('playbook.mls.0.overdue', false));

    $this->travelTo(CarbonImmutable::parse('2026-06-04 15:00', 'UTC'));
    expect(app(MlsGuard::class)->run()['escalated'])->toBe(1);
    Notification::assertSentTo($this->owner, BrokerAlert::class);
    expect(collect(app(RiskRegister::class)->all())->where('category', 'MLS status')->count())->toBe(1);

    $this->mls->status = 'Pending';
    expect(app(MlsGuard::class)->run()['resolved'])->toBe(1);
    expect(MlsGuard::forDeal($this->deal))->toBe([]);

    // Not in the feed: nothing to compare, nothing sent.
    $this->deal->update(['mls_number' => 'OTHER-9']);
    Notification::fake();
    expect(app(MlsGuard::class)->run()['checked'])->toBe(0);
    Notification::assertNothingSent();
});

it('lets the broker set the MLS deadlines', function (): void {
    $this->actingAs($this->owner)->post('/deals/compliance/mls', ['mls_pending_days' => 1, 'mls_closed_days' => 3, 'mls_active_days' => 2])->assertRedirect();
    expect(app(MlsGuard::class)->rules())->toBe(['mls_pending_days' => 1, 'mls_closed_days' => 3, 'mls_active_days' => 2]);
    $this->actingAs($this->owner)->post('/deals/compliance/mls', ['mls_pending_days' => 30, 'mls_closed_days' => 3, 'mls_active_days' => 2])->assertSessionHasErrors('mls_pending_days');
    $this->actingAs($this->agent)->post('/deals/compliance/mls', ['mls_pending_days' => 1, 'mls_closed_days' => 1, 'mls_active_days' => 1])->assertForbidden();
    $this->actingAs($this->owner)->get('/deals/compliance')->assertInertia(fn (Assert $page) => $page->where('mlsRules.mls_closed_days', 3));
});

it('sends each agent a Monday summary of their open deals, once', function (): void {
    underContract($this, $this->deal, '2026-05-20', '2026-06-05');
    DB::table('tx_dates')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'label' => 'Survey', 'due_on' => '2026-05-29', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(AgentWeekly::class)->run())->toBe(1)->and(app(AgentWeekly::class)->run())->toBe(0);
    Notification::assertSentTo($this->agent, AgentWeeklyMail::class, fn (AgentWeeklyMail $m): bool => in_array('12 Oak St: Survey (Fri May 29)', $m->summary['overdue'], true)
        && collect($m->summary['due'])->contains(fn (string $l): bool => str_contains($l, 'Final walk-through')));
    $this->travelTo(CarbonImmutable::parse('2026-06-02 15:00', 'UTC'));
    expect(app(AgentWeekly::class)->run())->toBe(0); // only on Mondays
});

it('gives everyone in the portal a calendar feed of their own dates', function (): void {
    underContract($this, $this->deal, '2026-06-01', '2026-07-15');
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal", ['role' => 'lender', 'name' => 'Big Bank', 'email' => 'loans@bank.test', 'locale' => 'en']);
    $this->actingAs($this->agent)->post("/deals/{$this->deal->ulid}/portal/calendars")->assertRedirect();
    $link = null;
    Notification::assertSentOnDemand(PortalMail::class, function (PortalMail $m) use (&$link): bool {
        if (! str_starts_with($m->url, 'webcal://')) {
            return false;
        }
        $link = $m->url;

        return $m->actionKey === 'mail.calendar_add';
    });
    auth()->logout();
    $path = (string) parse_url((string) $link, PHP_URL_PATH);
    $ics = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($ics)->toContain('BEGIN:VCALENDAR')->toContain('Closing — 12 Oak St')->toContain('Appraisal ordered (target)')->not->toContain('Order title');

    $ulid = (string) DB::table('tx_portal_access')->value('ulid');
    $this->actingAs($this->agent)->delete("/deals/{$this->deal->ulid}/portal/{$ulid}");
    auth()->logout();
    $this->get($path)->assertNotFound();
});
