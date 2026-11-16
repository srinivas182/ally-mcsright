<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;

/**
 * The quarterly access review (specs/core/03, R1). Owners see every role each person holds, with what it gives, when
 * they last signed in and when it ends; they keep or remove each one, and sign the review off. A removed role is
 * revoked at once. The signed-off review is the attestation auditors ask for (SOC 2).
 */
final class AccessReviews
{
    /** Signed in longer ago than this: flagged as dormant. */
    public const DORMANT_DAYS = 90;

    public function __construct(private readonly Roles $roles, private readonly PermissionRegistry $registry, private readonly AuditLogger $audit) {}

    public static function quarter(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();

        return $at->year.'-Q'.$at->quarter;
    }

    /**
     * @return object{id: int, ulid: string, quarter: string, started_at: string, completed_at: string|null, completed_by: int|null, note: string|null, reminded_at: string|null}|null
     */
    public function current(): ?object
    {
        /** @var object{id: int, ulid: string, quarter: string, started_at: string, completed_at: string|null, completed_by: int|null, note: string|null, reminded_at: string|null}|null */
        return DB::connection(Connections::cell())->table('access_reviews')->where('quarter', self::quarter())->first();
    }

    public function due(): bool
    {
        return $this->current()?->completed_at === null;
    }

    public function start(User $by): void
    {
        $this->guard($by);
        if ($this->current() !== null) {
            return;
        }
        $db = DB::connection(Connections::cell());
        $db->transaction(function () use ($db, $by): void {
            $id = $db->table('access_reviews')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $by->tenant_id, 'quarter' => self::quarter(),
                'started_by' => $by->id, 'started_at' => CarbonImmutable::now()]);
            $now = CarbonImmutable::now();
            foreach (UserRole::query()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))->get() as $grant) {
                $role = Role::query()->find($grant->role_id);
                if (! $role instanceof Role) {
                    continue;
                }
                $db->table('access_review_items')->insert(['tenant_id' => $by->tenant_id, 'review_id' => $id, 'user_id' => $grant->user_id, 'role_id' => $role->id,
                    'role_name' => $role->name, 'risky' => $role->is_locked || $this->registry->riskyIn(array_keys($this->roles->permissionsOf($role))) !== [],
                    'expires_at' => $grant->expires_at]);
            }
        });
        $this->audit->record('core.access_review.started', $by, metadata: ['quarter' => self::quarter()], label: self::quarter());
    }

    /**
     * Everything the review screen shows.
     *
     * @return list<array{id: int, person: string, person_ulid: string, kind: string, role: string, risky: bool, expires_at: string|null, last_sign_in: string|null, dormant: bool, access: list<string>, decision: string|null}>
     */
    public function items(): array
    {
        $review = $this->current();
        if ($review === null) {
            return [];
        }
        $out = [];
        $rows = DB::connection(Connections::cell())->table('access_review_items')->where('review_id', $review->id)->orderByDesc('risky')->orderBy('id')->get();
        foreach ($rows as $row) {
            $person = User::query()->find($row->user_id);
            if (! $person instanceof User) {
                continue;
            }
            $role = Role::query()->find($row->role_id);
            $last = $person->last_login_at;
            $out[] = ['id' => (int) $row->id, 'person' => $person->name, 'person_ulid' => $person->ulid, 'kind' => $person->kind->value, 'role' => (string) $row->role_name,
                'risky' => (bool) $row->risky, 'expires_at' => $row->expires_at !== null ? CarbonImmutable::parse((string) $row->expires_at)->toDateString() : null,
                'last_sign_in' => $last?->toDateString(), 'dormant' => $last === null || $last->lt(CarbonImmutable::now()->subDays(self::DORMANT_DAYS)),
                'access' => $role instanceof Role ? array_slice($this->roles->describe($this->roles->permissionsOf($role), $role->is_locked), 0, 8) : ['This role was deleted.'],
                'decision' => $row->decision !== null ? (string) $row->decision : null];
        }

        return $out;
    }

    public function decide(int $itemId, string $decision, User $by): void
    {
        $this->guard($by);
        $review = $this->current();
        $db = DB::connection(Connections::cell());
        if ($review === null || $review->completed_at !== null) {
            throw ValidationException::withMessages(['item' => 'This review is closed or the line was not found.']);
        }
        $item = $db->table('access_review_items')->where('review_id', $review->id)->where('id', $itemId)->first();
        if ($item === null) {
            throw ValidationException::withMessages(['item' => 'This review is closed or the line was not found.']);
        }
        if (! in_array($decision, ['keep', 'remove'], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose keep or remove.']);
        }
        $person = User::query()->find($item->user_id);
        $role = Role::query()->find($item->role_id);
        if ($decision === 'remove' && $role instanceof Role && $role->is_locked) {
            throw ValidationException::withMessages(['decision' => 'The Owner role stays with owners. Change who is an owner on the People page.']);
        }
        $db->table('access_review_items')->where('id', $itemId)->update(['decision' => $decision, 'decided_by' => $by->id, 'decided_at' => CarbonImmutable::now()]);
        if ($decision === 'remove' && $person instanceof User && $role instanceof Role) {
            $this->roles->revoke($person, $role, 'access review '.self::quarter());
        }
    }

    public function complete(User $by, ?string $note = null): void
    {
        $this->guard($by);
        $review = $this->current();
        if ($review === null || $review->completed_at !== null) {
            throw ValidationException::withMessages(['review' => 'Start this quarter\'s review first.']);
        }
        $db = DB::connection(Connections::cell());
        $open = $db->table('access_review_items')->where('review_id', $review->id)->whereNull('decision')->count();
        if ($open > 0) {
            throw ValidationException::withMessages(['review' => "Keep or remove the {$open} remaining ".Str::plural('line', $open).' first.']);
        }
        $db->table('access_reviews')->where('id', $review->id)->update(['completed_by' => $by->id, 'completed_at' => CarbonImmutable::now(), 'note' => $note !== null ? mb_substr($note, 0, 500) : null]);
        $counts = $db->table('access_review_items')->where('review_id', $review->id)->selectRaw('decision, count(*) as n')->groupBy('decision')->pluck('n', 'decision')->all();
        $this->audit->record('core.access_review.completed', $by, metadata: ['quarter' => $review->quarter, 'kept' => (int) ($counts['keep'] ?? 0), 'removed' => (int) ($counts['remove'] ?? 0), 'note' => $note],
            severity: 'warning', label: $review->quarter);
    }

    /**
     * Who can do what, one row per person and permission (the export auditors ask for).
     *
     * @return list<list<string>>
     */
    public function matrix(): array
    {
        $rows = [['Person', 'Email', 'Type', 'Roles', 'Permission', 'For', 'Sensitive', 'Access ends']];
        $where = ['own' => 'Own records', 'team' => 'Their team', 'office' => 'Their office', 'tenant' => 'Everyone'];
        $permissions = app(Permissions::class);
        foreach (User::query()->where('status', 'active')->where('kind', '!=', UserKind::HqStaff)->orderBy('first_name')->get() as $person) {
            $active = $permissions->activeRoles($person);
            $ends = UserRole::query()->where('user_id', $person->id)->whereNotNull('expires_at')->min('expires_at');
            $roleNames = $person->kind === UserKind::Owner ? 'Owner' : ($active->isEmpty() ? ($person->kind === UserKind::Staff ? 'None (staff access)' : 'None') : $active->pluck('name')->implode(', '));
            $grants = $permissions->grants($person);
            if ($grants === []) {
                $rows[] = [$person->name, (string) $person->email, $person->kind->value, $roleNames, $person->kind === UserKind::Staff ? 'Staff access (no role yet)' : 'Their own work only', '', '', ''];

                continue;
            }
            foreach ($grants as $key => $scope) {
                $def = $this->registry->get($key);
                $rows[] = [$person->name, (string) $person->email, $person->kind->value, $roleNames, (string) ($def['label'] ?? $key),
                    ($def['scoped'] ?? false) ? $where[$scope] : 'Everyone', ($def['sensitive'] ?? false) ? 'Yes' : '', $ends !== null ? CarbonImmutable::parse((string) $ends)->toDateString() : ''];
            }
        }

        return $rows;
    }

    private function guard(User $by): void
    {
        if (! Can::manage($by, 'core.access.review') || ($by->kind === UserKind::Staff && ! app(Permissions::class)->governed($by))) {
            throw ValidationException::withMessages(['review' => 'Only owners, or a role with "Run the quarterly access review", can do this.']);
        }
    }
}
