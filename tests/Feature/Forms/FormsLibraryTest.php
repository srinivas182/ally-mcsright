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
use Modules\Forms\Identity\IdentityProvider;
use Modules\Forms\Library\FieldMapper;
use Modules\Forms\Library\FillableFields;
use Modules\Forms\Library\FormUpdateAvailable;
use Modules\Forms\Library\Importer;
use Modules\Forms\Library\Libraries;
use Modules\Forms\Library\TestLibrary;
use Modules\Forms\Packets;
use Modules\Forms\Signing;
use Modules\Transactions\Models\Deal;

require_once __DIR__.'/../Contracts/contract-helpers.php';

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    TestLibrary::$forms = [
        'listing' => ['name' => 'Residential Listing Agreement', 'edition' => '11-2025', 'category' => 'Listing', 'extra' => ''],
        'seller-disclosure' => ['name' => "Seller's Disclosure Notice", 'edition' => '01-2026', 'category' => 'Disclosure', 'extra' => ''],
    ];
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.test']);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->actingAs($this->agent)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '485000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
    DB::table('tx_parties')->insert(['tenant_id' => $this->deal->tenant_id, 'deal_id' => $this->deal->id, 'role' => 'seller', 'name' => 'Maria Gomez', 'email' => 'maria@example.test',
        'created_at' => now(), 'updated_at' => now()]);
});

function libraryForm(string $external): int
{
    return (int) DB::table('form_library_forms')->where('external_id', $external)->value('id');
}

