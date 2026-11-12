<?php

declare(strict_types=1);

namespace Modules\Compensation\RevenueShare;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Rules\RevenueShareRules;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Who receives a level's revenue share (specs/compensation/02, addition 4): active agents; agents who
 * left after vesting (when the plan has vesting); and, for a vested agent who has died, their named
 * beneficiary. Suspended or blocked agents never receive it (legacy rule 8).
 */
final class Members
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{0: int|null, 1: string|null} payee and, when nobody can be paid, the reason
     */
    public function payee(int $userId, RevenueShareRules $rules, CarbonImmutable $on): array
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User || $user->status === UserStatus::Suspended) {
            return [null, 'ineligible_upline'];
        }
        $member = $this->row($userId);
        $deceased = ($member['deceased_on'] ?? null) !== null;
        if ($user->status === UserStatus::Active && ! $deceased) {
            return [$userId, null];
        }
        if ($rules->vestingYears === null || ! $this->vested($user, $rules->vestingYears)) {
            return [null, 'ineligible_upline'];
        }
        if (! $deceased) {
            return [$userId, null]; // left after vesting
        }
        $beneficiary = ($member['beneficiary_user_id'] ?? null) !== null ? User::query()->find($member['beneficiary_user_id']) : null;

        return $beneficiary instanceof User && $beneficiary->status === UserStatus::Active ? [$beneficiary->id, null] : [null, 'ineligible_upline'];
    }

    public function vested(User $user, int $years): bool
    {
        $member = $this->row($user->id);
        $joined = CarbonImmutable::parse($member['joined_on'] ?? $user->created_at?->toDateString() ?? 'now');
        $until = CarbonImmutable::parse($member['left_on'] ?? $member['deceased_on'] ?? CarbonImmutable::now()->toDateString());

        return $joined->addYears($years)->lte($until);
    }

    public function joined(int $userId, CarbonImmutable $on): void
    {
        DB::connection(Connections::cell())->table('rs_members')->insertOrIgnore([
            'tenant_id' => $this->tenants->id(), 'user_id' => $userId, 'joined_on' => $on->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function left(int $userId, CarbonImmutable $on): void
    {
        $this->ensure($userId);
        DB::connection(Connections::cell())->table('rs_members')->where('user_id', $userId)->whereNull('left_on')->update(['left_on' => $on->toDateString(), 'updated_at' => now()]);
    }

    public function setBeneficiary(User $agent, User $beneficiary): void
    {
        if ($beneficiary->id === $agent->id || $beneficiary->status !== UserStatus::Active) {
            throw ValidationException::withMessages(['beneficiary' => 'Choose someone else with an active RightAlly account in this brokerage.']);
        }
        $this->ensure($agent->id);
        DB::connection(Connections::cell())->table('rs_members')->where('user_id', $agent->id)->update(['beneficiary_user_id' => $beneficiary->id, 'updated_at' => now()]);
        app(AuditLogger::class)->record('compensation.beneficiary.set', $agent, metadata: ['beneficiary' => $beneficiary->name], severity: 'notice', label: $agent->name);
    }

    public function markDeceased(User $agent, CarbonImmutable $on, User $by, string $note): void
    {
        if (mb_strlen(trim($note)) < 10) {
            throw ValidationException::withMessages(['note' => 'Record how this was confirmed (at least 10 characters).']);
        }
        $this->ensure($agent->id);
        DB::connection(Connections::cell())->table('rs_members')->where('user_id', $agent->id)->update(['deceased_on' => $on->toDateString(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('compensation.member.deceased', $agent, metadata: ['on' => $on->toDateString(), 'note' => $note], severity: 'critical', label: $agent->name);
    }

    private function ensure(int $userId): void
    {
        $created = User::query()->whereKey($userId)->value('created_at');
        $this->joined($userId, CarbonImmutable::parse((string) ($created ?? 'now')));
    }

    /**
     * @return array{joined_on: string, left_on: string|null, deceased_on: string|null, beneficiary_user_id: int|null}|null
     */
    private function row(int $userId): ?array
    {
        $row = DB::connection(Connections::cell())->table('rs_members')->where('user_id', $userId)->first();
        if ($row === null) {
            return null;
        }
        $r = (array) $row;

        return ['joined_on' => (string) $r['joined_on'], 'left_on' => $r['left_on'] !== null ? (string) $r['left_on'] : null,
            'deceased_on' => $r['deceased_on'] !== null ? (string) $r['deceased_on'] : null,
            'beneficiary_user_id' => $r['beneficiary_user_id'] !== null ? (int) $r['beneficiary_user_id'] : null];
    }
}
