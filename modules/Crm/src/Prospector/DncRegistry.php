<?php

declare(strict_types=1);

namespace Modules\Crm\Prospector;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;

/**
 * The brokerage's copy of the National Do Not Call Registry (LQ2, specs/grow/15).
 *
 * Telemarketers must check the registry before cold calls, using their own subscription (an organisation ID and
 * Subscription Account Number from telemarketing.donotcall.gov), and must use data no older than 31 days. RightAlly
 * cannot download it for a brokerage, so the broker downloads the files for their area codes and uploads them here
 * every month. Only a hash of each number is kept. A number is clear to cold-call only when its area code was uploaded
 * within 31 days, it is not on that list, and nobody at the brokerage marked it Do Not Call.
 */
final class DncRegistry
{
    public const FRESH_DAYS = 31;

    public const CLEAR = 'clear';

    public const LISTED = 'listed';

    public const INTERNAL = 'internal';

    public const STALE = 'stale';

    public const NOT_COVERED = 'not_covered';

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Loads a registry download (one number per line, "AAANNNNNNN" or "AAA,NNNNNNN"), replacing the area codes it holds.
     *
     * @return array<string, int> numbers loaded per area code
     */
    public function import(string $contents, CarbonImmutable $downloadedOn, User $by): array
    {
        if ($downloadedOn->gt(CarbonImmutable::today()) || $downloadedOn->lt(CarbonImmutable::today()->subDays(self::FRESH_DAYS))) {
            throw ValidationException::withMessages(['downloaded_on' => 'Use a registry download from the last 31 days.']);
        }
        $byArea = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $digits = (string) preg_replace('/\D/', '', $line);
            if (strlen($digits) === 11 && $digits[0] === '1') {
                $digits = substr($digits, 1);
            }
            if (strlen($digits) === 10) {
                $byArea[substr($digits, 0, 3)][] = $digits;
            }
        }
        if ($byArea === []) {
            throw ValidationException::withMessages(['file' => 'That file has no phone numbers. Upload the registry download for your area codes.']);
        }
        $db = DB::connection(Connections::cell());
        $tenant = $this->tenants->id();
        $counts = [];
        foreach ($byArea as $area => $numbers) {
            $area = (string) $area;
            $db->transaction(function () use ($db, $tenant, $area, $numbers, $downloadedOn, $by, &$counts): void {
                $db->table('crm_dnc_numbers')->where('tenant_id', $tenant)->where('area_code', $area)->delete();
                foreach (array_chunk(array_values(array_unique($numbers)), 1000) as $chunk) {
                    $db->table('crm_dnc_numbers')->insertOrIgnore(array_map(fn (string $n): array => ['tenant_id' => $tenant, 'area_code' => $area, 'number_hash' => self::hash($n)], $chunk));
                }
                $counts[$area] = count(array_unique($numbers));
                $db->table('crm_dnc_imports')->updateOrInsert(['tenant_id' => $tenant, 'area_code' => $area],
                    ['numbers' => $counts[$area], 'downloaded_on' => $downloadedOn->toDateString(), 'uploaded_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
            });
        }
        app(AuditLogger::class)->record('crm.dnc_registry.imported', metadata: ['area_codes' => array_keys($counts), 'numbers' => array_sum($counts),
            'downloaded_on' => $downloadedOn->toDateString()], severity: 'notice');

        return $counts;
    }

    /** Whether a number may be cold-called, and if not, why (one of the constants). */
    public function status(?string $phone): string
    {
        $digits = substr((string) preg_replace('/\D/', '', (string) $phone), -10);
        if (strlen($digits) !== 10) {
            return self::NOT_COVERED;
        }
        if (Contact::query()->where('primary_phone', '+1'.$digits)->where('do_not_call', true)->exists()) {
            return self::INTERNAL;
        }
        $db = DB::connection(Connections::cell());
        $import = $db->table('crm_dnc_imports')->where('tenant_id', $this->tenants->id())->where('area_code', substr($digits, 0, 3))->first();
        if ($import === null) {
            return self::NOT_COVERED;
        }
        if (CarbonImmutable::parse((string) $import->downloaded_on)->lt(CarbonImmutable::today()->subDays(self::FRESH_DAYS))) {
            return self::STALE;
        }

        return $db->table('crm_dnc_numbers')->where('tenant_id', $this->tenants->id())->where('number_hash', self::hash($digits))->exists() ? self::LISTED : self::CLEAR;
    }

    /** In plain words, for the agent. */
    public static function words(string $status): string
    {
        return match ($status) {
            self::CLEAR => 'Clear to call: not on the Do Not Call Registry.',
            self::LISTED => 'On the National Do Not Call Registry: send a card instead.',
            self::INTERNAL => 'Marked Do Not Call at your brokerage: send a card instead.',
            self::STALE => 'Your registry list for this area code is over 31 days old: ask your broker to upload a new one before calling.',
            default => 'Your brokerage has not uploaded the registry list for this area code yet, so it can\'t be called.',
        };
    }

    /**
     * Area codes on file, newest first, and whether each is still fresh.
     *
     * @return list<array{area_code: string, numbers: int, downloaded_on: string, fresh: bool}>
     */
    public function imports(): array
    {
        return array_values(DB::connection(Connections::cell())->table('crm_dnc_imports')->where('tenant_id', $this->tenants->id())->orderBy('area_code')->get()
            ->map(fn ($i): array => ['area_code' => (string) $i->area_code, 'numbers' => (int) $i->numbers, 'downloaded_on' => (string) $i->downloaded_on,
                'fresh' => CarbonImmutable::parse((string) $i->downloaded_on)->gte(CarbonImmutable::today()->subDays(self::FRESH_DAYS))])->all());
    }

    private static function hash(string $tenDigits): string
    {
        return hash('sha256', 'dnc:'.$tenDigits);
    }
}