it('connects an association library only with a verified member number, and lists its forms', function (): void {
    $this->actingAs($this->agent)->post('/forms/libraries', ['connector' => 'test', 'member_id' => '123456789', 'last_name' => 'Lee'])->assertSessionHasErrors('forms');
    $this->actingAs($this->owner)->post('/forms/libraries', ['connector' => 'test', 'member_id' => '000000000', 'last_name' => 'Lee'])->assertSessionHasErrors('member_id');
    $this->actingAs($this->owner)->post('/forms/libraries', ['connector' => 'nope'])->assertSessionHasErrors('connector');
    $this->actingAs($this->owner)->post('/forms/libraries', ['connector' => 'test', 'member_id' => '123456789', 'last_name' => 'Lee'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/forms/libraries', ['connector' => 'test', 'member_id' => '123456789', 'last_name' => 'Lee'])->assertSessionHasErrors('connector');

    $this->actingAs($this->owner)->get('/forms/libraries')->assertInertia(fn (Assert $p) => $p->component('forms/libraries')
        ->where('libraries.0.member_id', '•••••6789')->has('libraries.0.forms', 2)
        ->where('libraries.0.forms.0.name', "Seller's Disclosure Notice")->where('libraries.0.forms.1.name', 'Residential Listing Agreement')->where('libraries.0.forms.1.added', false));
    $this->actingAs($this->agent)->get('/forms/libraries')->assertForbidden();
});

it('adds a library form with its fields placed, tracks editions, alerts once, shows what changed and updates without touching sent packets', function (): void {
    app()->instance(IdentityProvider::class, new FakeIdentity); // high-risk forms need ID checks
    $libraries = app(Libraries::class);
    $libraries->connect('test', '123456789', 'Lee', $this->owner);
    $this->actingAs($this->owner)->post('/forms/library-forms/'.libraryForm('listing').'/import')->assertSessionHasNoErrors();
    $old = DB::table('form_templates')->sole();
    expect($old->edition)->toBe('11-2025')->and($old->source)->toBe('association')->and(count(json_decode((string) $old->fields, true)))->toBe(4);
    DB::table('form_templates')->where('id', $old->id)->update(['checklist_label' => 'Listing agreement', 'high_risk' => true]);
    expect(fn () => $libraries->import(libraryForm('listing'), $this->owner))->toThrow(ValidationException::class, 'newest edition');

    // One packet goes out on the old edition; another stays a draft.
    $sent = app(Packets::class)->create($this->deal, 'Sent one', [(string) $old->ulid], $this->agent);
    app(Signing::class)->send($sent, 'email', $this->agent, 'http://acme.rightally.app');
    $draft = app(Packets::class)->create($this->deal, 'Draft one', [(string) $old->ulid], $this->agent);
    $form = (int) DB::table('form_packet_forms')->where('packet_id', DB::table('form_packets')->where('ulid', $draft)->value('id'))->value('id');
    app(Packets::class)->setValues($draft, $form, ['address' => '12 Oak St, Austin, TX', 'price' => '$479,000.00'], $this->agent);

    // The association releases a new edition.
    TestLibrary::$forms['listing']['edition'] = '02-2026';
    TestLibrary::$forms['listing']['extra'] = '3. Seller will disclose any known flood damage.';
    $this->artisan('forms:library-sync')->assertSuccessful();
    $this->artisan('forms:library-sync');
    Notification::assertSentToTimes($this->owner, FormUpdateAvailable::class, 1);
    Notification::assertSentTo($this->owner, FormUpdateAvailable::class, fn (FormUpdateAvailable $n): bool => $n->forms === ['Residential Listing Agreement: 11-2025 → 02-2026']);
    Notification::assertNotSentTo($this->agent, FormUpdateAvailable::class);

    $this->actingAs($this->owner)->get('/forms/libraries?compare='.libraryForm('listing'))->assertInertia(fn (Assert $p) => $p
        ->where('libraries.0.forms.1.update', true)->where('libraries.0.forms.1.your_edition', '11-2025')->where('libraries.0.forms.0.added', false)
        ->where('comparison.from', '11-2025')->where('comparison.to', '02-2026')
        ->where('comparison.added', ['Edition 02-2026', '3. Seller will disclose any known flood damage.'])->where('comparison.removed', ['Edition 11-2025']));

    $this->actingAs($this->owner)->post('/forms/library-forms/'.libraryForm('listing').'/import')->assertSessionHasNoErrors();
    $new = DB::table('form_templates')->where('edition', '02-2026')->sole();
    $retired = DB::table('form_templates')->where('id', $old->id)->first();
    expect((bool) $retired->active)->toBeFalse()->and((int) $retired->superseded_by)->toBe((int) $new->id)
        ->and($new->checklist_label)->toBe('Listing agreement')->and((bool) $new->high_risk)->toBeTrue();

    // The sent packet keeps its edition; the draft must switch before sending, keeping its values.
    $sentId = (int) DB::table('form_packets')->where('ulid', $sent)->value('id');
    expect((int) DB::table('form_packet_forms')->where('packet_id', $sentId)->value('template_id'))->toBe((int) $old->id);
    expect(fn () => app(Packets::class)->useNewestEditions($sent, $this->agent))->toThrow(ValidationException::class, 'Only a draft');
    expect(app(Packets::class)->check($draft, $this->agent))->toContain('Residential Listing Agreement: a newer edition (02-2026) is out. Switch to it before sending.');
    $this->actingAs($this->agent)->post("/forms/packets/{$draft}/newest-editions")->assertSessionHasNoErrors();
    $switched = DB::table('form_packet_forms')->where('id', $form)->first();
    expect((int) $switched->template_id)->toBe((int) $new->id)->and(json_decode((string) $switched->values, true)['price'])->toBe('$479,000.00')
        ->and(app(Packets::class)->check($draft, $this->agent))->not->toContain('Residential Listing Agreement: a newer edition (02-2026) is out. Switch to it before sending.');
});

it('imports fillable PDFs from other systems with fields placed, signers and deal details matched', function (): void {
    $pdf = (string) file_get_contents(base_path('tests/Fixtures/forms/fillable-listing.pdf'));
    expect(FillableFields::read($pdf))->toHaveCount(10, 'qpdf: '.FillableFields::$lastError);
    $this->actingAs($this->owner)->post('/forms/import', ['source' => 'zipform', 'files' => [UploadedFile::fake()->createWithContent('Exclusive_Right_to_Sell.pdf', $pdf)]])
        ->assertSessionHasNoErrors();
    $t = DB::table('form_templates')->sole();
    expect($t->name)->toBe('Exclusive Right to Sell')->and($t->import_source)->toBe('zipform');
    $fields = collect(json_decode((string) $t->fields, true))->keyBy('label');
    expect($fields['Property address'])->toMatchArray(['type' => 'text', 'bind' => 'deal.address', 'page' => 1, 'x' => 72.0, 'y' => 96.0, 'w' => 300.0, 'h' => 16.0])
        ->and($fields['List price']['bind'])->toBe('deal.price')
        ->and($fields['Closing date'])->toMatchArray(['type' => 'date', 'bind' => 'deal.closing_date'])
        ->and($fields['Seller name']['bind'])->toBe('seller.names')
        ->and($fields['Hoa']['type'])->toBe('checkbox')
        ->and($fields['Seller 1 signature'])->toMatchArray(['type' => 'signature', 'role' => 'seller1'])
        ->and($fields['Seller 1 initials'])->toMatchArray(['type' => 'initials', 'role' => 'seller1'])
        ->and($fields['Date signed seller'])->toMatchArray(['type' => 'date_signed', 'role' => 'seller1'])
        ->and($fields['Listing agent s signature'])->toMatchArray(['type' => 'signature', 'role' => 'listing_agent'])
        ->and($fields['Signature'])->toMatchArray(['type' => 'signature', 'role' => FieldMapper::UNASSIGNED]);
    $this->actingAs($this->owner)->get('/forms/libraries')->assertInertia(fn (Assert $p) => $p->where('imported.0.name', 'Exclusive Right to Sell')
        ->where('imported.0.fields', 10)->where('imported.0.needs_signer', 1));

    // A packet using it cannot be sent until someone assigns the unknown signer.
    $packet = app(Packets::class)->create($this->deal, 'Imported', [(string) $t->ulid], $this->agent);
    expect(implode(' ', app(Packets::class)->check($packet, $this->agent)))->toContain('Signature is for "unassigned"');
});

it('imports a ZIP package safely: PDFs only, by file name, never by path', function (): void {
    $zipPath = tempnam(sys_get_temp_dir(), 'pkg');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('Association Forms/Seller_Disclosure.pdf', (string) file_get_contents(base_path('tests/Fixtures/forms/fillable-listing.pdf')));
    $zip->addFromString('../../evil.pdf', (string) file_get_contents(base_path('tests/Fixtures/forms/fillable-listing.pdf')));
    $zip->addFromString('readme.txt', 'not a form');
    $zip->addFromString('__MACOSX/._Seller_Disclosure.pdf', 'junk');
    $zip->addFromString('fake.pdf', 'not really a pdf');
    $zip->close();

    $report = app(Importer::class)->import([new UploadedFile($zipPath, 'package.zip', 'application/zip', null, true)], 'association', $this->owner);
    expect(array_column($report, 'name'))->toBe(['Seller Disclosure', 'evil'])
        ->and(DB::table('form_templates')->pluck('source')->unique()->all())->toBe(['association'])
        ->and(collect(Storage::disk('local')->allFiles())->every(fn (string $f): bool => str_starts_with($f, 'forms/')))->toBeTrue();
    expect(fn () => app(Importer::class)->import([UploadedFile::fake()->createWithContent('notes.txt', 'hello')], 'pdf', $this->owner))->toThrow(ValidationException::class, 'not a PDF');
    expect(fn () => app(Importer::class)->import([UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4')], 'nowhere', $this->owner))->toThrow(ValidationException::class);
    expect(fn () => app(Importer::class)->import([UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4')], 'pdf', $this->agent))->toThrow(ValidationException::class);
});

it('reads field names the way other systems write them', function (): void {
    expect(FieldMapper::words('Seller1Signature'))->toBe('seller 1 signature')
        ->and(FieldMapper::words('buyer_2-initials'))->toBe('buyer 2 initials')
        ->and(FieldMapper::words("Buyer's Agent Signature"))->toBe('buyer s agent signature');
    $sizes = [[612.0, 792.0]];
    $map = fn (string $name, string $type = 'text') => FieldMapper::map([['name' => $name, 'type' => $type, 'page' => 1, 'rect' => [72, 100, 272, 120]]], $sizes)[0];
    expect($map("Buyer's Agent Signature"))->toMatchArray(['type' => 'signature', 'role' => 'buyer_agent'])
        ->and($map('Buyer 2 Signature'))->toMatchArray(['type' => 'signature', 'role' => 'buyer2'])
        ->and($map('Purchase Price')['bind'])->toBe('deal.price')
        ->and($map('Notes')['bind'])->toBeNull()
        ->and(FieldMapper::map([['name' => 'Off page', 'type' => 'text', 'page' => 2, 'rect' => [72, 100, 272, 120]]], $sizes))->toBe([])
        ->and(FieldMapper::map([['name' => 'A', 'type' => 'text', 'page' => 1, 'rect' => [0, 0, 10, 10]], ['name' => 'A', 'type' => 'text', 'page' => 1, 'rect' => [20, 20, 40, 40]]], $sizes)[1]['key'])->toBe('a_2');
});

it('sends the libraries, templates and packet pages the data they need', function (): void {
    app(Libraries::class)->connect('test', '123456789', 'Lee', $this->owner);
    app(Libraries::class)->import(libraryForm('listing'), $this->owner);
    TestLibrary::$forms['listing']['edition'] = '02-2026';
    app(Libraries::class)->sync((string) DB::table('form_libraries')->value('ulid'));
    assertPageContract($this->actingAs($this->owner)->get('/forms/libraries?compare='.libraryForm('listing')), 'forms/libraries');
    assertPageContract($this->actingAs($this->owner)->get('/forms/templates'), 'forms/templates');
    $packet = app(Packets::class)->create($this->deal, 'P', [(string) DB::table('form_templates')->value('ulid')], $this->agent);
    assertPageContract($this->actingAs($this->agent)->get("/forms/packets/{$packet}"), 'forms/packet');
});
