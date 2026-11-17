<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Identity\Actions\AcceptInvitation;
use Modules\Core\Identity\Models\Invitation;
use Modules\Ledger\ChartOfAccounts;
use Modules\Ledger\JournalDraft;
use Modules\Ledger\LedgerService;
use Modules\Ledger\Models\LedgerPeriod;
use Modules\Ledger\Money\Money;

/*
| FH2 (specs/finance/23): the audit pack and read-only access for an outside auditor.
*/

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia', 'last_name' => 'Owner']);
    $chart = app(ChartOfAccounts::class);
    $chart->seed();
    app(LedgerService::class)->post(JournalDraft::make('test', 'sep')->effectiveOn(CarbonImmutable::parse('2026-09-10'))
        ->debit($chart->account('1000'), Money::of(100_000))->credit($chart->account('3000'), Money::of(100_000))->describe('=HYPERLINK("x")'));
    $this->actingAs($this->owner)->post('/finance/periods/'.LedgerPeriod::query()->sole()->id.'/close')->assertSessionHasNoErrors();
});

it('makes a password-protected audit pack with a manifest, statements as closed and every entry', function (): void {
    $this->actingAs($this->owner)->post('/finance/audit/packs', ['from' => '2026-01-01', 'to' => '2026-12-31'])->assertSessionHasNoErrors();
    $pack = DB::table('ledger_audit_packs')->sole();
    expect($pack->status)->toBe('ready');

    $this->actingAs($this->owner)->get('/finance/audit/packs/'.$pack->ulid)->assertRedirect(); // asks for the password first
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/finance/audit/packs/'.$pack->ulid.'/password')->assertRedirect();
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get('/finance/audit')
        ->assertInertia(fn (Assert $page) => $page->component('finance/audit')->where('packs.0.status', 'ready')->where('can.auditors', true));
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get('/finance/audit/packs/'.$pack->ulid)->assertOk();

    $file = tempnam(sys_get_temp_dir(), 'pk');
    file_put_contents($file, Storage::disk('local')->get((string) $pack->path));
    $zip = new ZipArchive;
    $zip->open($file);
    expect($zip->getFromName('README.txt'))->toBeFalse(); // encrypted without the password
    $zip->setPassword(decrypt_pack_password($pack->password));
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    foreach ($manifest['sha256'] as $name => $hash) {
        expect(hash('sha256', (string) $zip->getFromName($name)))->toBe($hash);
    }
    expect(array_keys($manifest['sha256']))->toContain('statements/2026-09.pdf', 'general_ledger.csv', 'adjustments.csv', 'month_end.csv', '1099_tieout.csv', 'payout_runs.csv', 'activity_log_check.txt')
        ->and((string) $zip->getFromName('general_ledger.csv'))->toContain('\'=HYPERLINK')
        ->and((string) $zip->getFromName('month_end.csv'))->toContain('2026-09,closed')
        ->and((string) $zip->getFromName('README.txt'))->toContain('Every month in this range is closed.');
    $zip->close();
});

it('lets the owner give an auditor read-only access that ends by itself', function (): void {
    $this->actingAs($this->owner)->post('/finance/audit/auditors', ['email' => 'cpa@firm.test', 'first_name' => 'Casey', 'last_name' => 'Auditor', 'firm' => 'Firm LLP', 'days' => 3])
        ->assertSessionHasErrors('days');
    $this->actingAs($this->owner)->post('/finance/audit/auditors', ['email' => 'cpa@firm.test', 'first_name' => 'Casey', 'last_name' => 'Auditor', 'firm' => 'Firm LLP', 'days' => 14])
        ->assertSessionHasNoErrors();
    $auditor = fh2Accept();

    $this->actingAs($auditor)->get('/finance/journals')->assertOk();
    $this->actingAs($auditor)->get('/finance/audit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.auditors', false)->where('auditors', []));
    $this->actingAs($auditor)->post('/finance/adjustments', [])->assertForbidden();
    $this->actingAs($auditor)->post('/finance/periods/'.LedgerPeriod::query()->sole()->id.'/check')->assertForbidden();
    $this->actingAs($auditor)->post('/finance/audit/auditors', ['email' => 'x@y.test', 'first_name' => 'A', 'last_name' => 'B', 'days' => 14])->assertForbidden();

    $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00'));
    $this->artisan('core:expire-access')->assertSuccessful(); // hourly in production
    $this->actingAs($auditor->fresh())->get('/finance/journals')->assertForbidden();
});

it('ends an auditor\'s access straight away when the owner asks', function (): void {
    $this->actingAs($this->owner)->post('/finance/audit/auditors', ['email' => 'cpa@firm.test', 'first_name' => 'Casey', 'last_name' => 'Auditor', 'days' => 30]);
    $auditor = fh2Accept();
    $this->actingAs($auditor)->get('/finance/journals')->assertOk();

    $this->actingAs($this->owner)->post('/finance/audit/auditors/'.DB::table('ledger_auditor_access')->value('ulid').'/revoke')->assertRedirect();
    $this->actingAs($auditor->fresh())->get('/finance/journals')->assertForbidden();
    expect(DB::table('ledger_auditor_access')->value('revoked_at'))->not->toBeNull();
});

/** The auditor accepts; risky access (exporting the books) needs two-factor sign-in, which they set up. */
function fh2Accept(): User
{
    $auditor = app(AcceptInvitation::class)->handle(Invitation::query()->where('email', 'cpa@firm.test')->sole(), 'Casey', 'Auditor', 'a-Long-password-123!');
    $auditor->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

    return $auditor;
}

function decrypt_pack_password(string $sealed): string
{
    return Crypt::decryptString($sealed);
}
