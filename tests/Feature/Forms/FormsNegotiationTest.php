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
use Modules\Forms\ChangesToInitial;
use Modules\Forms\Negotiations;
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

/** The path of the last link of a kind emailed to an address. */
function negotiationLink(string $email, string $class = SignatureRequested::class): string
{
    $url = null;
    Notification::assertSentOnDemand($class, function (object $n, array $c, object $to) use ($email, &$url): bool {
        if ($to->routes['mail'] === $email) {
            $url = $n->url;
        }

        return true;
    });

    return (string) parse_url((string) $url, PHP_URL_PATH);
}

function signAs(object $test, string $link, string $name, string $initials): void
{
    $test->post($link.'/consent', ['agree' => true])->assertSessionHasNoErrors();
    $test->post($link.'/sign', ['typed' => $name, 'initials' => $initials])->assertSessionHasNoErrors();
}

function formId(): int
{
    return (int) DB::table('form_packet_forms')->orderBy('id')->value('id');
}

it('asks signers who already signed to initial only what changed, then completes with the changes on the certificate', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    signAs($this, negotiationLink('maria@example.test'), 'Maria Gomez', 'MG');

    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/amend", ['form' => formId(), 'values' => ['address' => '14 Oak St, Austin, TX'], 'reason' => 'Wrong house number'])
        ->assertSessionHasNoErrors();
    expect(DB::table('form_changes')->count())->toBe(1)->and(DB::table('form_change_acks')->count())->toBe(1);
    Notification::assertSentOnDemand(ChangesToInitial::class, fn (ChangesToInitial $n): bool => $n->changes === ['Address: 12 Oak St, Austin, TX → 14 Oak St, Austin, TX']
        && implode(' ', $n->toMail(new stdClass)->outroLines) !== '' && str_contains(implode(' ', $n->toMail(new stdClass)->outroLines), 'never wire money'));

    // The agent signs the new terms; the packet waits for Maria's initials.
    auth()->logout();
    signAs($this, negotiationLink('pat@example.test'), 'Pat Lee', 'PL');
    expect(DB::table('form_packets')->value('status'))->toBe('sent');

    $maria = negotiationLink('maria@example.test', ChangesToInitial::class);
    $this->get($maria)->assertInertia(fn (Assert $p) => $p->component('public/sign')->where('signer.signed', true)
        ->where('changes.0.label', 'Address')->where('changes.0.old', '12 Oak St, Austin, TX')->where('changes.0.new', '14 Oak St, Austin, TX')->where('changes.0.reason', 'Wrong house number'));
    $this->post($maria.'/acknowledge', ['initials' => 'M1'])->assertSessionHasErrors('initials');
    $this->post($maria.'/acknowledge', ['initials' => 'mg'])->assertSessionHasNoErrors();

    $p = DB::table('form_packets')->first();
    expect($p->status)->toBe('completed')->and(DB::table('form_change_acks')->value('initials'))->toBe('MG');
    $file = tempnam(sys_get_temp_dir(), 'sealed');
    file_put_contents($file, (string) Storage::disk('local')->get((string) $p->sealed_path));
    $text = new Process(['pdftotext', $file, '-']);
    $text->run();
    expect($text->getOutput())->toContain('14 Oak St, Austin, TX')->toContain('Changes made after sending')->toContain('Wrong house number')
        ->toContain('initialled by:')->toContain('Maria Gomez (MG, 2026-06-01');
});

it('needs no initials for changes made before anyone signed', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    app(Negotiations::class)->amend($this->packet, formId(), ['address' => '14 Oak St'], 'Typo', $this->agent, 'http://acme.rightally.app');
    Notification::assertSentOnDemandTimes(ChangesToInitial::class, 0);
    auth()->logout();
    signAs($this, negotiationLink('maria@example.test'), 'Maria Gomez', 'MG');
    signAs($this, negotiationLink('pat@example.test'), 'Pat Lee', 'PL');
    expect(DB::table('form_packets')->value('status'))->toBe('completed')->and(DB::table('form_change_acks')->count())->toBe(0);
});

it('guards changes: only while out for signature, with a reason, a real change, and no wiring instructions', function (): void {
    $n = app(Negotiations::class);
    $url = 'http://acme.rightally.app';
    expect(fn () => $n->amend($this->packet, formId(), ['address' => 'X'], 'Typo', $this->agent, $url))->toThrow(ValidationException::class, 'Only a packet out for signature');
    $this->signing->send($this->packet, 'email', $this->agent, $url);
    expect(fn () => $n->amend($this->packet, formId(), ['address' => 'X'], '', $this->agent, $url))->toThrow(ValidationException::class, 'Say why')
        ->and(fn () => $n->amend($this->packet, formId(), ['address' => '12 Oak St, Austin, TX', 'seller_sig' => 'forged'], 'Same', $this->agent, $url))->toThrow(ValidationException::class, 'Nothing changed')
        ->and(fn () => $n->amend($this->packet, formId(), ['address' => 'Wire funds to account 123456789 routing 021000021'], 'New bank', $this->agent, $url))->toThrow(ValidationException::class);
    $stranger = User::factory()->create();
    expect(fn () => $n->amend($this->packet, formId(), ['address' => 'X'], 'Typo', $stranger, $url))->toThrow(Exception::class);
});

