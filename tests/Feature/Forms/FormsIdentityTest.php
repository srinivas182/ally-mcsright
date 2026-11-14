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
use Modules\Forms\Identity\FakeIdentity;
use Modules\Forms\Identity\FakeOwnerRecords;
use Modules\Forms\Identity\IdentityAlert;
use Modules\Forms\Identity\IdentityChecks;
use Modules\Forms\Identity\IdentityProvider;
use Modules\Forms\Identity\OwnerRecords;
use Modules\Forms\Packets;
use Modules\Forms\SignatureRequested;
use Modules\Forms\Signing;
use Modules\Forms\Templates;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Contracts/contract-helpers.php';

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.test']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->signing = app(Signing::class);
    $this->idv = new FakeIdentity;
    $this->records = new FakeOwnerRecords;
    app()->instance(IdentityProvider::class, $this->idv);
    app()->instance(OwnerRecords::class, $this->records);

    $pdf = new FPDF('P', 'pt', 'Letter');
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 12);
    $pdf->Text(72, 60, 'RESIDENTIAL LISTING AGREEMENT');
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '485000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    DB::table('tx_parties')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'role' => 'seller', 'name' => 'Maria Gomez', 'email' => 'maria@example.test',
        'phone' => '+15125550123', 'created_at' => now(), 'updated_at' => now()]);
    $this->item = Item::query()->where('deal_id', $this->deal->id)->orderBy('sort')->firstOrFail();

    $templates = app(Templates::class);
    $t = $templates->upload('Listing', UploadedFile::fake()->createWithContent('l.pdf', $pdf->Output('S')), $this->item->label, $this->owner);
    $templates->saveFields($t, [
        ['key' => 'address', 'type' => 'text', 'page' => 1, 'x' => 72, 'y' => 100, 'w' => 400, 'h' => 14, 'bind' => 'deal.address', 'required' => true, 'label' => 'Address'],
        ['key' => 'seller_sig', 'type' => 'signature', 'page' => 1, 'x' => 72, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'seller1', 'label' => 'Seller signature'],
        ['key' => 'seller_init', 'type' => 'initials', 'page' => 1, 'x' => 72, 'y' => 700, 'w' => 60, 'h' => 14, 'role' => 'seller1', 'label' => 'Seller initials'],
        ['key' => 'seller_date', 'type' => 'date_signed', 'page' => 1, 'x' => 150, 'y' => 700, 'w' => 100, 'h' => 14, 'role' => 'seller1', 'label' => 'Date'],
        ['key' => 'agent_sig', 'type' => 'signature', 'page' => 1, 'x' => 320, 'y' => 650, 'w' => 200, 'h' => 20, 'role' => 'listing_agent', 'label' => 'Agent signature'],
    ], $this->owner);
    $this->packet = app(Packets::class)->create($this->deal, 'Listing paperwork', [$t], $this->agent);
});

const URL_BASE = 'http://acme.rightally.app';

/** The path of the last signing link emailed to an address. */
function idLink(string $email): string
{
    $url = null;
    Notification::assertSentOnDemand(SignatureRequested::class, function (SignatureRequested $n, array $c, object $to) use ($email, &$url): bool {
        if ($to->routes['mail'] === $email) {
            $url = $n->url;
        }

        return true;
    });

    return (string) parse_url((string) $url, PHP_URL_PATH);
}

function riskyOwnerRecord(array $owners = ['GOMEZ MARIA L']): array
{
    return ['owners' => $owners, 'mailing_state' => 'FL', 'vacant_land' => true, 'owner_occupied' => false, 'estimated_value_cents' => 90_000_000, 'source' => 'Test property data'];
}

function sellerStatus(): ?string
{
    return DB::table('form_signers')->where('role', 'seller1')->orderByDesc('id')->value('id_status');
}

