<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Transactions\Compliance\ComplianceScan;
use Modules\Transactions\Documents;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Risk\DealRisk;

/* RD1 (specs/transactions/31): state rule packs, deal risk scores, review queue by risk, and the audit pack. */

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
});

function rdDeal(object $t, string $type = 'listing', string $street = '12 Oak St'): Deal
{
    $t->actingAs($t->agent)->post('/deals', ['type' => $type, 'street' => $street, 'city' => 'Austin', 'state_code' => 'TX', 'price' => '400000'])->assertRedirect();

    return Deal::query()->latest('id')->firstOrFail();
}

it('checks the state\'s required disclosures once the brokerage turns its state pack on', function (): void {
    $deal = rdDeal($this);
    $codes = function () use ($deal): array {
        app(ComplianceScan::class)->run($deal->fresh() ?? $deal);

        return DB::table('tx_findings')->where('deal_id', $deal->id)->where('status', 'open')->pluck('code')->all();
    };
    expect($codes())->not->toContain('state.tx_iabs'); // off until turned on

    $this->actingAs($this->owner)->post('/deals/compliance/checks/state.tx_iabs', ['enabled' => true])->assertSessionHasNoErrors();
    expect($codes())->toContain('state.tx_iabs');
    app(Documents::class)->imported($deal, 'IABS signed.pdf', "%PDF-1.4\n%%EOF");
    expect($codes())->not->toContain('state.tx_iabs');
    $this->actingAs($this->owner)->get('/deals/compliance')->assertInertia(fn (Assert $p) => $p->where('checks', fn ($c) => collect($c)->contains(fn ($x) => ($x['code'] ?? '') === 'state.tx_iabs')));
});

it('scores each open deal and puts the riskiest first for reviewers and coaches', function (): void {
    $calm = rdDeal($this, 'listing', '1 Calm Ct');
    $risky = rdDeal($this, 'listing', '2 Risky Rd');
    DB::table('tx_findings')->insert(['tenant_id' => $this->tenant->id, 'deal_id' => $risky->id, 'code' => 'checklist.missing', 'severity' => 'blocker', 'fingerprint' => sha1('x'),
        'message' => 'Missing: Listing agreement.', 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    DB::table('tx_dates')->insert(['tenant_id' => $this->tenant->id, 'deal_id' => $risky->id, 'label' => 'Inspection', 'due_on' => '2026-05-28', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('tx_dates')->insert(['tenant_id' => $this->tenant->id, 'deal_id' => $risky->id, 'label' => 'Appraisal', 'due_on' => '2026-06-03', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);

    $scores = DealRisk::forDeals([$calm->id, $risky->id]);
    expect($scores[$calm->id]['score'])->toBe(0)->and($scores[$risky->id])->toBe(['score' => 45, 'level' => 'medium', 'reasons' => ['1 blocker', '1 missed date', '1 date in the next 3 days']]);

    $this->actingAs($this->agent)->get("/deals/{$risky->ulid}")->assertInertia(fn (Assert $p) => $p->where('risk.level', 'high')
        ->where('risk.reasons', fn ($r) => collect($r)->contains('1 missed date'))); // opening the deal runs the full compliance scan too
    $this->actingAs($this->owner)->get('/deals/risk')->assertInertia(fn (Assert $p) => $p->where('agents.0.agent', 'Pat')->where('agents.0.deals', 2)->where('agents.0.high', 1));
});

it('downloads an audit pack for a period, behind a password', function (): void {
    $deal = rdDeal($this, 'listing', '9 Closed Ln');
    $deal->forceFill(['status' => 'closed', 'closed_on' => '2026-05-15'])->save();
    DB::table('tx_findings')->insert(['tenant_id' => $this->tenant->id, 'deal_id' => $deal->id, 'code' => 'docs.blank', 'severity' => 'warning', 'fingerprint' => sha1('y'),
        'message' => 'Blank price field', 'status' => 'dismissed', 'dismiss_reason' => 'Price is on page 2', 'dismissed_by' => $this->owner->id, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->actingAs($this->owner)->get('/deals/risk/audit-pack?from=2026-01-01&to=2026-05-31')->assertRedirectContains('confirm-password');
    $res = $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get('/deals/risk/audit-pack?from=2026-01-01&to=2026-05-31')->assertOk();
    $zip = new ZipArchive;
    $zip->open($res->baseResponse->getFile()->getPathname());
    expect((string) $zip->getFromName('deals.csv'))->toContain('9 Closed Ln')
        ->and((string) $zip->getFromName('dismissed-findings.csv'))->toContain('Price is on page 2')
        ->and((string) $zip->getFromName('README.txt'))->toContain('Deals closed: 1')
        ->and((string) $zip->getFromName('compliance-checks.csv'))->toContain('state.tx_iabs');
    $this->actingAs($this->agent)->withSession(['auth.password_confirmed_at' => time()])->get('/deals/risk/audit-pack?from=2026-01-01&to=2026-05-31')->assertForbidden();
});
