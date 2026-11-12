<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Core\Access\Roles;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\LedgerService;
use Modules\Transactions\Cdas;
use Modules\Transactions\CdaSent;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Modules\Transactions\Referrals;
use Modules\Transactions\TitleDesk;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->staff = User::factory()->staff()->create(['first_name' => 'Sam']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
});

function tx2Deal(): Deal
{
    test()->actingAs(test()->agent)->post('/deals', ['type' => 'listing', 'street' => '9 Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '500000'])->assertRedirect();

    return Deal::query()->latest('id')->firstOrFail();
}

function tx2Contract(Deal $deal, string $closing = '2026-06-21'): Deal
{
    test()->actingAs(test()->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '500000', 'gci' => '15000', 'contract_date' => '2026-06-01', 'closing_date' => $closing])
        ->assertSessionHasNoErrors();

    return $deal->fresh() ?? $deal;
}

function tx2Pdf(string $name = 'doc.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

it('tracks earnest money, warns when it is late, and posts trust-account money to the ledger', function (): void {
    $deal = tx2Deal();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/deposits", ['amount' => '10000', 'held_by' => 'trust', 'due_on' => '2026-05-30'])->assertSessionHasNoErrors();
    $deposit = (string) DB::table('tx_deposits')->value('ulid');

    $this->actingAs($this->agent)->get("/deals/{$deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('deposits.0.overdue', true)->where('can.trust', false));
    $this->actingAs($this->agent)->post("/deals/deposits/{$deposit}/receive", ['received_on' => '2026-06-01', 'method' => 'wire'])->assertForbidden();

    $this->actingAs($this->owner)->post("/deals/deposits/{$deposit}/receive", ['received_on' => '2026-06-01', 'method' => 'wire', 'reference' => 'W-123'])->assertSessionHasNoErrors();
    $accounts = app(ChartOfAccounts::class);
    $ledger = app(LedgerService::class);
    expect($ledger->balance($accounts->account('1010'))->cents)->toBe(1_000_000);

    $this->actingAs($this->owner)->post("/deals/deposits/{$deposit}/release", ['refund' => false, 'to' => 'Closing (applied to purchase)', 'on' => '2026-06-20'])->assertSessionHasNoErrors();
    expect($ledger->balance($accounts->account('1010'))->cents)->toBe(0)
        ->and(DB::table('tx_deposits')->value('status'))->toBe('released')
        ->and((int) DB::table('ledger_lines')->sum('debit_cents'))->toBe((int) DB::table('ledger_lines')->sum('credit_cents'));
});

it('takes outgoing referral fees off the top of the commission and reports them for 1099', function (): void {
    $deal = tx2Deal();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/referrals", ['direction' => 'out', 'brokerage' => 'Coastal Realty', 'agent' => 'Kim Lee', 'percent' => '25'])->assertSessionHasNoErrors();
    $deal = tx2Contract($deal);

    $calc = Calculation::query()->findOrFail($deal->calculation_id);
    expect($calc->result['outside_referral_cents'])->toBe(375_000) // 25% of $15,000
        ->and(fn () => app(Referrals::class)->add($deal, 'out', 'Late Realty', 'Al', null, '10', null))->toThrow(ValidationException::class);

    DB::table('tx_deals')->where('id', $deal->id)->update(['status' => 'closed', 'closed_on' => '2026-06-21']);
    $this->actingAs($this->owner)->get('/settings/transactions?year=2026')->assertInertia(fn (Assert $page) => $page
        ->where('referralsPaid.0.brokerage', 'Coastal Realty')->where('referralsPaid.0.paid_cents', 375_000)->where('referralsPaid.0.w9', false));
});

it('generates a CDA that adds up, approved by someone other than the agent and sent only to a verified title company', function (): void {
    Notification::fake();
    $deal = tx2Deal();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/referrals", ['direction' => 'out', 'brokerage' => 'Coastal Realty', 'agent' => 'Kim Lee', 'percent' => '10']);
    $deal = tx2Contract($deal);

    $this->actingAs($this->owner)->post("/deals/{$deal->ulid}/cdas")->assertSessionHasNoErrors();
    $cda = app(Cdas::class)->forDeal($deal)[0];
    expect(array_column($cda['payload']['payees'], 'amount_cents'))->toBe([150_000, 1_350_000])
        ->and(array_sum(array_column($cda['payload']['payees'], 'amount_cents')))->toBe($cda['payload']['gci_cents'])
        ->and(json_encode($cda['payload']))->not->toContain('agent_net');

    // Separation of duties: the deal's own agent can't approve its CDA, even with the permission.
    $roles = app(Roles::class);
    $roles->assign($this->agent, $roles->save(null, 'CDA approver', null, ['transactions.cda.approve' => null], $this->owner), $this->owner);
    expect(fn () => app(Cdas::class)->approve($cda['ulid'], $this->agent->fresh()))->toThrow(ValidationException::class, 'other than the deal');
    $this->actingAs($this->owner)->post("/deals/cdas/{$cda['ulid']}/approve")->assertSessionHasNoErrors();

    $desk = app(TitleDesk::class);
    $title = $desk->addCompany('Lone Star Title', 'escrow@lonestar.test', '512-555-0100');
    $this->actingAs($this->owner)->post("/deals/cdas/{$cda['ulid']}/send", ['title' => $title])->assertSessionHasErrors('title');
    expect(fn () => $desk->verifyCompany($title, 'called', $this->owner))->toThrow(ValidationException::class);
    $desk->verifyCompany($title, 'Called 512-555-0100 from their website; Jo confirmed the escrow email', $this->owner);
    $this->actingAs($this->owner)->post("/deals/cdas/{$cda['ulid']}/send", ['title' => $title])->assertSessionHasNoErrors();

    $link = null;
    Notification::assertSentOnDemand(CdaSent::class, function (CdaSent $n, array $channels, object $notifiable) use (&$link): bool {
        $link = $n->url;

        return $notifiable->routes['mail'] === 'escrow@lonestar.test';
    });
    $path = (string) parse_url((string) $link, PHP_URL_PATH);
    auth()->logout();
    $this->get($path)->assertInertia(fn (Assert $page) => $page->component('public/cda')->where('cda.gci_cents', 1_500_000)->where('cda.payees.0.name', 'Coastal Realty'));
    $this->post($path.'/acknowledge', ['name' => 'Jo Escrow'])->assertRedirect();
    expect(DB::table('tx_cdas')->where('status', 'acknowledged')->value('acknowledged_by'))->toBe('Jo Escrow');

    // A new version supersedes the old one and kills its link.
    app(Cdas::class)->generate($deal, $this->owner);
    $this->get($path)->assertNotFound();
});

it('changes the brokerage receiving account only after a second person confirms by call-back', function (): void {
    $desk = app(TitleDesk::class);
    $second = User::factory()->owner()->create(['first_name' => 'Second']);
    $desk->proposeWire('First National', '4417', $this->owner);

    expect(fn () => $desk->confirmWire('Called the bank at the number on our statement', $this->owner))->toThrow(ValidationException::class, 'different person')
        ->and(fn () => $desk->proposeWire('Bank', '1234', $this->agent))->toThrow(ValidationException::class);
    $desk->confirmWire('Called First National at the number on our statement; confirmed', $second);
    expect($desk->wire())->toMatchArray(['bank' => 'First National', 'last4' => '4417', 'pending' => null]);

    $this->actingAs($this->owner)->post('/settings/transactions/wire/propose', ['bank' => 'Other Bank', 'last4' => '9999'])->assertRedirectContains('confirm-password');
});

it('assigns each upload to the reviewer with the fewest open reviews and approves in bulk', function (): void {
    $deal = tx2Deal();
    $items = Item::query()->where('deal_id', $deal->id)->orderBy('sort')->limit(3)->get();
    foreach ($items as $item) {
        $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => tx2Pdf(), 'item' => $item->ulid])->assertSessionHasNoErrors();
    }
    $assigned = Item::query()->whereIn('id', $items->pluck('id'))->orderBy('sort')->pluck('assigned_reviewer_id')->all();
    expect($assigned)->toBe([$this->owner->id, $this->staff->id, $this->owner->id])->not->toContain($this->agent->id);

    $this->actingAs($this->staff)->get('/deals/review')->assertInertia(fn (Assert $page) => $page->where('items', fn ($i) => collect($i)->where('mine', true)->count() === 1));
    $this->actingAs($this->owner)->post('/deals/review/bulk', ['items' => $items->take(2)->pluck('ulid')->all()])->assertSessionHasNoErrors();
    expect(Item::query()->whereIn('id', $items->pluck('id'))->where('status', 'approved')->count())->toBe(2);
    $this->actingAs($this->agent)->post('/deals/review/bulk', ['items' => [$items[2]->ulid]])->assertForbidden();
});

it('exports the complete transaction file with an audit certificate, and records the download', function (): void {
    $deal = tx2Deal();
    $item = Item::query()->where('deal_id', $deal->id)->orderBy('sort')->firstOrFail();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => tx2Pdf('listing.pdf'), 'item' => $item->ulid]);

    $this->actingAs($this->agent)->get("/deals/{$deal->ulid}/export")->assertForbidden();
    $response = $this->actingAs($this->owner)->get("/deals/{$deal->ulid}/export")->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $names = array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    expect($names)->toContain('checklist.json', 'audit-trail.json', 'audit-certificate.txt')
        ->and(collect($names)->contains(fn ($n) => str_ends_with($n, 'listing.pdf')))->toBeTrue()
        ->and((string) $zip->getFromName('audit-certificate.txt'))->toContain(hash('sha256', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"));
    (new FlushAuditOutbox)->handle();
    expect(DB::table('audit_events')->where('action', 'transactions.file.exported')->exists())->toBeTrue();
});

it('forecasts company dollar from deals under contract, for the brokerage only', function (): void {
    tx2Contract(tx2Deal(), '2026-06-21'); // company dollar 20% of $15,000 = $3,000
    tx2Contract(tx2Deal(), '2026-08-15');

    $this->actingAs($this->owner)->get('/deals')->assertInertia(fn (Assert $page) => $page
        ->where('forecast.buckets.30.company_cents', 300_000)->where('forecast.buckets.30.deals', 1)
        ->where('forecast.buckets.90.company_cents', 600_000)->where('forecast.buckets.90.deals', 2));
    $this->actingAs($this->agent)->get('/deals')->assertInertia(fn (Assert $page) => $page->where('forecast', null));
});
