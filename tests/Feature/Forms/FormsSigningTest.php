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
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Forms\PacketCompleted;
use Modules\Forms\Packets;
use Modules\Forms\Sealer;
use Modules\Forms\SignatureRequested;
use Modules\Forms\SignerDeclined;
use Modules\Forms\Signing;
use Modules\Forms\Templates;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Contracts/contract-helpers.php';

const PNG_DOT = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

/** The signing link last emailed to an address. */
function linkFor(string $email): string
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

function tokenOf(string $path): string
{
    return basename($path);
}

it('sends to the first signer only, with the wire-fraud warning, after the pre-send check passes', function (): void {
    DB::table('form_packet_forms')->update(['values' => '{}']);
    expect(fn () => $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app'))->toThrow(ValidationException::class, 'Fix these first');
    DB::table('form_packet_forms')->update(['values' => json_encode(['address' => '12 Oak St, Austin, TX'])]);

    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/send", ['verify' => 'email'])->assertSessionHasNoErrors();
    Notification::assertSentOnDemandTimes(SignatureRequested::class, 1);
    $mail = null;
    Notification::assertSentOnDemand(SignatureRequested::class, function (SignatureRequested $n) use (&$mail): bool {
        $mail = $n->toMail(new stdClass);

        return true;
    });
    expect(implode(' ', array_merge($mail->introLines, $mail->outroLines)))->toContain('never wire money')
        ->and(DB::table('form_signers')->where('role', 'listing_agent')->value('status'))->toBe('waiting');
});

it('takes each signer through consent and signing, in order, then seals and files the signed copy', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $seller = linkFor('maria@example.test');
    auth()->logout();

    $this->get($seller)->assertInertia(fn (Assert $p) => $p->component('public/sign')->where('signer.consented', false));
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasErrors('sign');
    $this->post($seller.'/consent', ['agree' => true])->assertSessionHasNoErrors();
    $this->post($seller.'/sign', ['typed' => 'Someone Else', 'initials' => 'MG'])->assertSessionHasErrors('signature');
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasNoErrors();

    $agent = linkFor('pat@example.test');
    expect($agent)->not->toBe($seller);
    $this->post($agent.'/consent', ['agree' => true]);
    $this->post($agent.'/sign', ['drawn' => PNG_DOT, 'initials' => 'PL'])->assertSessionHasNoErrors();

    $p = DB::table('form_packets')->first();
    $pdf = (string) Storage::disk('local')->get((string) $p->sealed_path);
    expect($p->status)->toBe('completed')->and(hash('sha256', $pdf))->toBe($p->sealed_sha256)
        ->and(app(Sealer::class)->valid((string) $p->sealed_sha256, (string) $p->seal_signature))->toBeTrue();

    $file = tempnam(sys_get_temp_dir(), 'sealed');
    file_put_contents($file, $pdf);
    $text = new Process(['pdftotext', '-layout', $file, '-']);
    $text->run();
    expect($text->getOutput())->toContain('12 Oak St, Austin, TX')->toContain('Maria Gomez')->toContain('MG')->toContain('06/01/2026')
        ->toContain('Signature certificate')->toContain('consented')->toContain('127.0.0.1');

    Notification::assertSentOnDemand(PacketCompleted::class, fn (PacketCompleted $n): bool => $n->pdf === $pdf);
    $doc = DB::table('tx_documents')->where('deal_id', $this->deal->id)->first();
    expect($doc->via)->toBe('esign')->and(Item::query()->find($this->item->id)?->status)->toBe('submitted');
    $this->get($seller.'/document.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('checks a text code when the brokerage asks for it', function (): void {
    $sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $sms);
    // The seller's number comes from the deal; the agent has none on file yet.
    DB::table('form_signers')->where('role', 'listing_agent')->update(['phone' => null]);
    expect(fn () => $this->signing->send($this->packet, 'sms', $this->agent, 'http://acme.rightally.app'))->toThrow(ValidationException::class, 'mobile number');
    DB::table('form_signers')->where('role', 'listing_agent')->update(['phone' => '+15125550199']);

    $this->signing->send($this->packet, 'sms', $this->agent, 'http://acme.rightally.app');
    $seller = linkFor('maria@example.test');
    auth()->logout();
    $this->post($seller.'/consent', ['agree' => true]);
    $code = substr($sms->sent[0]['message'], 0, 6);
    expect($sms->sent[0]['to'])->toBe('+15125550123');

    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasErrors('sign');
    $this->post($seller.'/verify', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post($seller.'/verify', ['code' => $code])->assertSessionHasNoErrors();
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasNoErrors();
    expect(DB::table('form_signers')->where('role', 'seller1')->value('verified_at'))->not->toBeNull();
});

it('verifies a genuine signed copy and rejects an altered one', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    foreach (['maria@example.test' => 'Maria Gomez', 'pat@example.test' => 'Pat Lee'] as $email => $name) {
        $t = tokenOf(linkFor($email));
        $this->signing->consent($t, null);
        $this->signing->sign($t, $name, null, 'XX', null, null, 'http://acme.rightally.app');
    }
    $pdf = (string) Storage::disk('local')->get((string) DB::table('form_packets')->value('sealed_path'));
    auth()->logout();

    $this->post('/verify-document', ['file' => UploadedFile::fake()->createWithContent('s.pdf', $pdf)])
        ->assertInertia(fn (Assert $p) => $p->where('result.valid', true)->where('result.signers', ['Maria Gomez', 'Pat Lee']));
    $this->post('/verify-document', ['file' => UploadedFile::fake()->createWithContent('s.pdf', $pdf.' ')])->assertInertia(fn (Assert $p) => $p->where('result.valid', false));
});