it('flags seller-impersonation signals and blocks sending when checks are needed but no identity service is set up', function (): void {
    $this->records->record = riskyOwnerRecord(['ROBERT KING']);
    $this->idv->isReady = false;

    $this->actingAs($this->agent)->get("/forms/packets/{$this->packet}")->assertInertia(fn (Assert $p) => $p->component('forms/packet')
        ->where('risk.flags', fn ($flags): bool => collect($flags)->contains(fn ($f) => str_contains($f, 'vacant land'))
            && collect($flags)->contains(fn ($f) => str_contains($f, 'out of state (FL)'))
            && collect($flags)->contains(fn ($f) => str_contains($f, 'well below the estimated value'))
            && collect($flags)->contains(fn ($f) => str_contains($f, 'Maria Gomez is not named as an owner of record (ROBERT KING)'))
            && collect($flags)->contains(fn ($f) => str_contains($f, 'does not live at the property')))
        ->where('risk.owner.owners.0', 'ROBERT KING')
        ->where('problems', fn ($p): bool => collect($p)->contains(fn ($x) => str_contains($x, 'no identity service is set up'))));
    expect(fn () => $this->signing->send($this->packet, 'email', $this->agent, URL_BASE))->toThrow(ValidationException::class, 'no identity service');
});

it('makes a flagged seller verify with ID and selfie before signing, then records it on the certificate', function (): void {
    $this->records->record = riskyOwnerRecord();
    $this->signing->send($this->packet, 'email', $this->agent, URL_BASE);
    expect(DB::table('form_signers')->where('role', 'seller1')->value('id_required'))->toBeTruthy()
        ->and(DB::table('form_signers')->where('role', 'listing_agent')->value('id_required'))->toBeFalsy();

    $seller = idLink('maria@example.test');
    auth()->logout();
    $this->post($seller.'/consent', ['agree' => true]);
    $this->get($seller)->assertInertia(fn (Assert $p) => $p->where('signer.id_required', true)->where('signer.id_status', null));
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasErrors(['sign' => 'Verify your identity first.']);

    $this->idv->next['name'] = 'Maria L Gomez';
    $this->post($seller.'/identity')->assertRedirect(URL_BASE.$seller.'/identity/return');
    $this->get($seller.'/identity/return')->assertRedirect('/sign/'.basename($seller));
    expect(sellerStatus())->toBe('verified');
    $check = DB::table('form_identity_checks')->first();
    expect($check->status)->toBe('verified')->and($check->document_type)->toBe('driving_license')->and(substr((string) $check->expires_at, 0, 10))->toBe('2027-06-01');

    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasNoErrors();
    $agent = idLink('pat@example.test');
    $this->post($agent.'/consent', ['agree' => true]);
    $this->post($agent.'/sign', ['typed' => 'Pat Lee', 'initials' => 'PL'])->assertSessionHasNoErrors();

    $p = DB::table('form_packets')->first();
    expect($p->status)->toBe('completed');
    $file = tempnam(sys_get_temp_dir(), 'sealed');
    file_put_contents($file, (string) Storage::disk('local')->get((string) $p->sealed_path));
    $text = new Process(['pdftotext', $file, '-']);
    $text->run();
    expect($text->getOutput())->toContain('identity verified 2026-06-01')->toContain("driver's licence, US, live selfie")->toContain('Owner of record (Test property data): GOMEZ MARIA L');
});

it('pauses signing and alerts the agent when the ID shows a different name', function (): void {
    $this->records->record = riskyOwnerRecord();
    $this->signing->send($this->packet, 'email', $this->agent, URL_BASE);
    $seller = idLink('maria@example.test');
    auth()->logout();
    $this->post($seller.'/consent', ['agree' => true]);
    $this->idv->next['name'] = 'John Smith';
    $this->post($seller.'/identity');
    $this->get($seller.'/identity/return');

    expect(sellerStatus())->toBe('name_mismatch')->and(DB::table('form_identity_checks')->value('expires_at'))->toBeNull();
    Notification::assertSentTo($this->agent, IdentityAlert::class, fn (IdentityAlert $n): bool => str_contains($n->message, 'John Smith'));
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasErrors('sign');
    expect(DB::table('form_events')->where('action', 'id_mismatch')->exists())->toBeTrue();
});

