<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\FakeVoiceCaller;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Messaging\VoiceCaller;
use Modules\Core\Support\Connections;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Deadlines\BrokerAlert;
use Modules\Transactions\Deadlines\Deadlines;
use Modules\Transactions\Models\Deal;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/*
 * Sprint 15D: deadlines that cannot be missed (texts, automated calls and broker alerts on a ladder, the agent's
 * calendar feed), the broker risk register, and pre-closing risk checks.
 */

beforeEach(function (): void {
    Storage::fake('local');
    // 10:00 in Austin (America/Chicago, the brokerage's time zone).
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'phone' => '+15125550199']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    $this->deal->update(['status' => 'under_contract']);
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    app()->instance(VoiceCaller::class, $this->calls = new FakeVoiceCaller);
});

function keyDate(Deal $deal, string $label, string $due): int
{
    return (int) DB::table('tx_dates')->insertGetId(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'label' => $label, 'due_on' => $due, 'created_at' => now(), 'updated_at' => now()]);
}

function hqAllows(int $tenantId, bool $sms = true, bool $voice = true): void
{
    $control = DB::connection(Connections::control());
    $control->table('hq_messaging')->insert(['sms_from' => '+15125550100', 'voice_from' => '+15125550100', 'sms_enabled' => true, 'voice_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $control->table('hq_messaging_tenants')->insert(['tenant_id' => $tenantId, 'sms' => $sms, 'voice' => $voice, 'created_at' => now(), 'updated_at' => now()]);
}

it('texts the day before, texts and calls on the day, tells the broker when missed, and sends each alert once', function (): void {
    Notification::fake();
    hqAllows($this->tenant->id);
    $this->actingAs($this->agent)->post('/deals/alerts', ['sms' => true, 'voice' => true])->assertRedirect();
    keyDate($this->deal, 'Inspection period ends', '2026-06-02');
    keyDate($this->deal, 'Financing deadline', '2026-06-01');
    keyDate($this->deal, 'Option fee', '2026-05-29');

    $this->artisan('transactions:escalate')->assertSuccessful();
    expect(array_column($this->sms->sent, 'message'))->toEqualCanonicalizing([
        'RightAlly: Inspection period ends for 12 Oak St is due tomorrow. Tick it off in RightAlly once done.',
        'RightAlly: Financing deadline for 12 Oak St is due today. Tick it off in RightAlly once done.',
    ])->and(array_unique(array_column($this->sms->sent, 'to')))->toBe(['+15125550199'])
        ->and($this->calls->calls)->toHaveCount(1)
        ->and($this->calls->calls[0]['message'])->toContain('Financing deadline for 12 Oak St is due today');
    Notification::assertSentTo($this->owner, BrokerAlert::class);

    // The hourly run does not repeat itself.
    $this->artisan('transactions:escalate')->assertSuccessful();
    expect($this->sms->sent)->toHaveCount(2)->and($this->calls->calls)->toHaveCount(1);
    Notification::assertSentToTimes($this->owner, BrokerAlert::class, 1);
});

it('stays quiet at night, without consent, and when HQ has not switched texts on for the brokerage', function (): void {
    Notification::fake();
    keyDate($this->deal, 'Financing deadline', '2026-06-01');

    // HQ has not switched texts on: nothing, even with consent.
    DB::table('tx_alert_prefs')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $this->agent->id, 'sms_consent_at' => now(), 'voice_consent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(app(Deadlines::class)->escalate())->toBe(['texts' => 0, 'calls' => 0, 'broker' => 0]);

    // Switched on, but it is 10pm in Austin.
    hqAllows($this->tenant->id, voice: false);
    $this->travelTo(CarbonImmutable::parse('2026-06-02 03:00', 'UTC'));
    keyDate($this->deal, 'Appraisal due', '2026-06-01');
    expect(app(Deadlines::class)->escalate()['texts'])->toBe(0);

    // Morning: texts go, but no call (HQ allows texts only).
    $this->travelTo(CarbonImmutable::parse('2026-06-02 14:00', 'UTC'));
    keyDate($this->deal, 'Survey due', '2026-06-02');
    expect(app(Deadlines::class)->escalate())->toMatchArray(['texts' => 1, 'calls' => 0])
        ->and($this->calls->calls)->toBe([]);

    // Agents who did not opt in get nothing.
    DB::table('tx_alert_prefs')->delete();
    keyDate($this->deal, 'HOA documents', '2026-06-02');
    expect(app(Deadlines::class)->escalate()['texts'])->toBe(0);
});

it('needs a mobile number before an agent opts in, and shows the choices on the deals page', function (): void {
    hqAllows($this->tenant->id);
    $noPhone = User::factory()->create(['phone' => null]);
    $this->actingAs($noPhone)->post('/deals/alerts', ['sms' => true, 'voice' => false])->assertSessionHasErrors('phone');

    $this->actingAs($this->agent)->post('/deals/alerts', ['sms' => true, 'voice' => false]);
    keyDate($this->deal, 'Financing deadline', '2026-06-03');
    $this->actingAs($this->agent)->get('/deals')->assertInertia(fn (Assert $page) => $page
        ->component('deals/index')
        ->where('alerts.sms', true)->where('alerts.voice', false)->where('alerts.sms_allowed', true)->where('alerts.phone', '•••0199')
        ->where('atRisk.0.what', 'Financing deadline')->where('atRisk.0.street', '12 Oak St'));
    expect(DB::table('audit_events')->where('action', 'transactions.deadlines.consent')->exists())->toBeTrue();
});

it('gives the agent a private calendar feed of key dates and closings', function (): void {
    keyDate($this->deal, 'Inspection period ends', '2026-06-05');
    $this->deal->update(['closing_date' => '2026-06-30']);
    $this->actingAs($this->agent)->post('/deals/calendar')->assertRedirect();
    $url = app(Deadlines::class)->prefs($this->agent)['calendar_url'];
    expect($url)->toMatch('#/calendar/[A-Za-z0-9]{40}\.ics$#');

    auth()->logout();
    $ics = $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($ics)->toContain('BEGIN:VCALENDAR')->toContain('SUMMARY:Inspection period ends — 12 Oak St')
        ->toContain('DTSTART;VALUE=DATE:20260605')->toContain('SUMMARY:Closing — 12 Oak St')->toContain('DTSTART;VALUE=DATE:20260630');

    // A new link replaces the old one; unknown tokens are not found.
    $this->actingAs($this->agent)->post('/deals/calendar');
    $this->get($url)->assertNotFound();
    $this->get('/calendar/'.str_repeat('a', 40).'.ics')->assertNotFound();
});

it('flags missed dates, financing without an appraisal, inspection without a report, closing without a CD and late earnest money', function (): void {
    keyDate($this->deal, 'Option period ends', '2026-05-28');
    keyDate($this->deal, 'Financing deadline', '2026-06-05');
    keyDate($this->deal, 'Inspection period ends', '2026-06-02');
    $this->deal->update(['closing_date' => '2026-06-04']);
    app(ComplianceScan::class)->run($this->deal->fresh());

    $f = DB::table('tx_findings')->where('deal_id', $this->deal->id)->where('status', 'open')->where('code', 'like', 'risk.%')->pluck('severity', 'message')->all();
    expect($f)->toMatchArray([
        'Missed: Option period ends was due 05/28/2026. Tick it off if it is done, or agree an extension.' => 'blocker',
        'Financing deadline in 4 days, and no appraisal is on file.' => 'warning',
        'Inspection period ends tomorrow, and no inspection report or waiver is on file.' => 'blocker',
        'Closing in 3 days, and no closing disclosure or settlement statement is on file.' => 'warning',
    ]);

    // Ticked off: it resolves on the next scan.
    DB::table('tx_dates')->where('label', 'Option period ends')->update(['done_at' => now()]);
    app(ComplianceScan::class)->run($this->deal->fresh());
    expect(DB::table('tx_findings')->where('code', 'risk.missed')->value('status'))->toBe('resolved');
});

it('tells the broker about blockers still open close to closing, once', function (): void {
    Notification::fake();
    keyDate($this->deal, 'Option period ends', '2026-05-28');
    $this->deal->update(['closing_date' => '2026-06-02']);
    app(ComplianceScan::class)->run($this->deal->fresh());
    $blockers = DB::table('tx_findings')->where('deal_id', $this->deal->id)->where('status', 'open')->where('severity', 'blocker')->count();
    expect($blockers)->toBeGreaterThan(0);

    // Settings: no missed-date emails, so only the closing blockers go.
    $this->actingAs($this->owner)->post('/deals/compliance/deadlines', ['email_days' => 3, 'text_days' => 2, 'call_on_due' => false, 'broker_on_missed' => false, 'closing_blocker_hours' => 72])
        ->assertRedirect();
    expect(app(Deadlines::class)->settings())->toBe(['email_days' => 3, 'text_days' => 2, 'call_on_due' => false, 'broker_on_missed' => false, 'closing_blocker_hours' => 72]);
    expect(app(Deadlines::class)->escalate()['broker'])->toBe($blockers)
        ->and(app(Deadlines::class)->escalate()['broker'])->toBe(0);

    $this->actingAs($this->owner)->post('/deals/compliance/deadlines', ['email_days' => 30, 'text_days' => 1, 'call_on_due' => true, 'broker_on_missed' => true, 'closing_blocker_hours' => 48])
        ->assertSessionHasErrors('ladder');
    $this->actingAs($this->agent)->post('/deals/compliance/deadlines', ['email_days' => 1, 'text_days' => 1, 'call_on_due' => true, 'broker_on_missed' => true, 'closing_blocker_hours' => 48])
        ->assertForbidden();
});

it('shows the broker the risk register with a trend, and exports it safely for insurers', function (): void {
    keyDate($this->deal, '=HYPERLINK("x") Option period', '2026-05-28');
    DB::table('roster_credentials')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'user_id' => $this->agent->id, 'kind' => 'eo',
        'state_code' => null, 'expires_on' => '2026-06-20', 'status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
    app(ComplianceScan::class)->run($this->deal->fresh());

    $this->actingAs($this->owner)->get('/deals/risk')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('deals/risk')
        ->has('trend', 8)
        ->where('counts.high', fn ($n) => $n >= 1)
        ->where('risks', fn ($risks) => collect($risks)->contains(fn ($r) => str_contains($r['title'], 'E&O') && $r['owner'] === 'Pat Lee')
            && collect($risks)->contains(fn ($r) => str_starts_with($r['title'], 'Missed:') && $r['street'] === '12 Oak St')));

    $csv = $this->actingAs($this->owner)->get('/deals/risk.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
    expect($csv)->toContain('12 Oak St')->not->toMatch('/(^|,)"?=HYPERLINK/m');
    expect(DB::table('audit_events')->where('action', 'transactions.risk.exported')->exists())->toBeTrue();

    $this->actingAs($this->agent)->get('/deals/risk')->assertForbidden();
    $this->actingAs($this->agent)->get('/deals/risk.csv')->assertForbidden();
});

it('sends the new pages the data they need', function (): void {
    assertPageContract($this->actingAs($this->agent)->get('/deals'), 'deals/index');
    assertPageContract($this->actingAs($this->owner)->get('/deals/risk'), 'deals/risk');
    assertPageContract($this->actingAs($this->owner)->get('/deals/compliance'), 'deals/compliance');
});
