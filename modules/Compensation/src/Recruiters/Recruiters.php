<?php

declare(strict_types=1);

namespace Modules\Compensation\Recruiters;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Actions\ManageInvitations;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Events\UserActivated;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * CM4b: recruiters. Only the brokerage adds them; they need no licence and never hold deals. They bring agents in
 * (their own join link works like any agent's) and are paid a flat bonus when a recruit closes their first deal.
 * Revenue share on levels 1–2 is off unless the owner switches it on and confirms their state allows it: in most
 * US states a brokerage may share compensation only with licensed people.
 */
final class Recruiters
{
    /** The suggested flat bonus per recruit's first closing (a money default for the owner to confirm). */
    public const DEFAULT_BONUS_CENTS = 50_000;

    /** @var array<int, array<int, bool>> per brokerage: user id => recruiter */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function isRecruiter(int $userId): bool
    {
        $tenant = $this->tenants->id();
        if (! isset($this->cache[$tenant])) {
            $this->cache[$tenant] = DB::connection(Connections::cell())->table('rs_recruiters')->whereNull('removed_at')->whereNotNull('user_id')
                ->pluck('user_id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
        }

        return isset($this->cache[$tenant][$userId]);
    }

    /**
     * @return array{bonus_cents: int, share_levels: int, confirmed_by: string|null, confirmed_at: string|null}
     */
    public function settings(): array
    {
        $row = DB::connection(Connections::cell())->table('rs_settings')->first();

        return [
            'bonus_cents' => $row?->recruiter_bonus_cents !== null ? (int) $row->recruiter_bonus_cents : self::DEFAULT_BONUS_CENTS,
            'share_levels' => (int) ($row->recruiter_share_levels ?? 0),
            'confirmed_by' => $row?->recruiter_share_confirmed_by !== null ? User::query()->find((int) $row->recruiter_share_confirmed_by)?->name : null,
            'confirmed_at' => $row?->recruiter_share_confirmed_at !== null ? (string) $row->recruiter_share_confirmed_at : null,
        ];
    }

    /** The revenue-share levels a recruiter may receive (0 unless the owner switched it on). */
    public function shareLevels(): int
    {
        return $this->settings()['share_levels'];
    }

    public function bonusFor(int $userId): int
    {
        $own = DB::connection(Connections::cell())->table('rs_recruiters')->where('user_id', $userId)->whereNull('removed_at')->value('bonus_cents');

        return $own !== null ? (int) $own : $this->settings()['bonus_cents'];
    }

    /**
     * The owner's choices: the default bonus, and revenue share for recruiters (levels 1 or 2) only with the
     * confirmation that their state allows it.
     */
    public function saveSettings(int $bonusCents, int $shareLevels, bool $confirmed, User $by): void
    {
        if ($bonusCents < 0 || $bonusCents > 10_000_000) {
            throw ValidationException::withMessages(['bonus' => 'Enter a bonus from $0 to $100,000.']);
        }
        if ($shareLevels < 0 || $shareLevels > 2) {
            throw ValidationException::withMessages(['share_levels' => 'Recruiters can receive levels 1 or 2 at most.']);
        }
        if ($shareLevels > 0 && ! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm that your state allows paying revenue share to people without a licence (check with your lawyer).']);
        }
        DB::connection(Connections::cell())->table('rs_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'recruiter_bonus_cents' => $bonusCents, 'recruiter_share_levels' => $shareLevels,
            'recruiter_share_confirmed_by' => $shareLevels > 0 ? $by->id : null, 'recruiter_share_confirmed_at' => $shareLevels > 0 ? now() : null,
            'updated_at' => now(), 'created_at' => now(),
        ]);
        app(AuditLogger::class)->record('compensation.recruiters.settings', metadata: ['bonus_cents' => $bonusCents, 'share_levels' => $shareLevels, 'confirmed' => $confirmed],
            severity: $shareLevels > 0 ? 'critical' : 'warning');
    }

    /** Adds a recruiter: they get an invitation; once they accept they are a recruiter (no licence, no deals). */
    public function add(string $email, string $firstName, string $lastName, ?int $bonusCents, User $by): void
    {
        $email = mb_strtolower(trim($email));
        $db = DB::connection(Connections::cell());
        if ($db->table('rs_recruiters')->where('email', $email)->whereNull('removed_at')->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already a recruiter.']);
        }
        $existing = User::query()->where('email', $email)->first();
        if ($existing === null) {
            app(ManageInvitations::class)->invite($email, $firstName, $lastName, UserKind::Agent, $by);
        }
        $db->table('rs_recruiters')->insert(['tenant_id' => $this->tenants->id(), 'email' => $email, 'user_id' => $existing?->id, 'bonus_cents' => $bonusCents,
            'added_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->cache = [];
        app(AuditLogger::class)->record('compensation.recruiter.added', metadata: ['email' => $email, 'bonus_cents' => $bonusCents], severity: 'notice', label: trim("{$firstName} {$lastName}"));
    }

    public function remove(int $id, User $by): void
    {
        DB::connection(Connections::cell())->table('rs_recruiters')->where('id', $id)->update(['removed_at' => now(), 'updated_at' => now()]);
        $this->cache = [];
        app(AuditLogger::class)->record('compensation.recruiter.removed', metadata: ['id' => $id], severity: 'notice');
    }

    /** They accepted the invitation: link the recruiter to their account. */
    public function activated(UserActivated $event): void
    {
        $user = User::query()->find($event->userId);
        if ($user instanceof User) {
            DB::connection(Connections::cell())->table('rs_recruiters')->where('email', mb_strtolower((string) $user->email))->whereNull('user_id')
                ->update(['user_id' => $user->id, 'updated_at' => now()]);
            $this->cache = [];
        }
    }

    /**
     * @return list<array{id: int, name: string, email: string, joined: bool, recruits: int, closed: int, bonus_cents: int, paid_cents: int}>
     */
    public function list(): array
    {
        $db = DB::connection(Connections::cell());
        $out = [];
        foreach ($db->table('rs_recruiters')->whereNull('removed_at')->orderBy('email')->get() as $r) {
            $user = $r->user_id !== null ? User::query()->find((int) $r->user_id) : null;
            $recruits = $user !== null ? $db->table('rs_sponsorships')->where('sponsor_user_id', $user->id)->whereNull('effective_to')->pluck('agent_user_id') : collect();
            $out[] = [
                'id' => (int) $r->id, 'name' => $user->name ?? (string) $r->email, 'email' => (string) $r->email, 'joined' => $user !== null,
                'recruits' => $recruits->count(),
                'closed' => $recruits->isEmpty() ? 0 : (int) $db->table('comp_calculation_agents')->whereIn('agent_user_id', $recruits)->where('status', 'final')->distinct()->count('agent_user_id'),
                'bonus_cents' => $r->bonus_cents !== null ? (int) $r->bonus_cents : $this->settings()['bonus_cents'],
                'paid_cents' => $user !== null ? (int) $db->table('rs_entries')->where('recipient_user_id', $user->id)->whereIn('kind', ['bonus', 'share'])->whereNull('reversal_of')->sum('amount_cents') : 0,
            ];
        }

        return $out;
    }
}
