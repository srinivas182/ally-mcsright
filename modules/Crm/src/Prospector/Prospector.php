<?php

declare(strict_types=1);

namespace Modules\Crm\Prospector;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Learning\TrainingGate;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\PropertyData\PropertyData;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use stdClass;

/**
 * Lead-IQ Prospector (LQ2, specs/grow/15): owners worth reaching who are not in the CRM yet, each with the reason.
 *
 * - **Expired and withdrawn listings** from MLSs the brokerage has a back-office licence for (IDX rules don't allow
 *   prospecting with off-market listings), in the areas the broker chose, shared out to the agents the broker chose,
 *   a few a day each.
 * - **For sale by owner**: homes the agent adds (signs, ads), since there is no lawful bulk source.
 *
 * Owners' names and mailing addresses come from property data (paid per lookup in credits). Phone numbers are never
 * bought or scraped: the agent adds one they found themselves, and it may be called only when the brokerage's copy of
 * the Do Not Call Registry says it is clear (DncRegistry). Everyone else gets a card in the mail. Likely sellers and
 * buyers already in the database are scored by the CRM and appear in "Who to call today".
 */
final class Prospector
{
    public const OFF_MARKET = ['Expired', 'Withdrawn', 'Canceled'];

    public const LOOKBACK_DAYS = 30;

    public function __construct(private readonly TenantContext $tenants, private readonly DncRegistry $dnc, private readonly Timeline $timeline) {}

    /** @return array{agent_ids: list<int>, per_agent_daily: int, zips: list<string>} */
    public function settings(): array
    {
        $r = DB::connection(Connections::cell())->table('crm_prospector_settings')->where('tenant_id', $this->tenants->id())->first();

        return ['agent_ids' => array_values(array_map('intval', (array) json_decode((string) ($r->agent_ids ?? '[]'), true))), 'per_agent_daily' => (int) ($r->per_agent_daily ?? 5),
            'zips' => array_values(array_map('strval', (array) json_decode((string) ($r->zips ?? '[]'), true)))];
    }