it('drafts a counter-offer with a summary of changed terms, voids the original and links the history', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    $form = formId();
    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/counter", ['changes' => [$form => ['address' => '14 Oak St, Austin, TX']]])
        ->assertRedirect();
    $counter = DB::table('form_packets')->where('kind', 'counter')->first();
    $original = DB::table('form_packets')->where('ulid', $this->packet)->first();
    expect($original->status)->toBe('voided')->and($original->void_reason)->toBe('Countered in round 2')
        ->and($counter->status)->toBe('draft')->and((int) $counter->round)->toBe(2)->and($counter->title)->toBe('Listing paperwork — counter 1')
        ->and($counter->negotiation_ulid)->toBe($this->packet)->and((int) $counter->parent_packet_id)->toBe((int) $original->id)
        ->and(json_decode((string) DB::table('form_packet_forms')->where('packet_id', $counter->id)->value('values'), true)['address'])->toBe('14 Oak St, Austin, TX')
        ->and(DB::table('form_signers')->where('packet_id', $counter->id)->pluck('email')->all())->toBe(DB::table('form_signers')->where('packet_id', $original->id)->pluck('email')->all());

    $this->get("/forms/packets/{$counter->ulid}")->assertInertia(fn (Assert $p) => $p->component('forms/packet')->where('packet.kind', 'counter')
        ->has('negotiation', 2)->where('negotiation.1.changes.0', 'Address: 12 Oak St, Austin, TX → 14 Oak St, Austin, TX'));

    // A counter needs a real change, and a draft can't be countered.
    expect(fn () => app(Negotiations::class)->counter((string) $counter->ulid, [], $this->agent))->toThrow(ValidationException::class, 'Counter a packet');
    $this->signing->send((string) $counter->ulid, 'email', $this->agent, 'http://acme.rightally.app');
    $cf = (int) DB::table('form_packet_forms')->where('packet_id', $counter->id)->value('id');
    expect(fn () => app(Negotiations::class)->counter((string) $counter->ulid, [$cf => ['address' => '14 Oak St, Austin, TX']], $this->agent))
        ->toThrow(ValidationException::class, 'Change at least one term');
    $third = app(Negotiations::class)->counter((string) $counter->ulid, [$cf => ['address' => '16 Oak St, Austin, TX']], $this->agent);
    expect(DB::table('form_packets')->where('ulid', $third)->value('title'))->toBe('Listing paperwork — counter 2')
        ->and(Negotiations::history(DB::table('form_packets')->where('ulid', $third)->first()))->toHaveCount(3);
});

it('adds an addendum to a signed packet with the same signers', function (): void {
    expect(fn () => app(Negotiations::class)->addendum($this->packet, [DB::table('form_templates')->value('ulid')], $this->agent))->toThrow(ValidationException::class, 'fully signed');
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    auth()->logout();
    signAs($this, negotiationLink('maria@example.test'), 'Maria Gomez', 'MG');
    signAs($this, negotiationLink('pat@example.test'), 'Pat Lee', 'PL');

    $this->actingAs($this->agent)->post("/forms/packets/{$this->packet}/addendum", ['templates' => [DB::table('form_templates')->value('ulid')]])->assertRedirect();
    $add = DB::table('form_packets')->where('kind', 'addendum')->first();
    expect($add->title)->toBe('Addendum to Listing paperwork')->and($add->status)->toBe('draft')->and($add->negotiation_ulid)->toBe($this->packet)
        ->and(DB::table('form_signers')->where('packet_id', $add->id)->orderBy('sign_order')->pluck('name')->all())->toBe(['Maria Gomez', 'Pat Lee']);
    $this->get("/forms/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $p) => $p->where('packets.0.kind', 'addendum'));
});

it('sends the packet and signing pages the data they need while changes are pending', function (): void {
    $this->signing->send($this->packet, 'email', $this->agent, 'http://acme.rightally.app');
    signAs($this, negotiationLink('maria@example.test'), 'Maria Gomez', 'MG');
    app(Negotiations::class)->amend($this->packet, formId(), ['address' => '14 Oak St'], 'Typo', $this->agent, 'http://acme.rightally.app');
    assertPageContract($this->actingAs($this->agent)->get("/forms/packets/{$this->packet}"), 'forms/packet');
    assertPageContract($this->actingAs($this->agent)->get("/forms/deals/{$this->deal->ulid}"), 'forms/deal');
    auth()->logout();
    assertPageContract($this->get(negotiationLink('maria@example.test', ChangesToInitial::class)), 'public/sign');
});