it('lets a signer decline with a reason, telling the agent, and lets the agent void', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $seller = linkFor('maria@example.test');
    auth()->logout();
    $this->post($seller.'/decline', ['reason' => 'The price is wrong'])->assertSessionHasNoErrors();
    expect(DB::table('form_packets')->value('status'))->toBe('declined');
    Notification::assertSentTo($this->agent, SignerDeclined::class, fn (SignerDeclined $n): bool => $n->reason === 'The price is wrong');

    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/void", ['reason' => 'Price changed; new packet'])->assertSessionHasNoErrors();
    expect(DB::table('form_packets')->value('status'))->toBe('voided');
    auth()->logout();
    // Voiding kills the signing links.
    $this->get($seller)->assertNotFound();
    $this->post($seller.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertNotFound();
});

it('reminds unsigned signers every two days, up to three times, with a fresh link', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $first = linkFor('maria@example.test');
    foreach ([1, 3, 5, 7, 9] as $day) {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00')->addDays($day));
        $this->signing->remind('http://acme.rightally.app');
    }
    expect((int) DB::table('form_signers')->where('role', 'seller1')->value('reminders'))->toBe(3);
    Notification::assertSentOnDemandTimes(SignatureRequested::class, 4);
    auth()->logout();
    $this->get($first)->assertNotFound();
    $this->get(linkFor('maria@example.test'))->assertOk();
});

it('lets the agent host in-person signing on their device, recorded as such', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $seller = (string) DB::table('form_signers')->where('role', 'seller1')->value('ulid');
    $agent = (string) DB::table('form_signers')->where('role', 'listing_agent')->value('ulid');
    expect(fn () => $this->signing->inPerson($this->packet, $agent, $this->agent, 'http://acme.rightally.app'))->toThrow(ValidationException::class, 'Others must sign');

    $location = $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/in-person/{$seller}", [], ['X-Inertia' => 'true'])->headers->get('X-Inertia-Location');
    $path = (string) parse_url((string) $location, PHP_URL_PATH);
    $this->post($path.'/consent', ['agree' => true]);
    $this->post($path.'/sign', ['typed' => 'Maria Gomez', 'initials' => 'MG'])->assertSessionHasNoErrors();
    expect(DB::table('form_events')->where('action', 'in_person')->exists())->toBeTrue()
        ->and(DB::table('form_signers')->where('role', 'seller1')->value('hosted_by'))->toBe($this->agent->id);
});

it('sends the signing and verification pages the data they need', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $seller = linkFor('maria@example.test');
    auth()->logout();
    assertPageContract($this->get($seller), 'public/sign');
    assertPageContract($this->get('/verify-document'), 'public/verify-document');
    assertPageContract($this->actingAs($this->agent)->get("/forms/packets/{$this->packet}"), 'forms/packet');
});
