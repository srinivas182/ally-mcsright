<?php

declare(strict_types=1);

namespace Modules\Transactions\Mls;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Agents\AgentCredentials;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Transactions\DealAccess;
use Modules\Transactions\Deals;
use Modules\Transactions\Models\Deal;

/**
 * ML2: each agent's own MLS listings in My listings.
 *
 * Agents' MLS IDs (ML1) pick out their listings in the brokerage's MLS feeds. New ones are offered on My listings
 * ("Found in the MLS — add it"), or added by themselves when the owner switches that on. Listings already on the board
 * keep their price in step while on the market; when the MLS says Pending or Sold, the deal asks the agent to mark it
 * (the money details still have to come from them). The owner also sees sales in the MLS by their agents that have
 * no deal in RightAlly.
 */
final class MlsListings
{
    /** MLS statuses that mean "on the market" (offered to add). */
    public const LIVE = ['Active', 'Coming Soon', 'Active Under Contract', 'Pending'];

    /** How far back the "sales not in RightAlly" report looks. */
    public const REPORT_DAYS = 365;

    /** @var array<int, list<string>> the brokerage's own office MLS IDs on each board */
    private array $offices = [];

    /** @var array<int, User|null> */
    private array $people = [];

    public function __construct(private readonly TenantContext $tenants, private readonly AgentCredentials $credentials, private readonly Deals $deals) {}

    /**
     * For My listings: listings found in the MLS to add, and deals whose MLS status moved on.
     *
     * @return array{found: list<array{ulid: string, street: string, city: string|null, price_cents: int, mls_number: string, status: string, agent: string, mls: string}>, prompts: list<array{deal: string, street: string, say: string, action: string}>, connected: bool, auto: bool}
     */
    public function board(?User $only): array
    {
        $sources = $this->sources();
        $owners = $this->owners($only);
        if ($sources === [] || $owners === []) {
            return ['found' => [], 'prompts' => [], 'connected' => $sources !== [], 'auto' => $this->autoAdd()];
        }
        $names = User::query()->whereIn('id', array_unique(array_values($owners)))->get()->keyBy('id');
        $found = array_values($this->found($sources, $owners)->map(fn (\stdClass $l): array => ['ulid' => (string) $l->ulid, 'street' => self::street($l),
            'city' => $l->city, 'price_cents' => (int) ($l->list_price_cents ?? 0), 'mls_number' => (string) $l->listing_id, 'status' => (string) $l->standard_status,
            'agent' => (string) ($names->get($this->ownerOf($l, $owners))->name ?? 'Agent'), 'mls' => (string) ($sources[(int) $l->mls_source_id] ?? 'MLS')])->all());

        return ['found' => $found, 'prompts' => $this->prompts($sources, $only), 'connected' => true, 'auto' => $this->autoAdd()];
    }

    /** Add one listing found in the MLS to My listings, for the agent whose MLS ID is on it. */
    public function add(string $listingUlid, User $by): Deal
    {
        $sources = $this->sources();
        $owners = $this->owners(DealAccess::reviewer($by) ? null : $by);
        $listing = $this->found($sources, $owners)->firstWhere('ulid', $listingUlid);
        if ($listing === null) {
            throw ValidationException::withMessages(['listing' => 'This listing is already on the board, or it is not one of yours.']);
        }

        return $this->create($listing, $owners, $by);
    }

