<?php

declare(strict_types=1);

namespace Modules\Roster;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;

/**
 * ML1: agents' MLS IDs without anyone typing them.
 *
 * Every listing in the MLS feed carries the listing office's MLS ID, the listing agent's MLS ID and their name. For
 * listings from the brokerage's own office(s) we match the name to the people in the brokerage and suggest the ID to
 * that agent, who confirms it with one click ("We found 3 listings under 4810237 — is this you?"). Nothing is saved
 * until the agent confirms, or the owner assigns it. Agents with no listings yet type their ID once; if the name on
 * that ID's listings is someone else's, the owner checks it instead.
 */
final class MlsIds
{
    public function __construct(private readonly TenantContext $tenants, private readonly Roster $roster) {}

    /**
     * Look through this brokerage's MLS feeds for its agents' IDs. Returns how many new suggestions were made.
     */
    public function scan(): int
    {
        $new = 0;
        $people = $this->people();
        foreach ($this->authorisations() as $auth) {
            $offices = array_values(array_unique(array_filter([...(array) ($auth->office_mls_ids ?? []), $auth->broker_mls_id])));
            if ($offices === []) {
                continue;
            }
            $rows = DB::connection(Connections::listings())->table('listings')->where('mls_source_id', $auth->mls_source_id)
                ->whereIn('list_office_mls_id', $offices)->whereNotNull('list_agent_mls_id')
                ->selectRaw('list_agent_mls_id as id, max(list_agent_name) as name, count(*) as n')->groupBy('list_agent_mls_id')->get();
            foreach ($rows as $row) {
                $id = trim((string) $row->id);
                if ($id === '') {
                    continue;
                }
                $this->recheckTyped($auth->mls_source_id, $id, (string) $row->name, $people);
                if ($this->known($auth->mls_source_id, $id)) {
                    continue;
                }
                $who = self::match((string) $row->name, $people);
                $this->insert($auth->mls_source_id, $id, (string) $row->name, (int) $row->n, $who, $who === null ? 'review' : 'suggested', 'feed');
                $new++;
            }
        }

        return $new;
    }

    /**
     * What the agent sees on their dashboard: a suggestion to confirm, or the boards to type an ID for.
     *
     * @return array{suggestions: list<array{ulid: string, mls: string, id: string, listings: int, sample: list<string>}>, boards: list<array{id: int, name: string}>, has_id: bool, current: list<array{mls: string, id: string, checked: bool}>}|null
     */
    public function forAgent(User $agent): ?array
    {
        $boards = $this->boards();
        if ($boards === []) {
            return null;
        }
        $names = array_column($boards, 'name', 'id');
        $suggestions = array_values(DB::connection(Connections::cell())->table('roster_mls_matches')->where('user_id', $agent->id)->where('status', 'suggested')->orderBy('id')->get()
            ->map(fn (object $m): array => ['ulid' => (string) $m->ulid, 'mls' => (string) ($names[(int) $m->mls_source_id] ?? 'your MLS'), 'id' => (string) $m->agent_mls_id,
                'listings' => (int) $m->listings, 'sample' => array_values(array_map('strval', (array) json_decode((string) ($m->sample ?? '[]'), true)))])->values()->all());

        $current = array_values(DB::connection(Connections::cell())->table('roster_credentials')->where('user_id', $agent->id)->where('kind', 'mls')->where('status', 'current')
            ->whereNotNull('number')->orderBy('id')->get(['issuer', 'number', 'verified_at'])
            ->map(fn (object $c): array => ['mls' => (string) $c->issuer, 'id' => (string) $c->number, 'checked' => $c->verified_at !== null])->values()->all());

        return ['suggestions' => $suggestions, 'boards' => $boards, 'has_id' => $current !== [], 'current' => $current];
    }

