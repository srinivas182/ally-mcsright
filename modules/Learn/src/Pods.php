<?php

declare(strict_types=1);

namespace Modules\Learn;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * Accountability pods (LN4, E20): three to six agents who keep each other on track. Each week every member writes one
 * goal; at the end of the week what they did and where they need help. The pod page shows everyone's check-ins next
 * to the numbers RightAlly already knows for the week (calls logged, appointments, new deals), so nobody has to
 * report them. Only members (and the brokerage's managers) see a pod.
 */
final class Pods
{
    public const MIN = 2;

    public const MAX = 6;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @param  list<string>  $memberUlids */
    public function create(User $by, string $name, array $memberUlids): string
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the pod a name.']);
        }
        $members = User::query()->whereIn('ulid', $memberUlids)->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Agent, UserKind::Owner])->get()
            ->push($by)->unique('id');
        if ($members->count() < self::MIN || $members->count() > self::MAX) {
            throw ValidationException::withMessages(['members' => 'A pod has '.self::MIN.' to '.self::MAX.' people, including you.']);
        }
        $db = DB::connection(Connections::cell());
        $ulid = (string) Str::ulid();
        $db->transaction(function () use ($db, $ulid, $name, $by, $members): void {
            $id = $db->table('learn_pods')->insertGetId(['ulid' => $ulid, 'tenant_id' => $this->tenants->id(), 'name' => mb_substr($name, 0, 80), 'created_by' => $by->id,
                'created_at' => now(), 'updated_at' => now()]);
            foreach ($members as $m) {
                $db->table('learn_pod_members')->insert(['tenant_id' => $this->tenants->id(), 'pod_id' => $id, 'user_id' => $m->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        return $ulid;
    }

    public function checkIn(User $user, string $podUlid, string $goal, ?string $done, ?string $help): void
    {
        $pod = $this->pod($podUlid, $user, true);
        if (trim($goal) === '') {
            throw ValidationException::withMessages(['goal' => 'Write this week\'s goal.']);
        }
        DB::connection(Connections::cell())->table('learn_pod_checkins')->updateOrInsert(['pod_id' => $pod->id, 'user_id' => $user->id, 'week' => self::week()->toDateString()],
            ['tenant_id' => $this->tenants->id(), 'goal' => mb_substr(trim($goal), 0, 300), 'done' => $done !== null && trim($done) !== '' ? mb_substr(trim($done), 0, 300) : null,
                'help' => $help !== null && trim($help) !== '' ? mb_substr(trim($help), 0, 300) : null, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function leave(User $user, string $podUlid): void
    {
        $pod = $this->pod($podUlid, $user, true);
        DB::connection(Connections::cell())->table('learn_pod_members')->where('pod_id', $pod->id)->where('user_id', $user->id)->delete();
    }

    /** @return list<array{ulid: string, name: string, members: int}> */
    public function mine(User $user): array
    {
        $db = DB::connection(Connections::cell());
        $ids = $db->table('learn_pod_members')->where('tenant_id', $this->tenants->id())->where('user_id', $user->id)->pluck('pod_id');

        return array_values($db->table('learn_pods')->whereIn('id', $ids->all() ?: [0])->orderBy('name')->get()
            ->map(fn ($p): array => ['ulid' => (string) $p->ulid, 'name' => (string) $p->name, 'members' => $db->table('learn_pod_members')->where('pod_id', $p->id)->count()])->all());
    }

    /**
     * This week in the pod: each member's goal and check-in, and their numbers from RightAlly.
     *
     * @return array{ulid: string, name: string, week: string, members: list<array<string, mixed>>}
     */
    public function show(string $podUlid, User $viewer): array
    {
        $pod = $this->pod($podUlid, $viewer, false);
        $db = DB::connection(Connections::cell());
        $week = self::week();
        $end = $week->addWeek();
        $members = User::query()->whereIn('id', $db->table('learn_pod_members')->where('pod_id', $pod->id)->pluck('user_id')->all() ?: [0])->orderBy('first_name')->get();
        $checkins = $db->table('learn_pod_checkins')->where('pod_id', $pod->id)->where('week', $week->toDateString())->get()->keyBy('user_id');
        $count = fn (string $table, string $userCol, array $extra = []): array => rescue(fn (): array => $db->table($table)->where('tenant_id', $this->tenants->id())
            ->whereIn($userCol, $members->pluck('id')->all() ?: [0])->whereBetween('created_at', [$week, $end])->where($extra)->groupBy($userCol)
            ->select($userCol)->selectRaw('count(*) as n')->pluck('n', $userCol)->map(fn ($n): int => (int) $n)->all(), [], false);
        $calls = $count('crm_activities', 'user_id', ['type' => 'call']);
        $appointments = $count('appt_consultations', 'user_id');
        $deals = $count('tx_deals', 'agent_user_id');

        return ['ulid' => (string) $pod->ulid, 'name' => (string) $pod->name, 'week' => $week->toDateString(),
            'members' => array_values($members->map(function (User $m) use ($checkins, $calls, $appointments, $deals, $viewer): array {
                $c = $checkins->get($m->id);

                return ['name' => $m->name, 'me' => $m->id === $viewer->id, 'goal' => $c->goal ?? null, 'done' => $c->done ?? null, 'help' => $c->help ?? null,
                    'calls' => $calls[$m->id] ?? 0, 'appointments' => $appointments[$m->id] ?? 0, 'deals' => $deals[$m->id] ?? 0];
            })->all())];
    }

    /**
     * People who can be invited (active agents and owners).
     *
     * @return list<array{ulid: string, name: string}>
     */
    public function people(User $viewer): array
    {
        return array_values(User::query()->where('status', UserStatus::Active)->whereIn('kind', [UserKind::Agent, UserKind::Owner])->whereKeyNot($viewer->id)
            ->orderBy('first_name')->limit(300)->get()->map(fn (User $u): array => ['ulid' => (string) $u->ulid, 'name' => $u->name])->all());
    }

    public static function week(): CarbonImmutable
    {
        return CarbonImmutable::today()->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function pod(string $ulid, User $user, bool $memberOnly): stdClass
    {
        $db = DB::connection(Connections::cell());
        $pod = $db->table('learn_pods')->where('tenant_id', $this->tenants->id())->where('ulid', $ulid)->first();
        $member = $pod !== null && $db->table('learn_pod_members')->where('pod_id', $pod->id)->where('user_id', $user->id)->exists();
        $manager = ! $memberOnly && in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
        if ($pod === null || (! $member && ! $manager)) {
            abort(404);
        }

        return $pod;
    }
}