it('lets a failed check be retried', function (): void {
    $this->records->record = riskyOwnerRecord();
    $this->signing->send($this->packet, 'email', $this->agent, URL_BASE);
    $seller = idLink('maria@example.test');
    auth()->logout();
    $this->post($seller.'/consent', ['agree' => true]);
    $this->idv->next = ['status' => 'failed', 'name' => null, 'document_type' => null, 'document_country' => null, 'reason' => 'document_expired'];
    $this->post($seller.'/identity');
    $this->get($seller.'/identity/return');
    expect(sellerStatus())->toBe('failed');

    $this->idv->next = ['status' => 'verified', 'name' => 'Maria Gomez', 'document_type' => 'passport', 'document_country' => 'US', 'reason' => null];
    $this->post($seller.'/identity');
    $this->get($seller.'/identity/return');
    expect(sellerStatus())->toBe('verified')->and(DB::table('form_identity_checks')->count())->toBe(2)->and($this->idv->started)->toBe(2);
});

it('holds a verified seller who is not the owner of record until the broker accepts it with a reason', function (): void {
    $this->records->record = riskyOwnerRecord(['GOMEZ FAMILY TRUST']);
    $this->signing->send($this->packet, 'email', $this->agent, URL_BASE);
    $seller = idLink('maria@example.test');
    auth()->logout();
    $this->post($seller.'/consent', ['agree' => true]);
    $this->idv->next['name'] = 'Maria Gomez';
    $this->post($seller.'/identity');
    $this->get($seller.'/identity/return');
    expect(sellerStatus())->toBe('owner_mismatch');
    Notification::assertSentTo($this->agent, IdentityAlert::class, fn (IdentityAlert $n): bool => str_contains($n->message, 'not named as an owner of record'));
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasErrors('sign');

    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/owner-override", ['reason' => 'She is the trustee; certificate of trust on file'])->assertSessionHasErrors('owner');
    $this->actingAs($this->owner)->post("/forms/packets/{$this->packet}/owner-override", ['reason' => 'short'])->assertSessionHasErrors('owner');
    $this->actingAs($this->owner)->post("/forms/packets/{$this->packet}/owner-override", ['reason' => 'She is the trustee; certificate of trust on file'])->assertSessionHasNoErrors();
    expect(sellerStatus())->toBe('verified');
    auth()->logout();
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasNoErrors();
});

it('reuses a verification of the same person for 12 months', function (): void {
    $this->records->record = riskyOwnerRecord();
    $this->signing->send($this->packet, 'email', $this->agent, URL_BASE);
    $seller = idLink('maria@example.test');
    auth()->logout();
    $this->post($seller.'/consent', ['agree' => true]);
    $this->idv->next['name'] = 'Maria Gomez';
    $this->post($seller.'/identity');
    $this->get($seller.'/identity/return');

    $template = (string) DB::table('form_templates')->value('ulid');
    $second = app(Packets::class)->create($this->deal, 'Second', [$template], $this->agent);
    $this->signing->send($second, 'email', $this->agent, URL_BASE);
    $sid = (int) DB::table('form_packets')->where('ulid', $second)->value('id');
    expect(DB::table('form_signers')->where('packet_id', $sid)->where('role', 'seller1')->value('id_status'))->toBe('verified')
        ->and(DB::table('form_events')->where('packet_id', $sid)->where('action', 'id_reused')->exists())->toBeTrue();

    $this->travel(13)->months();
    $third = app(Packets::class)->create($this->deal, 'Third', [$template], $this->agent);
    $this->signing->send($third, 'email', $this->agent, URL_BASE);
    $tid = (int) DB::table('form_packets')->where('ulid', $third)->value('id');
    expect(DB::table('form_signers')->where('packet_id', $tid)->where('role', 'seller1')->value('id_status'))->toBeNull();
});