    /** The agent says yes (or no) to a suggested ID. */
    public function decide(User $agent, string $ulid, bool $mine): void
    {
        $db = DB::connection(Connections::cell());
        $match = $db->table('roster_mls_matches')->where('ulid', $ulid)->where('user_id', $agent->id)->where('status', 'suggested')->first();
        if ($match === null) {
            throw ValidationException::withMessages(['match' => 'This suggestion has already been answered.']);
        }
        if (! $mine) {
            // Not theirs: the owner decides who it belongs to.
            $db->table('roster_mls_matches')->where('id', $match->id)->update(['status' => 'review', 'user_id' => null, 'decided_by' => $agent->id, 'decided_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->record('roster.mls_id.declined', $agent, metadata: ['mls_id' => $match->agent_mls_id], label: $agent->name);

            return;
        }
        $this->confirm((int) $match->id, (int) $match->mls_source_id, (string) $match->agent_mls_id, $agent, $agent);
    }

    /**
     * The agent types their ID. If listings under it carry their name (or there are none yet), it is offered back to
     * them to confirm; if the listings carry someone else's name, the owner checks it.
     *
     * @return string 'confirm' (shown to the agent to confirm) or 'review' (sent to the owner)
     */
    public function typed(User $agent, int $sourceId, string $agentMlsId): string
    {
        $id = trim($agentMlsId);
        if (preg_match('/^[A-Za-z0-9._-]{2,30}$/', $id) !== 1) {
            throw ValidationException::withMessages(['mls_id' => 'Type your MLS ID as it appears in the MLS (letters and numbers).']);
        }
        if (! in_array($sourceId, array_column($this->boards(), 'id'), true)) {
            throw ValidationException::withMessages(['board' => 'Choose one of your brokerage\'s MLS boards.']);
        }
        $db = DB::connection(Connections::cell());
        $existing = $db->table('roster_mls_matches')->where('mls_source_id', $sourceId)->where('agent_mls_id', $id)->first();
        if ($existing !== null && $existing->status === 'confirmed') {
            throw ValidationException::withMessages(['mls_id' => (int) $existing->user_id === $agent->id ? 'This MLS ID is already saved for you.'
                : 'This MLS ID already belongs to someone in your brokerage. Ask your broker if it is wrong.']);
        }
        $found = DB::connection(Connections::listings())->table('listings')->where('mls_source_id', $sourceId)
            ->where(fn ($w) => $w->where('list_agent_mls_id', $id)->orWhere('co_list_agent_mls_id', $id))
            ->selectRaw('count(*) as n, max(case when list_agent_mls_id = ? then list_agent_name end) as name', [$id])->first();
        $count = (int) ($found->n ?? 0);
        $name = trim((string) ($found->name ?? ''));
        $theirs = $name === '' || self::sameName($name, $agent);
        $status = $theirs ? 'suggested' : 'review';
        $db->table('roster_mls_matches')->where('mls_source_id', $sourceId)->where('agent_mls_id', $id)->where('status', '!=', 'confirmed')->delete();
        // Anything still waiting for this agent on this board is replaced by what they just typed.
        $db->table('roster_mls_matches')->where('user_id', $agent->id)->where('mls_source_id', $sourceId)->where('status', 'suggested')->delete();
        $this->insert($sourceId, $id, $name !== '' ? $name : $agent->name, $count, $theirs ? $agent->id : null, $status, 'typed', $theirs ? null : $agent->id);

        return $theirs ? 'confirm' : 'review';
    }

    /**
     * For the owner: who has an ID, who is waiting to confirm, what needs checking, and who has none yet.
     *
     * @return array{review: list<array{ulid: string, mls: string, id: string, name: string, listings: int, sample: list<string>, typed_by: string|null}>, waiting: list<array{name: string, id: string}>, missing: list<array{ulid: string, name: string}>, matched: int, people: list<array{ulid: string, name: string}>}
     */
    public function overview(): array
    {
        $names = array_column($this->boards(), 'name', 'id');
        $db = DB::connection(Connections::cell());
        $people = $this->people();
        $byId = collect($people)->keyBy('id');
        $rows = $db->table('roster_mls_matches')->whereIn('status', ['review', 'suggested'])->orderBy('agent_name')->get();
        $review = array_values($rows->where('status', 'review')->map(fn (object $m): array => ['ulid' => (string) $m->ulid, 'mls' => (string) ($names[(int) $m->mls_source_id] ?? 'MLS'),
            'id' => (string) $m->agent_mls_id, 'name' => (string) $m->agent_name, 'listings' => (int) $m->listings,
            'sample' => array_values(array_map('strval', (array) json_decode((string) ($m->sample ?? '[]'), true))),
            'typed_by' => $m->matched_by === 'typed' && $m->decided_by !== null ? ($byId->get((int) $m->decided_by)['name'] ?? null) : null])->values()->all());
        $waiting = array_values($rows->where('status', 'suggested')->map(fn (object $m): array => ['name' => (string) ($byId->get((int) $m->user_id)['name'] ?? $m->agent_name),
            'id' => (string) $m->agent_mls_id])->values()->all());
        $withId = $db->table('roster_credentials')->where('kind', 'mls')->where('status', 'current')->whereNotNull('number')->pluck('user_id')->map(fn ($v): int => (int) $v)->all();
        $pending = $rows->pluck('user_id')->filter()->map(fn ($v): int => (int) $v)->all();
        $missing = array_values(collect($people)->filter(fn (array $p): bool => ! in_array($p['id'], $withId, true) && ! in_array($p['id'], $pending, true))
            ->map(fn (array $p): array => ['ulid' => $p['ulid'], 'name' => $p['name']])->values()->all());

        return ['review' => $review, 'waiting' => $waiting, 'missing' => $missing, 'matched' => count(array_unique($withId)),
            'people' => array_map(fn (array $p): array => ['ulid' => $p['ulid'], 'name' => $p['name']], $people)];
    }

    /** The owner says who an ID belongs to (saved straight away), or that it is no one here. */
    public function assign(string $ulid, ?User $agent, User $by): void
    {
        if (! Roster::manages($by)) {
            throw ValidationException::withMessages(['roster' => 'Only the brokerage owner or roster managers can do this.']);
        }
        $db = DB::connection(Connections::cell());
        $match = $db->table('roster_mls_matches')->where('ulid', $ulid)->whereIn('status', ['review', 'suggested'])->first();
        if ($match === null) {
            throw ValidationException::withMessages(['match' => 'This has already been answered.']);
        }
        if ($agent === null) {
            $db->table('roster_mls_matches')->where('id', $match->id)->update(['status' => 'declined', 'user_id' => null, 'decided_by' => $by->id, 'decided_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->record('roster.mls_id.not_ours', metadata: ['mls_id' => $match->agent_mls_id], label: (string) $match->agent_name);

            return;
        }
        $this->confirm((int) $match->id, (int) $match->mls_source_id, (string) $match->agent_mls_id, $agent, $by);
    }

    /**
     * The brokerage's MLS boards (active, any scope).
     *
     * @return list<array{id: int, name: string}>
     */
    public function boards(): array
    {
        $ids = $this->authorisations()->pluck('mls_source_id')->unique()->all();

        return array_values(MlsSource::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (MlsSource $s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])->values()->all());
    }

    private function confirm(int $matchId, int $sourceId, string $agentMlsId, User $agent, User $by): void
    {
        $name = (string) (MlsSource::query()->whereKey($sourceId)->value('name') ?? 'MLS');
        $db = DB::connection(Connections::cell());
        $row = $db->table('roster_mls_matches')->where('id', $matchId)->first(['listings', 'matched_by']);
        // Typed by the agent with no listings to compare yet: saved, but not marked as checked.
        $matched = $by->id !== $agent->id || (int) ($row->listings ?? 0) > 0 || ($row->matched_by ?? '') === 'feed';
        $db->transaction(function () use ($db, $matchId, $sourceId, $agentMlsId, $agent, $by, $name, $matched): void {
            $this->roster->addMlsIdFromFeed($agent, $name, $agentMlsId, $by, $matched);
            // A new ID on the same board replaces the old one; the old one is free again.
            $db->table('roster_mls_matches')->where('user_id', $agent->id)->where('mls_source_id', $sourceId)->where('status', 'confirmed')->where('id', '!=', $matchId)
                ->update(['status' => 'replaced', 'updated_at' => now()]);
            $db->table('roster_mls_matches')->where('id', $matchId)->update(['status' => 'confirmed', 'user_id' => $agent->id,
                'decided_by' => $by->id, 'decided_at' => now(), 'updated_at' => now()]);
        });
        app(AuditLogger::class)->record('roster.mls_id.confirmed', $agent, metadata: ['mls_id' => $agentMlsId, 'mls' => $name, 'by_owner' => $by->id !== $agent->id],
            severity: 'notice', label: $agent->name);
    }

    private function insert(int $sourceId, string $id, string $name, int $listings, ?int $userId, string $status, string $by, ?int $typedBy = null): void
    {
        $sample = DB::connection(Connections::listings())->table('listings')->where('mls_source_id', $sourceId)
            ->where(fn ($w) => $w->where('list_agent_mls_id', $id)->orWhere('co_list_agent_mls_id', $id))->orderByDesc('id')->limit(3)
            ->get(['street_number', 'street_name', 'unit_number', 'city'])
            ->map(fn (object $l): string => trim(preg_replace('/\s+/', ' ', trim(($l->street_number ?? '').' '.($l->street_name ?? '').($l->unit_number ? ' #'.$l->unit_number : ''))).($l->city ? ', '.$l->city : ''), ', '))
            ->filter()->values()->all();
        DB::connection(Connections::cell())->table('roster_mls_matches')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'user_id' => $userId,
            'mls_source_id' => $sourceId, 'agent_mls_id' => $id, 'agent_name' => mb_substr($name, 0, 120), 'listings' => $listings, 'sample' => json_encode($sample),
            'status' => $status, 'matched_by' => $by, 'decided_by' => $typedBy, 'decided_at' => $typedBy !== null ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * An ID an agent typed before it had any listings, now seen on listings carrying someone else's name: it comes off
     * the agent's record and goes to the owner to check, so no one's listings come in under the wrong person.
     *
     * @param  list<array{id: int, ulid: string, name: string, first: string, last: string}>  $people
     */
    private function recheckTyped(int $sourceId, string $id, string $mlsName, array $people): void
    {
        $db = DB::connection(Connections::cell());
        $match = $db->table('roster_mls_matches')->where('mls_source_id', $sourceId)->where('agent_mls_id', $id)->where('status', 'confirmed')->first();
        if ($match === null || $mlsName === '') {
            return;
        }
        $holder = collect($people)->firstWhere('id', (int) $match->user_id);
        $unchecked = $db->table('roster_credentials')->where('user_id', $match->user_id)->where('kind', 'mls')->where('status', 'current')->where('number', $id)
            ->whereNull('verified_at')->exists();
        if (! $unchecked || ($holder !== null && self::match($mlsName, [$holder]) !== null)) {
            return;
        }
        $db->transaction(function () use ($db, $match, $id, $mlsName): void {
            $db->table('roster_credentials')->where('user_id', $match->user_id)->where('kind', 'mls')->where('status', 'current')->where('number', $id)
                ->update(['status' => 'replaced', 'updated_at' => now()]);
            $db->table('roster_mls_matches')->where('id', $match->id)->update(['status' => 'review', 'decided_by' => $match->user_id, 'user_id' => null,
                'matched_by' => 'typed', 'agent_name' => mb_substr($mlsName, 0, 120), 'updated_at' => now()]);
        });
        app(AuditLogger::class)->record('roster.mls_id.name_mismatch', metadata: ['mls_id' => $id, 'mls_name' => $mlsName, 'typed_by' => $match->user_id], severity: 'warning');
    }

    /** Already suggested, confirmed, declined or waiting for the owner: do not suggest it again. */
    private function known(int $sourceId, string $id): bool
    {
        return DB::connection(Connections::cell())->table('roster_mls_matches')->where('mls_source_id', $sourceId)->where('agent_mls_id', $id)->exists()
            || DB::connection(Connections::cell())->table('roster_credentials')->where('kind', 'mls')->where('status', 'current')->where('number', $id)->exists();
    }

    /**
     * @return Collection<int, MlsAuthorization>
     */
    private function authorisations()
    {
        return MlsAuthorization::query()->where('tenant_id', $this->tenants->id())->where('status', 'active')
            ->where(fn ($w) => $w->whereNull('expires_on')->orWhereDate('expires_on', '>=', CarbonImmutable::today()->toDateString()))->get();
    }

    /**
     * Active agents and owners in this brokerage (the people who can hold listings).
     *
     * @return list<array{id: int, ulid: string, name: string, first: string, last: string}>
     */
    private function people(): array
    {
        return array_values(User::query()->whereIn('kind', [UserKind::Agent, UserKind::Owner])->where('status', UserStatus::Active)->orderBy('first_name')->get()
            ->map(fn (User $u): array => ['id' => (int) $u->id, 'ulid' => (string) $u->ulid, 'name' => (string) $u->name,
                'first' => self::word((string) $u->first_name), 'last' => self::word((string) $u->last_name)])->values()->all());
    }

    /**
     * The one person whose first and last name match the MLS name ("Andrew J. Smith" matches Andrew Smith). Null when
     * no one matches, or more than one does.
     *
     * @param  list<array{id: int, ulid: string, name: string, first: string, last: string}>  $people
     */
    public static function match(string $mlsName, array $people): ?int
    {
        $words = self::words($mlsName);
        if (count($words) < 2) {
            return null;
        }
        $first = $words[0];
        $last = $words[count($words) - 1];
        $hits = array_values(array_filter($people, fn (array $p): bool => $p['first'] === $first && $p['last'] === $last));

        return count($hits) === 1 ? $hits[0]['id'] : null;
    }

    private static function sameName(string $mlsName, User $agent): bool
    {
        return self::match($mlsName, [['id' => (int) $agent->id, 'ulid' => '', 'name' => '', 'first' => self::word((string) $agent->first_name),
            'last' => self::word((string) $agent->last_name)]]) !== null;
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        // "Smith, Andrew" is how some MLSs write it.
        if (str_contains($name, ',')) {
            [$last, $rest] = array_map('trim', explode(',', $name, 2));
            $name = "{$rest} {$last}";
        }
        $parts = preg_split('/\s+/', trim((string) preg_replace('/[^\p{L}\s\'-]/u', ' ', $name))) ?: [];
        $parts = array_values(array_filter(array_map(fn (string $w): string => self::word($w), $parts), fn (string $w): bool => mb_strlen($w) > 1
            && ! in_array($w, ['jr', 'sr', 'ii', 'iii', 'iv'], true)));

        return $parts;
    }

    private static function word(string $w): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}]/u', '', $w));
    }
}
