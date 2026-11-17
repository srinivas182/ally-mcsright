<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Identity\Models\Invitation;
use Modules\Crm\Models\Contact;
use Modules\Transactions\Models\Deal;
use Tests\Fixtures\FakeAiProvider;

/*
| SU1 (specs/setup/01): bring everything from the old system's export files: recognised, matched, previewed in plain
| words, saved only when the owner says yes, and undoable for 24 hours.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia', 'last_name' => 'Owner']);
    $this->kim = User::factory()->create(['first_name' => 'Kim', 'last_name' => 'Agent', 'email' => 'kim@acme.test']);
});

function suCsv(string $name, array $rows): UploadedFile
{
    $csv = implode("\n", array_map(fn (array $r): string => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $r)), $rows))."\n";

    return UploadedFile::fake()->createWithContent($name, $csv);
}

function suXlsx(string $name, array $rows): UploadedFile
{
    $strings = [];
    $sheet = '';
    foreach ($rows as $r => $row) {
        $sheet .= '<row r="'.($r + 1).'">';
        foreach (array_values($row) as $c => $v) {
            $strings[] = htmlspecialchars((string) $v);
            $sheet .= '<c r="'.chr(65 + $c).($r + 1).'" t="s"><v>'.(count($strings) - 1).'</v></c>';
        }
        $sheet .= '</row>';
    }
    $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => "<si><t>{$s}</t></si>", $strings)).'</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
    $zip->close();

    return new UploadedFile($path, $name, null, null, true);
}

it('recognises a SkySlope export, shows what will happen, and files the deals under their agents only when the owner says yes', function (): void {
    $file = suCsv('skyslope.csv', [
        ['Property Address', 'City', 'State', 'Zip Code', 'Status', 'Sale Price', 'Escrow Closing Date', 'Agent Name', 'Type'],
        ['12 Oak St', 'Austin', 'TX', '78704', 'Closed', '$450,000.00', '09/15/2026', 'Kim Agent', 'Seller'],
        ['8 Elm Rd', 'Austin', 'TX', '78702', 'Pending', '612,500', '12/20/2026', 'Nobody Here', 'Buyer'],
        ['3 Pine Ct', 'Austin', '', '78745', 'Active', '399000', '', 'Kim Agent', 'Listing'],
        ['9 Bay Ln', 'Austin', 'TX', '78745', 'Thinking about it', '1', '', 'Kim Agent', 'Listing'],
    ]);
    $this->actingAs($this->owner)->post('/setup/import', ['files' => [$file]])->assertRedirect();
    $ulid = (string) DB::table('setup_imports')->value('ulid');

    $this->actingAs($this->owner)->get("/setup/import/{$ulid}")->assertInertia(fn (Assert $page) => $page->component('setup/import-show')
        ->where('import.label', 'SkySlope transactions')->where('import.kind', 'deals')
        ->where('preview.ready', 2)->where('preview.problem_count', 2)->where('preview.problems.0.why', 'No two-letter state (for example TX).')
        ->where('preview.examples.0.address', '12 Oak St, Austin'));
    expect(Deal::query()->count())->toBe(0);

    $this->actingAs($this->owner)->post("/setup/import/{$ulid}/confirm")->assertRedirect();

    $oak = Deal::query()->where('street', '12 Oak St')->sole();
    $elm = Deal::query()->where('street', '8 Elm Rd')->sole();
    expect($oak->status)->toBe('closed')->and($oak->agent_user_id)->toBe($this->kim->id)->and($oak->price_cents)->toBe(45_000_000)
        ->and($oak->locked_at)->not->toBeNull()->and($oak->closing_date?->toDateString())->toBe('2026-09-15')->and($oak->imported_from)->toBe('SkySlope')
        ->and($elm->status)->toBe('under_contract')->and($elm->type)->toBe('buyer')->and($elm->agent_user_id)->toBe($this->owner->id)->and($elm->price_cents)->toBe(61_250_000);

    // The same file again changes nothing; the first one can be undone for 24 hours.
    $this->actingAs($this->owner)->post('/setup/import', ['files' => [$file]]);
    $second = (string) DB::table('setup_imports')->orderByDesc('id')->value('ulid');
    $this->actingAs($this->owner)->get("/setup/import/{$second}")->assertInertia(fn (Assert $page) => $page->where('preview.ready', 0)->where('preview.existing', 2));
    $this->actingAs($this->owner)->post("/setup/import/{$ulid}/undo")->assertRedirect();
    expect(Deal::query()->count())->toBe(0)->and(DB::table('setup_imports')->where('ulid', $ulid)->value('status'))->toBe('undone');
});

it('invites agents from an Excel roster, skipping anyone already here, and can take the invitations back', function (): void {
    $file = suXlsx('roster.xlsx', [
        ['Agent Name', 'Email', 'License #', 'Start Date'],
        ['Lee, Sam', 'sam@x.test', 'TX-1234', '2024-01-02'],
        ['Kim Agent', 'kim@acme.test', 'TX-9', ''],
        ['No Email', 'not-an-email', '', ''],
    ]);
    $this->actingAs($this->owner)->post('/setup/import', ['files' => [$file]])->assertRedirect();
    $ulid = (string) DB::table('setup_imports')->value('ulid');
    $this->actingAs($this->owner)->get("/setup/import/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('import.label', 'Lone Wolf agents')
        ->where('preview.ready', 1)->where('preview.existing', 1)->where('preview.problem_count', 1));

    $this->actingAs($this->owner)->post("/setup/import/{$ulid}/confirm")->assertRedirect();

    $invitation = Invitation::query()->where('email', 'sam@x.test')->sole();
    expect($invitation->first_name)->toBe('Sam')->and($invitation->last_name)->toBe('Lee');
    $this->actingAs($this->owner)->post("/setup/import/{$ulid}/undo")->assertRedirect();
    expect($invitation->fresh()?->revoked_at)->not->toBeNull();
});

it('hands people files to the CRM import with the columns already matched', function (): void {
    $file = suCsv('fub.csv', [
        ['First Name', 'Last Name', 'Email 1', 'Phone 1', 'Stage', 'Assigned To', 'Tags'],
        ['Pat', 'Lee', 'pat@x.test', '5125550100', 'Lead', 'Kim Agent', 'buyer'],
        ['Ana', 'Ruiz', 'ana@x.test', '', 'Past client', 'Kim Agent', ''],
    ]);
    $this->actingAs($this->owner)->post('/setup/import', ['files' => [$file]]);
    $ulid = (string) DB::table('setup_imports')->value('ulid');
    $this->actingAs($this->owner)->get("/setup/import/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('import.label', 'Follow Up Boss people')->where('preview.ready', 2));

    $this->actingAs($this->owner)->post("/setup/import/{$ulid}/confirm")->assertRedirect();

    expect(Contact::query()->pluck('first_name')->sort()->values()->all())->toBe(['Ana', 'Pat'])
        ->and(DB::table('setup_imports')->value('crm_import_id'))->not->toBeNull();
});

it('asks the AI to match unusual columns from their names and kinds of value only, never the values', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat', 'json']]);
    AiFeatureRoute::query()->create(['feature_key' => 'setup.map_columns', 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.setup_map']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond('{"mapping": {"Prop. Addr": "street", "St": "state_code", "Stage": "status", "COE Date": "closing_date", "Made up": "price"}}');

    $this->actingAs($this->owner)->post('/setup/import', ['kind' => 'deals', 'files' => [suCsv('odd.csv', [
        ['Prop. Addr', 'St', 'Stage', 'COE Date'], ['77 Secret Way', 'TX', 'Closed', '10/01/2026'],
    ])]]);

    $row = DB::table('setup_imports')->first();
    expect(json_decode((string) $row->mapping, true))->toEqual(['Prop. Addr' => 'street', 'St' => 'state_code', 'Stage' => 'status', 'COE Date' => 'closing_date'])
        ->and((bool) $row->ai_matched)->toBeTrue()
        ->and($ai->requests[0]->userMessage)->toContain('Prop. Addr (short text)')->not->toContain('77 Secret Way');
});

it('lets the owner fix the matches, and is for owners only', function (): void {
    $this->actingAs($this->owner)->post('/setup/import', ['kind' => 'deals', 'files' => [suCsv('d.csv', [['Where', 'State', 'Status'], ['5 Ash St', 'TX', 'Closed']])]]);
    $ulid = (string) DB::table('setup_imports')->value('ulid');
    $this->actingAs($this->owner)->get("/setup/import/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('preview.missing', ['street']));

    $this->actingAs($this->owner)->put("/setup/import/{$ulid}", ['kind' => 'deals', 'mapping' => ['Where' => 'street', 'State' => 'street', 'Status' => 'status']])->assertSessionHasErrors('mapping');
    $this->actingAs($this->owner)->put("/setup/import/{$ulid}", ['kind' => 'deals', 'mapping' => ['Where' => 'street', 'State' => 'state_code', 'Status' => 'status']])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get("/setup/import/{$ulid}")->assertInertia(fn (Assert $page) => $page->where('preview.ready', 1));

    $this->actingAs($this->kim)->get('/setup/import')->assertForbidden();
    $this->actingAs($this->kim)->post("/setup/import/{$ulid}/confirm")->assertForbidden();
});