it('follows the brokerage policy, high-risk forms and the met-in-person answer', function (): void {
    $checks = app(IdentityChecks::class);
    expect(fn () => $checks->saveSettings('everyone', true, 70, $this->agent))->toThrow(ValidationException::class);
    $this->actingAs($this->owner)->post('/forms/identity-settings', ['id_policy' => 'nobody', 'owner_check' => true, 'below_value_percent' => 70])->assertSessionHasErrors('identity');

    // No owner record and no signals: the default policy asks for nothing.
    $pid = (int) DB::table('form_packets')->where('ulid', $this->packet)->value('id');
    $checks->prepare($pid);
    expect(DB::table('form_signers')->where('packet_id', $pid)->where('id_required', true)->count())->toBe(0);

    // The agent has not met the seller: that alone is a signal.
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/signers", ['signers' => [
        ['role' => 'seller1', 'name' => 'Maria Gomez', 'email' => 'maria@example.test', 'sign_order' => 1, 'met_in_person' => false],
        ['role' => 'listing_agent', 'name' => 'Pat Lee', 'email' => 'pat@example.test', 'sign_order' => 2],
    ]])->assertSessionHasNoErrors();
    expect($checks->assess($pid)['flags'])->toBe(['Maria Gomez has not met the agent in person.']);
    $checks->prepare($pid);
    expect(DB::table('form_signers')->where('packet_id', $pid)->where('role', 'seller1')->value('id_required'))->toBeTruthy();

    $this->actingAs($this->owner)->post('/forms/identity-settings', ['id_policy' => 'off', 'owner_check' => true, 'below_value_percent' => 70])->assertSessionHasNoErrors();
    $checks->prepare($pid);
    expect(DB::table('form_signers')->where('packet_id', $pid)->where('id_required', true)->count())->toBe(0);

    // A high-risk form: every client signer verifies (agents never do), under any policy but off.
    $this->actingAs($this->owner)->post('/forms/identity-settings', ['id_policy' => 'sellers', 'owner_check' => false, 'below_value_percent' => 70]);
    $this->actingAs($this->owner)->post('/forms/templates/'.DB::table('form_templates')->value('ulid').'/risk', ['high_risk' => true])->assertSessionHasNoErrors();
    expect($checks->assess($pid)['high_risk'])->toBeTrue();
    $checks->prepare($pid);
    expect(DB::table('form_signers')->where('packet_id', $pid)->where('id_required', true)->pluck('role')->all())->toBe(['seller1']);
    $this->actingAs($this->agent)->post('/forms/templates/'.DB::table('form_templates')->value('ulid').'/risk', ['high_risk' => false])->assertForbidden();
});

it('matches names and owners sensibly', function (): void {
    expect(IdentityChecks::nameMatches('Maria Gomez', 'MARIA LUISA GOMEZ'))->toBeTrue()
        ->and(IdentityChecks::nameMatches('José Núñez', 'Jose Nunez'))->toBeTrue()
        ->and(IdentityChecks::nameMatches('Maria Gomez', 'Maria Lopez'))->toBeFalse()
        ->and(IdentityChecks::ownerMatches('Maria Gomez', ['GOMEZ MARIA L']))->toBeTrue()
        ->and(IdentityChecks::ownerMatches('Maria Gomez', ['GOMEZ M']))->toBeTrue()
        ->and(IdentityChecks::ownerMatches('Maria Gomez', ['KING ROBERT', 'GOMEZ JUAN']))->toBeFalse()
        ->and(IdentityChecks::ownerMatches('Robert King Jr.', ['KING ROBERT & KING ANNE']))->toBeTrue();
});

it('sends the forms, packet and signing pages the data they need for identity checks', function (): void {
    $this->records->record = riskyOwnerRecord();
    assertPageContract($this->actingAs($this->owner)->get('/forms/templates'), 'forms/templates');
    assertPageContract($this->actingAs($this->agent)->get("/forms/packets/{$this->packet}"), 'forms/packet');
    $this->signing->send($this->packet, 'email', $this->agent, URL_BASE);
    auth()->logout();
    assertPageContract($this->get(idLink('maria@example.test')), 'public/sign');
});