    /** @param  array{agent_ids?: list<int|string>|null, per_agent_daily?: int|string|null, zips?: string|null}  $in */
    public function saveSettings(array $in, User $by): void
    {
        $agents = User::query()->whereIn('id', array_map('intval', (array) ($in['agent_ids'] ?? [])))->whereIn('kind', [UserKind::Agent, UserKind::Owner])->pluck('id')->all();
        $daily = (int) ($in['per_agent_daily'] ?? 5);
        if ($daily < 1 || $daily > 50) {
            throw ValidationException::withMessages(['per_agent_daily' => 'Between 1 and 50 a day.']);
        }
        preg_match_all('/\b\d{5}\b/', (string) ($in['zips'] ?? ''), $m);
        DB::connection(Connections::cell())->table('crm_prospector_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'agent_ids' => json_encode(array_values($agents)), 'per_agent_daily' => $daily, 'zips' => json_encode(array_values(array_unique($m[0]))), 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('crm.prospector.settings', metadata: ['agents' => count($agents), 'per_agent_daily' => $daily, 'zips' => count(array_unique($m[0]))], severity: 'info');
    }

    /** Whether this brokerage can get expired and withdrawn listings (a back-office MLS licence). */
    public function feedAvailable(): bool
    {
        return app(MlsAccess::class)->licensedSources($this->tenants->id(), 'back_office') !== [];
    }

    /**
     * Daily: new expired and withdrawn listings in the chosen areas become prospects, shared out to the chosen agents,
     * no more than their daily number each.
     */
    public function find(): int
    {
        $s = $this->settings();
        if ($s['agent_ids'] === [] || ! $this->feedAvailable()) {
            return 0;
        }
        $db = DB::connection(Connections::cell());
        $tenant = $this->tenants->id();
        $today = CarbonImmutable::today();
        $load = [];
        foreach ($s['agent_ids'] as $id) {
            $load[$id] = $db->table('crm_prospects')->where('tenant_id', $tenant)->where('assigned_to', $id)->where('found_on', $today->toDateString())->count();
        }
        $known = $db->table('crm_prospects')->where('tenant_id', $tenant)->whereNotNull('listing_id')->pluck('listing_id')->all();
        $listings = Listing::query()->visibleTo($tenant, 'back_office')->whereIn('standard_status', self::OFF_MARKET)
            ->where('modification_timestamp', '>=', CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS))
            ->when($s['zips'] !== [], fn ($q) => $q->whereIn('postal_code', $s['zips']))
            ->whereNotIn('id', $known === [] ? [0] : $known)->orderByDesc('modification_timestamp')->limit(500)->get();
        $made = 0;
        foreach ($listings as $l) {
            asort($load);
            $agent = (int) array_key_first($load);
            if ($load[$agent] >= $s['per_agent_daily']) {
                break;
            }
            $street = trim(implode(' ', array_filter([$l->street_number, $l->street_name, $l->unit_number !== null ? '#'.$l->unit_number : null])));
            if ($street === '') {
                continue;
            }
            $db->table('crm_prospects')->insertOrIgnore(['ulid' => (string) Str::ulid(), 'tenant_id' => $tenant, 'kind' => strtolower((string) $l->standard_status) === 'expired' ? 'expired' : 'withdrawn',
                'listing_id' => $l->id, 'street' => $street, 'city' => $l->city, 'state' => $l->state_or_province, 'zip' => $l->postal_code, 'reason' => self::reasonFor($l),
                'assigned_to' => $agent, 'status' => 'new', 'found_on' => $today->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            $load[$agent]++;
            $made++;
        }

        return $made;
    }

    /** Why this listing is worth a call or a card, in one line. */
    public static function reasonFor(Listing $l): string
    {
        $when = $l->modification_timestamp !== null ? CarbonImmutable::parse($l->modification_timestamp) : null;
        $ago = $when !== null ? (int) $when->startOfDay()->diffInDays(CarbonImmutable::today()) : null;
        $what = strtolower((string) $l->standard_status) === 'expired' ? 'Expired' : (strtolower((string) $l->standard_status) === 'withdrawn' ? 'Withdrawn' : 'Cancelled');
        $price = $l->list_price_cents !== null ? '$'.number_format(intdiv((int) $l->list_price_cents, 100)) : null;
        $cut = $l->original_list_price_cents !== null && $l->list_price_cents !== null && (int) $l->original_list_price_cents > (int) $l->list_price_cents
            ? ' (cut from $'.number_format(intdiv((int) $l->original_list_price_cents, 100)).')' : '';

        return $what.($ago !== null ? ($ago === 0 ? ' today' : " {$ago} day".($ago === 1 ? '' : 's').' ago') : '')
            .($l->days_on_market !== null ? " after {$l->days_on_market} days on the market" : '').($price !== null ? " at {$price}{$cut}" : '').'.';
    }

    /** An agent adds a for-sale-by-owner home they found. */
    public function addFsbo(User $agent, string $street, ?string $city, ?string $state, ?string $zip, ?string $note): string
    {
        $street = mb_substr(trim($street), 0, 160);
        if ($street === '') {
            throw ValidationException::withMessages(['street' => 'Enter the street address.']);
        }
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('crm_prospects')->insert(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'kind' => 'fsbo', 'street' => $street,
            'city' => $city !== null ? mb_substr(trim($city), 0, 80) : null, 'state' => $state !== null && $state !== '' ? strtoupper(substr($state, 0, 2)) : null, 'zip' => $zip,
            'reason' => mb_substr('For sale by owner'.($note !== null && trim($note) !== '' ? ': '.trim($note) : '.'), 0, 300),
            'assigned_to' => $agent->id, 'status' => 'new', 'found_on' => CarbonImmutable::today()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);

        return $ulid;
    }

    /**
     * The prospects a person sees: their own, or everyone's for the broker.
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        $broker = $user->isKind(UserKind::Owner) || $user->can('crm.leads.manage');
        $names = User::query()->pluck('first_name', 'id');

        return array_values(DB::connection(Connections::cell())->table('crm_prospects')->where('tenant_id', $this->tenants->id())->whereIn('status', ['new', 'working'])
            ->when(! $broker, fn ($q) => $q->where('assigned_to', $user->id))->orderByDesc('found_on')->orderByDesc('id')->limit(200)->get()
            ->map(fn (stdClass $p): array => ['ulid' => (string) $p->ulid, 'kind' => (string) $p->kind, 'address' => trim($p->street.', '.($p->city ?? '').' '.($p->zip ?? ''), ', '),
                'reason' => (string) $p->reason, 'owner' => $p->owner_name, 'mailing' => $p->mailing !== null ? json_decode((string) $p->mailing, true) : null,
                'phone' => $p->phone, 'dnc' => $p->phone !== null ? $this->dnc->status((string) $p->phone) : null,
                'dnc_words' => $p->phone !== null ? DncRegistry::words($this->dnc->status((string) $p->phone)) : null,
                'agent' => $p->assigned_to !== null ? ($names[$p->assigned_to] ?? null) : null, 'mine' => (int) $p->assigned_to === $user->id, 'found' => (string) $p->found_on])->all());
    }

    /** The agent adds a phone number they found (never bought). Returns the Do Not Call status. */
    public function setPhone(string $ulid, string $phone, User $by): string
    {
        $p = $this->mine($ulid, $by);
        $digits = substr((string) preg_replace('/\D/', '', $phone), -10);
        if (strlen($digits) !== 10) {
            throw ValidationException::withMessages(['phone' => 'Enter a 10-digit US phone number.']);
        }
        $this->touch($p, ['phone' => '+1'.$digits, 'status' => 'working']);

        return $this->dnc->status('+1'.$digits);
    }

    /** Owner name and mailing address from property data (credits per lookup; free again for 30 days). */
    public function findOwner(string $ulid, User $by): void
    {
        $p = $this->mine($ulid, $by);
        $data = app(PropertyData::class)->lookup($by, 'batchdata', ['street' => (string) $p->street, 'city' => (string) ($p->city ?? ''), 'state' => (string) ($p->state ?? ''), 'zip' => (string) ($p->zip ?? '')])['data'];
        $owners = array_values(array_filter((array) ($data['owners'] ?? []), 'is_string'));
        $this->touch($p, ['owner_name' => $owners !== [] ? mb_substr(implode(' & ', $owners), 0, 160) : null,
            'mailing' => ($data['mailing'] ?? []) !== [] ? json_encode($data['mailing']) : null, 'status' => 'working']);
    }

    /**
     * Moves a prospect into the CRM as the agent's lead. With a phone that is clear to call, the ready-made
     * "Expired or for-sale-by-owner" plan starts; otherwise they are tagged for cards only.
     */
    public function toContact(string $ulid, User $by): Contact
    {
        $training = app(TrainingGate::class)->missing($by, 'prospector'); // LN2
        if ($training !== null) {
            throw ValidationException::withMessages(['prospect' => $training]);
        }
        $p = $this->mine($ulid, $by);
        if ($p->contact_id !== null && ($existing = Contact::query()->find((int) $p->contact_id)) !== null) {
            return $existing;
        }
        $names = preg_split('/\s+/', trim((string) ($p->owner_name ?? ''))) ?: [];
        $mailing = $p->mailing !== null ? (array) json_decode((string) $p->mailing, true) : [];
        $clear = $p->phone !== null && $this->dnc->status((string) $p->phone) === DncRegistry::CLEAR;
        $contact = Contact::query()->create([
            'first_name' => ($names[0] ?? '') !== '' ? $names[0] : 'Owner', 'last_name' => count($names) > 1 ? implode(' ', array_slice($names, 1)) : (string) $p->street,
            'emails' => [], 'phones' => $p->phone !== null ? [['e164' => $p->phone, 'primary' => true]] : [],
            'address_line1' => $mailing['street'] ?? $p->street, 'city' => $mailing['city'] ?? $p->city, 'state' => $mailing['state'] ?? $p->state, 'zip' => $mailing['zip'] ?? $p->zip,
            'origin' => $p->kind === 'fsbo' ? 'personal' : 'company', 'source' => 'other', 'lifecycle' => 'lead', 'owner_user_id' => $by->id,
        ]);
        app(Tags::class)->attach($contact, 'Prospect');
        if (! $clear) {
            app(Tags::class)->attach($contact, 'Mail only');
        }
        $this->touch($p, ['contact_id' => $contact->id, 'status' => 'contact']);
        $this->timeline->system($contact, 'Added from the Prospector: '.$p->reason.($clear ? '' : ' Mail only: '.($p->phone !== null ? DncRegistry::words($this->dnc->status((string) $p->phone)) : 'no phone number.')));
        if ($clear && ($plan = FollowUpPlan::query()->where('template_key', 'expired_fsbo')->where('active', true)->first()) !== null) {
            app(FollowUpPlans::class)->enroll($contact, $plan, $by);
        }
        app(AuditLogger::class)->record('crm.prospector.added', metadata: ['kind' => $p->kind, 'callable' => $clear], severity: 'info');

        return $contact;
    }

    public function dismiss(string $ulid, User $by): void
    {
        $this->touch($this->mine($ulid, $by), ['status' => 'dismissed']);
    }

    /** New prospects waiting for an agent today (for the daily brief). */
    public function waiting(User $agent): int
    {
        return DB::connection(Connections::cell())->table('crm_prospects')->where('tenant_id', $this->tenants->id())->where('assigned_to', $agent->id)->where('status', 'new')->count();
    }

    /**
     * Whether a CRM contact came from the Prospector and has never answered (no prior relationship), so calls need the
     * Do Not Call Registry to be clear.
     */
    public function isCold(Contact $contact): bool
    {
        $db = DB::connection(Connections::cell());

        return $db->table('crm_prospects')->where('tenant_id', $this->tenants->id())->where('contact_id', $contact->id)->exists()
            && ! $db->table('crm_activities')->where('contact_id', $contact->id)->where(fn ($q) => $q->where('direction', 'in')->orWhere('outcome', 'connected'))->exists();
    }

    private function mine(string $ulid, User $by): stdClass
    {
        $p = DB::connection(Connections::cell())->table('crm_prospects')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first() ?? abort(404);
        abort_unless((int) $p->assigned_to === $by->id || $by->isKind(UserKind::Owner) || $by->can('crm.leads.manage'), 403);

        return $p;
    }

    /** @param  array<string, mixed>  $values */
    private function touch(stdClass $p, array $values): void
    {
        DB::connection(Connections::cell())->table('crm_prospects')->where('id', $p->id)->update($values + ['updated_at' => now()]);
    }
}