    /** "Not mine to add": not offered again. */
    public function dismiss(string $listingUlid, User $by): void
    {
        $listing = $this->found($this->sources(), $this->owners(DealAccess::reviewer($by) ? null : $by))->firstWhere('ulid', $listingUlid);
        if ($listing === null) {
            throw ValidationException::withMessages(['listing' => 'This listing is not on your list.']);
        }
        DB::connection(Connections::cell())->table('tx_mls_dismissed')->insertOrIgnore(['tenant_id' => $this->tenants->id(), 'mls_source_id' => $listing->mls_source_id,
            'listing_key' => $listing->listing_key, 'dismissed_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Nightly: keep prices of listings on the market in step, and add new ones when the owner chose automatic.
     *
     * @return array{added: int, repriced: int}
     */
    public function sync(User $system): array
    {
        $sources = $this->sources();
        $owners = $this->owners(null);
        if ($sources === [] || $owners === []) {
            return ['added' => 0, 'repriced' => 0];
        }
        $added = 0;
        if ($this->autoAdd()) {
            foreach ($this->found($sources, $owners) as $listing) {
                $this->create($listing, $owners, $system);
                $added++;
            }
        }
        $repriced = 0;
        foreach ($this->linked($sources, ['pre_contract']) as [$deal, $listing]) {
            $price = (int) ($listing->list_price_cents ?? 0);
            if ($price > 0 && $price !== (int) $deal->price_cents) {
                $from = (int) $deal->price_cents;
                $deal->forceFill(['price_cents' => $price])->save();
                app(AuditLogger::class)->record('transactions.deal.price_from_mls', $deal, metadata: ['from_cents' => $from, 'to_cents' => $price], label: $deal->street);
                $repriced++;
            }
        }

        return ['added' => $added, 'repriced' => $repriced];
    }

    /**
     * For the owner: homes your agents sold (listing or buyer side) in the last year that have no deal in RightAlly.
     *
     * @return list<array{street: string, city: string|null, mls_number: string, close_price_cents: int, close_date: string|null, agent: string, side: string, mls: string}>
     */
    public function missingSales(): array
    {
        $sources = $this->sources();
        $owners = $this->owners(null);
        if ($sources === [] || $owners === []) {
            return [];
        }
        $ids = array_map('strval', array_keys($owners));
        $sales = DB::connection(Connections::listings())->table('listings')
            ->where(fn ($w) => $w->where(fn ($l) => $this->ownOffice($l, 'list_office_mls_id'))->orWhere(fn ($b) => $this->ownOffice($b, 'buyer_office_mls_id')))
            ->where('standard_status', 'Closed')
            ->where(fn ($w) => $w->whereNull('close_date')->orWhere('close_date', '>=', CarbonImmutable::today()->subDays(self::REPORT_DAYS)->toDateString()))
            ->where(fn ($w) => $w->whereIn('list_agent_mls_id', $ids)->orWhereIn('co_list_agent_mls_id', $ids)->orWhereIn('buyer_agent_mls_id', $ids))
            ->orderByDesc('close_date')->limit(500)->get();
        $deals = Deal::query()->get(['mls_number', 'street', 'city']);
        $numbers = [];
        $streets = [];
        foreach ($deals as $d) {
            if ((string) $d->mls_number !== '') {
                $numbers[(string) $d->mls_number] = true;
            }
            $streets[self::key((string) $d->street, $d->city)] = true;
        }
        $names = User::query()->whereIn('id', array_unique(array_values($owners)))->get()->keyBy('id');
        $rows = [];
        foreach ($sales as $l) {
            if (isset($numbers[(string) $l->listing_id]) || isset($streets[self::key(self::street($l), $l->city)])) {
                continue;
            }
            $listOffice = in_array((string) $l->list_office_mls_id, $this->offices[(int) $l->mls_source_id] ?? [], true);
            $buyOffice = in_array((string) ($l->buyer_office_mls_id ?? ''), $this->offices[(int) $l->mls_source_id] ?? [], true);
            foreach ([['list_agent_mls_id', 'Listing side', $listOffice], ['co_list_agent_mls_id', 'Listing side', $listOffice], ['buyer_agent_mls_id', 'Buyer side', $buyOffice]] as [$col, $side, $ours]) {
                $id = (string) ($l->{$col} ?? '');
                if ($ours && $id !== '' && isset($owners[$id])) {
                    $rows[] = ['street' => self::street($l), 'city' => $l->city, 'mls_number' => (string) $l->listing_id, 'close_price_cents' => (int) ($l->close_price_cents ?? 0),
                        'close_date' => $l->close_date !== null ? (string) $l->close_date : null, 'agent' => (string) ($names->get($owners[$id])->name ?? 'Agent'), 'side' => $side,
                        'mls' => (string) ($sources[(int) $l->mls_source_id] ?? 'MLS')];
                }
            }
        }

        return $rows;
    }

    public function autoAdd(): bool
    {
        return (bool) DB::connection(Connections::cell())->table('tx_settings')->value('mls_auto_add');
    }

    public function setAutoAdd(bool $on, User $by): void
    {
        abort_unless($by->isKind(UserKind::Owner), 403);
        DB::connection(Connections::cell())->table('tx_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['mls_auto_add' => $on, 'updated_at' => now()]);
        app(AuditLogger::class)->record('transactions.mls_auto_add', metadata: ['on' => $on], severity: 'notice');
    }

    /**
     * @param  array<int, string>  $sources
     * @param  array<string, int>  $owners
     * @return Collection<int, \stdClass>
     */
    private function found(array $sources, array $owners): Collection
    {
        if ($sources === [] || $owners === []) {
            return collect();
        }
        $ids = array_map('strval', array_keys($owners));
        // Only listings from the brokerage's own office: an MLS ID never pulls in another brokerage's listings.
        $listings = DB::connection(Connections::listings())->table('listings')->where(fn ($w) => $this->ownOffice($w, 'list_office_mls_id'))->whereIn('standard_status', self::LIVE)
            ->whereNotNull('listing_id')->where(fn ($w) => $w->whereIn('list_agent_mls_id', $ids)->orWhereIn('co_list_agent_mls_id', $ids))
            ->orderByDesc('id')->limit(300)->get()
            // And the name the MLS shows must be the agent's: a wrong ID never brings in a colleague's listings.
            ->filter(fn (\stdClass $l): bool => $this->nameFits($l, $owners))->values();
        $taken = Deal::query()->whereNotNull('mls_number')->pluck('mls_number')->map(fn ($n): string => (string) $n)->flip();
        $dismissed = DB::connection(Connections::cell())->table('tx_mls_dismissed')->get(['mls_source_id', 'listing_key'])
            ->map(fn (\stdClass $d): string => $d->mls_source_id.':'.$d->listing_key)->flip();

        return $listings->reject(fn (\stdClass $l): bool => $taken->has((string) $l->listing_id) || $dismissed->has($l->mls_source_id.':'.$l->listing_key))->values();
    }

    /**
     * @param  array<int, string>  $sources
     * @return list<array{deal: string, street: string, say: string, action: string}>
     */
    private function prompts(array $sources, ?User $only): array
    {
        $prompts = [];
        foreach ($this->linked($sources, ['pre_contract', 'under_contract'], $only) as [$deal, $listing]) {
            $status = (string) $listing->standard_status;
            if ($deal->status === 'pre_contract' && in_array($status, ['Pending', 'Active Under Contract'], true)) {
                $prompts[] = ['deal' => $deal->ulid, 'street' => (string) $deal->street, 'say' => "The MLS says this home is {$status}. Mark it under contract?", 'action' => 'under_contract'];
            } elseif ($status === 'Closed') {
                $prompts[] = ['deal' => $deal->ulid, 'street' => (string) $deal->street, 'say' => 'The MLS says this home sold. Mark it closed?', 'action' => 'closed'];
            }
        }

        return $prompts;
    }

    /**
     * Deals on the board with an MLS number we can find in the feed.
     *
     * @param  array<int, string>  $sources
     * @param  list<string>  $statuses
     * @return list<array{0: Deal, 1: \stdClass}>
     */
    private function linked(array $sources, array $statuses, ?User $only = null): array
    {
        $deals = Deal::query()->whereIn('type', ['listing', 'dual'])->whereIn('status', $statuses)->whereNotNull('mls_number')
            ->when($only !== null, fn ($q) => $q->where('agent_user_id', $only?->id))->limit(1000)->get();
        if ($deals->isEmpty()) {
            return [];
        }
        $listings = DB::connection(Connections::listings())->table('listings')->where(fn ($w) => $this->ownOffice($w, 'list_office_mls_id'))
            ->whereIn('listing_id', $deals->pluck('mls_number')->all())->get()->keyBy('listing_id');
        $pairs = [];
        foreach ($deals as $deal) {
            $listing = $listings->get((string) $deal->mls_number);
            if ($listing !== null) {
                $pairs[] = [$deal, $listing];
            }
        }

        return $pairs;
    }

    /** @param array<string, int> $owners */
    private function create(\stdClass $listing, array $owners, User $by): Deal
    {
        $agent = User::query()->findOrFail($this->ownerOf($listing, $owners));
        $deal = $this->deals->create(['type' => 'listing', 'street' => self::street($listing), 'city' => $listing->city, 'state_code' => (string) ($listing->state_or_province ?? ''),
            'postal_code' => $listing->postal_code, 'mls_number' => (string) $listing->listing_id, 'price_cents' => (int) ($listing->list_price_cents ?? 0)], $agent, $by);
        app(AuditLogger::class)->record('transactions.deal.from_mls', $deal, metadata: ['mls_number' => $listing->listing_id], label: $deal->street);

        return $deal;
    }

    /** @param array<string, int> $owners */
    private function ownerOf(\stdClass $listing, array $owners): int
    {
        return $owners[(string) ($listing->list_agent_mls_id ?? '')] ?? $owners[(string) ($listing->co_list_agent_mls_id ?? '')] ?? 0;
    }

    /**
     * MLS agent IDs => user, for one agent or the whole brokerage.
     *
     * @return array<string, int>
     */
    private function owners(?User $only): array
    {
        $owners = $this->credentials->mlsIdOwners();

        return $only === null ? $owners : array_filter($owners, fn (int $user): bool => $user === $only->id);
    }

    /**
     * The brokerage's active MLS boards: id => name.
     *
     * @return array<int, string>
     */
    private function sources(): array
    {
        $this->offices = [];
        if (! class_exists(MlsAuthorization::class)) {
            return [];
        }
        $auths = MlsAuthorization::query()->where('tenant_id', $this->tenants->id())->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', CarbonImmutable::today()->toDateString()))->get();
        foreach ($auths as $a) {
            $this->offices[(int) $a->mls_source_id] = array_values(array_unique(array_filter([...($this->offices[(int) $a->mls_source_id] ?? []),
                ...(array) ($a->office_mls_ids ?? []), $a->broker_mls_id])));
        }
        $ids = array_keys($this->offices);
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (DB::connection(Connections::listings())->table('mls_sources')->whereIn('id', $ids)->get(['id', 'name']) as $s) {
            $names[(int) $s->id] = (string) $s->name;
        }

        return $names;
    }

    /** Rows from one of the brokerage's offices on that board. */
    private function ownOffice(Builder $q, string $column): void
    {
        foreach ($this->offices as $source => $offices) {
            $q->orWhere(fn ($w) => $w->where('mls_source_id', $source)->whereIn($column, $offices === [] ? [''] : $offices));
        }
        if ($this->offices === []) {
            $q->whereRaw('1 = 0');
        }
    }

    /**
     * The listing agent's name in the MLS is the agent's whose ID it is (no name in the MLS: the office check decides).
     *
     * @param  array<string, int>  $owners
     */
    private function nameFits(\stdClass $l, array $owners): bool
    {
        $name = trim((string) ($l->list_agent_name ?? ''));
        $owner = $owners[(string) ($l->list_agent_mls_id ?? '')] ?? null;
        if ($name === '' || $owner === null) {
            return true;
        }
        $user = $this->people[$owner] ??= User::query()->find($owner);
        if ($user === null) {
            return false;
        }
        $words = preg_split('/\s+/', mb_strtolower(trim((string) preg_replace('/[^\p{L}\s]/u', ' ', str_contains($name, ',') ? implode(' ', array_reverse(array_map('trim', explode(',', $name, 2)))) : $name)))) ?: [];
        $words = array_values(array_filter($words, fn (string $w): bool => mb_strlen($w) > 1 && ! in_array($w, ['jr', 'sr', 'ii', 'iii', 'iv'], true)));

        return count($words) >= 2 && $words[0] === mb_strtolower((string) preg_replace('/[^\p{L}]/u', '', (string) $user->first_name))
            && $words[count($words) - 1] === mb_strtolower((string) preg_replace('/[^\p{L}]/u', '', (string) $user->last_name));
    }

    private static function street(\stdClass $l): string
    {
        return trim((string) preg_replace('/\s+/', ' ', trim(($l->street_number ?? '').' '.($l->street_name ?? '').(($l->unit_number ?? '') !== '' ? ' #'.$l->unit_number : ''))));
    }

    private static function key(string $street, ?string $city): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $street.'|'.($city ?? '')));
    }
}
