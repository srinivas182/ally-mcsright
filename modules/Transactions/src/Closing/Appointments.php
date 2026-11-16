<?php

declare(strict_types=1);

namespace Modules\Transactions\Closing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;

/**
 * Vendor scheduling (specs/ai/02, §21): inspections, appraisals, surveys and the final walk-through on the deal.
 * The vendor gets a link (no account) to confirm or suggest another time; the agent accepts a new time in one click;
 * the vendor is reminded the day before. When an appointment passes without being marked done, the agent is told
 * which deadline depends on it.
 */
final class Appointments
{
    public const KINDS = ['inspection' => 'Inspection', 'appraisal' => 'Appraisal', 'survey' => 'Survey', 'walkthrough' => 'Final walk-through', 'other' => 'Other'];

    /** Deadlines that depend on each kind (words in the date's name). */
    public const DEPENDS = ['inspection' => '/option|inspection/i', 'appraisal' => '/apprais|financ/i', 'survey' => '/survey|title/i', 'walkthrough' => '/closing/i', 'other' => '/^$/'];

    /** What clients see (the other side's appointments stay with the agent). */
    public const FOR_CLIENTS = ['inspection', 'appraisal', 'walkthrough'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array{kind: string, title?: string|null, vendor_name?: string|null, vendor_email?: string|null, vendor_phone?: string|null, starts_at: string, vendor?: string|null}  $data
     */
    public function book(Deal $deal, array $data, User $by): string
    {
        if ($deal->locked_at !== null) {
            throw ValidationException::withMessages(['starts_at' => 'This deal is closed and locked.']);
        }
        $kind = array_key_exists($data['kind'], self::KINDS) ? $data['kind'] : 'other';
        $vendor = ($data['vendor'] ?? null) !== null ? DB::connection(Connections::cell())->table('tx_vendors')->where('ulid', $data['vendor'])->first() : null;
        $email = $vendor->email ?? ($data['vendor_email'] ?? null);
        $ulid = (string) Str::ulid();
        $token = $email !== null ? Str::random(48) : null;
        $starts = CarbonImmutable::parse($data['starts_at'], $this->tz());
        DB::connection(Connections::cell())->table('tx_appointments')->insert(['ulid' => $ulid, 'tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'kind' => $kind,
            'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: self::KINDS[$kind], 0, 120), 'vendor_name' => $vendor->name ?? ($data['vendor_name'] ?? null),
            'vendor_email' => $email !== null ? mb_strtolower((string) $email) : null, 'vendor_phone' => $vendor->phone ?? ($data['vendor_phone'] ?? null),
            'starts_at' => $starts->utc(), 'status' => $email !== null ? 'requested' : 'confirmed', 'token_hash' => $token !== null ? hash('sha256', $token) : null,
            'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        if ($token !== null) {
            Notification::route('mail', (string) $email)->notify(new VendorMail($deal->street, self::KINDS[$kind], $starts, $by->name, (string) $by->email, url('/appointment/'.$token), false));
        }
        app(AuditLogger::class)->record('transactions.appointment.booked', $deal, metadata: ['kind' => $kind, 'at' => $starts->toIso8601String()], label: $deal->street);

        return $ulid;
    }

    /** The appointment behind a vendor's link, while it is still ahead. */
    public function forVendor(string $token): ?\stdClass
    {
        $a = DB::connection(Connections::cell())->table('tx_appointments')->where('token_hash', hash('sha256', $token))->first();

        return $a !== null && in_array($a->status, ['requested', 'confirmed', 'reschedule'], true) ? $a : null;
    }

    /** The vendor confirms, or suggests another time. */
    public function answer(string $token, bool $confirm, ?string $proposed, ?string $note): void
    {
        $a = $this->forVendor($token) ?? throw ValidationException::withMessages(['answer' => 'This appointment can no longer be changed here. Please contact the agent.']);
        $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;
        $db = DB::connection(Connections::cell());
        if ($confirm) {
            $db->table('tx_appointments')->where('id', $a->id)->update(['status' => 'confirmed', 'proposed_at' => null, 'vendor_note' => $note, 'updated_at' => now()]);
        } else {
            $when = $proposed !== null ? CarbonImmutable::parse($proposed, $this->tz()) : null;
            if ($when === null || $when->isPast()) {
                throw ValidationException::withMessages(['proposed' => 'Suggest a time in the future.']);
            }
            $db->table('tx_appointments')->where('id', $a->id)->update(['status' => 'reschedule', 'proposed_at' => $when->utc(), 'vendor_note' => $note, 'updated_at' => now()]);
        }
        $deal = Deal::query()->whereKey((int) $a->deal_id)->firstOrFail();
        User::query()->whereKey($deal->agent_user_id)->first()?->notify(new AppointmentAnswerMail($deal->street, (string) $a->title, (string) ($a->vendor_name ?? 'The vendor'),
            $confirm, $confirm ? null : CarbonImmutable::parse((string) $proposed, $this->tz()), $note, '/deals/'.$deal->ulid));
        app(AuditLogger::class)->record($confirm ? 'transactions.appointment.confirmed' : 'transactions.appointment.reschedule', $deal, metadata: ['title' => $a->title], label: $deal->street);
    }

    /** The agent accepts the vendor's suggested time (the vendor is told), marks it done, or cancels it. */
    public function update(Deal $deal, string $ulid, string $action, User $by): void
    {
        $db = DB::connection(Connections::cell());
        $a = $db->table('tx_appointments')->where('deal_id', $deal->id)->where('ulid', $ulid)->first() ?? abort(404);
        match ($action) {
            'accept' => $a->proposed_at !== null ? $db->table('tx_appointments')->where('id', $a->id)->update(['starts_at' => $a->proposed_at, 'proposed_at' => null, 'status' => 'confirmed',
                'reminded_at' => null, 'updated_at' => now()]) : throw ValidationException::withMessages(['appointment' => 'There is no new time to accept.']),
            'done' => $db->table('tx_appointments')->where('id', $a->id)->update(['status' => 'done', 'updated_at' => now()]),
            'cancel' => $db->table('tx_appointments')->where('id', $a->id)->update(['status' => 'cancelled', 'updated_at' => now()]),
            default => throw ValidationException::withMessages(['appointment' => 'Unknown action.']),
        };
        if ($action !== 'done' && $a->vendor_email !== null) {
            $starts = CarbonImmutable::parse((string) ($action === 'accept' ? $a->proposed_at : $a->starts_at))->setTimezone($this->tz());
            Notification::route('mail', (string) $a->vendor_email)->notify(new VendorMail($deal->street, (string) $a->title, $starts, $by->name, (string) $by->email, null, $action === 'cancel'));
        }
        app(AuditLogger::class)->record('transactions.appointment.'.$action, $deal, metadata: ['title' => $a->title], label: $deal->street);
    }

    /**
     * Reminds vendors the day before, and flags appointments that passed without being done (hourly).
     *
     * @return array{vendor_reminders: int, missed: int}
     */
    public function run(): array
    {
        $db = DB::connection(Connections::cell());
        $counts = ['vendor_reminders' => 0, 'missed' => 0];
        foreach ($db->table('tx_appointments')->where('status', 'confirmed')->whereNull('reminded_at')->whereNotNull('vendor_email')
            ->whereBetween('starts_at', [now(), now()->addHours(26)])->get() as $a) {
            $deal = Deal::query()->whereKey((int) $a->deal_id)->first();
            $agent = $deal !== null ? User::query()->whereKey($deal->agent_user_id)->first() : null;
            if ($deal === null || $agent === null) {
                continue;
            }
            Notification::route('mail', (string) $a->vendor_email)->notify(new VendorMail($deal->street, (string) $a->title, CarbonImmutable::parse((string) $a->starts_at)->setTimezone($this->tz()),
                $agent->name, (string) $agent->email, null, false, true));
            $db->table('tx_appointments')->where('id', $a->id)->update(['reminded_at' => now()]);
            $counts['vendor_reminders']++;
        }
        foreach ($db->table('tx_appointments')->whereIn('status', ['requested', 'confirmed', 'reschedule'])->where('starts_at', '<', now()->subHours(12))->get() as $a) {
            $db->table('tx_appointments')->where('id', $a->id)->update(['status' => 'missed', 'updated_at' => now()]);
            $deal = Deal::query()->whereKey((int) $a->deal_id)->first();
            if ($deal === null) {
                continue;
            }
            $depends = $db->table('tx_dates')->where('deal_id', $deal->id)->whereNull('done_at')->get(['label', 'due_on'])
                ->filter(fn ($d): bool => preg_match(self::DEPENDS[$a->kind] ?? '/^$/', (string) $d->label) === 1)
                ->map(fn ($d): string => $d->label.' ('.CarbonImmutable::parse((string) $d->due_on)->format('D M j').')')->values()->all();
            User::query()->whereKey($deal->agent_user_id)->first()?->notify(new AppointmentMissedMail($deal->street, (string) $a->title, array_values($depends), '/deals/'.$deal->ulid));
            $counts['missed']++;
        }

        return $counts;
    }

    /**
     * @return list<array{ulid: string, kind: string, title: string, vendor: string|null, phone: string|null, starts_at: string, status: string, proposed_at: string|null, note: string|null}>
     */
    public static function forDeal(Deal $deal, bool $clientsOnly = false): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_appointments')->where('deal_id', $deal->id)->whereNot('status', 'cancelled')
            ->when($clientsOnly, fn ($q) => $q->whereIn('kind', self::FOR_CLIENTS)->whereIn('status', ['requested', 'confirmed', 'reschedule']))->orderBy('starts_at')->get()
            ->map(fn ($a): array => ['ulid' => (string) $a->ulid, 'kind' => (string) $a->kind, 'title' => (string) $a->title, 'vendor' => $a->vendor_name, 'phone' => $a->vendor_phone,
                'starts_at' => CarbonImmutable::parse((string) $a->starts_at)->toIso8601String(), 'status' => (string) $a->status,
                'proposed_at' => $a->proposed_at !== null ? CarbonImmutable::parse((string) $a->proposed_at)->toIso8601String() : null, 'note' => $a->vendor_note])->all());
    }

    private function tz(): string
    {
        $tz = $this->tenants->current()->timezone;

        return $tz !== '' ? $tz : 'America/Chicago';
    }
}
