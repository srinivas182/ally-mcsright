<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\Models\MlsAgent;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Models\RecruitNote;

/**
 * The recruiting pipeline (RC1, specs/grow/17): recruits by stage, each with a next step, a history, and alerts when
 * their brokerage or production changes. Recruits are the brokerage's own business: only people who may recruit
 * (`recruiting.manage`) see them.
 */
final class Pipeline
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $in  name, email, phone, brokerage, source, next_step, next_step_on
     */
    public function add(array $in, User $by): Recruit
    {
        $data = validator($in, [
            'name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:20'],
            'brokerage' => ['nullable', 'string', 'max:120'], 'source' => ['nullable', Rule::in(['referral', 'manual'])],
            'next_step' => ['nullable', 'string', 'max:200'], 'next_step_on' => ['nullable', 'date'],
        ])->validate();
        $recruit = Recruit::query()->create(['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => self::phone($data['phone'] ?? null),
            'brokerage' => $data['brokerage'] ?? null, 'source' => $data['source'] ?? 'manual', 'owner_user_id' => $by->id, 'stage' => 'prospect',
            'stage_changed_on' => CarbonImmutable::today(), 'next_step' => $data['next_step'] ?? 'First call', 'next_step_on' => $data['next_step_on'] ?? CarbonImmutable::today()->addDay()]);
        $this->log($recruit, $by, 'stage', 'Added to recruiting.');
        app(AuditLogger::class)->record('recruiting.recruit.added', $recruit, metadata: ['source' => $recruit->source]);

        return $recruit;
    }

    /** From the MLS agent database; once only. */
    public function addFromMls(MlsAgent $agent, User $by): Recruit
    {
        $existing = Recruit::query()->where('mls_agent_id', $agent->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $recruit = Recruit::query()->create(['mls_agent_id' => $agent->id, 'name' => $agent->displayName(), 'brokerage' => $agent->office_name, 'source' => 'mls',
            'owner_user_id' => $by->id, 'stage' => 'prospect', 'stage_changed_on' => CarbonImmutable::today(), 'next_step' => 'First call',
            'next_step_on' => CarbonImmutable::today()->addDay()]);
        $this->log($recruit, $by, 'stage', "Added from the MLS: {$agent->sides} closed sides in the last 12 months.");
        app(AuditLogger::class)->record('recruiting.recruit.added', $recruit, metadata: ['source' => 'mls']);

        return $recruit;
    }

    public function move(Recruit $recruit, string $stage, User $by, ?string $reason = null): void
    {
        if (! array_key_exists($stage, Recruit::STAGES)) {
            throw ValidationException::withMessages(['stage' => 'Choose a stage.']);
        }
        if ($stage === $recruit->stage) {
            return;
        }
        $from = Recruit::STAGES[$recruit->stage];
        $recruit->fill(['stage' => $stage, 'stage_changed_on' => CarbonImmutable::today(), 'not_now_reason' => $stage === 'not_now' ? mb_substr((string) $reason, 0, 200) ?: null : null]);
        if (! in_array($stage, Recruit::OPEN, true)) {
            $recruit->fill(['next_step' => null, 'next_step_on' => null]);
        }
        $recruit->save();
        $this->log($recruit, $by, 'stage', "{$from} → ".Recruit::STAGES[$stage].($stage === 'not_now' && $reason ? " ({$reason})" : '').'.');
        app(AuditLogger::class)->record('recruiting.recruit.stage', $recruit, ['stage' => ['old' => $from, 'new' => Recruit::STAGES[$stage]]]);
    }

    /**
     * @param  array<string, mixed>  $in  kind, body, next_step, next_step_on
     */
    public function note(Recruit $recruit, array $in, User $by): void
    {
        $data = validator($in, ['kind' => ['required', Rule::in(RecruitNote::KINDS)], 'body' => ['nullable', 'string', 'max:5000'],
            'next_step' => ['nullable', 'string', 'max:200'], 'next_step_on' => ['nullable', 'date']])->validate();
        if (trim((string) ($data['body'] ?? '')) !== '') {
            $this->log($recruit, $by, $data['kind'], trim((string) $data['body']));
        }
        if (array_key_exists('next_step', $data) || array_key_exists('next_step_on', $data)) {
            $recruit->fill(['next_step' => $data['next_step'] ?? null, 'next_step_on' => $data['next_step_on'] ?? null])->save();
        }
    }

    /**
     * Their contact details and current plan (for "what you'd earn here").
     *
     * @param  array<string, mixed>  $in
     */
    public function update(Recruit $recruit, array $in): void
    {
        $data = validator($in, [
            'name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:20'], 'brokerage' => ['nullable', 'string', 'max:120'],
            'current_split' => ['nullable', 'numeric', 'min:0', 'max:100'], 'current_cap' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'current_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'owner_user_id' => ['nullable', 'integer'],
        ])->validate();
        $owner = isset($data['owner_user_id']) ? User::query()->find((int) $data['owner_user_id']) : null;
        $recruit->fill(['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => self::phone($data['phone'] ?? null), 'brokerage' => $data['brokerage'] ?? null,
            'current_split' => isset($data['current_split']) ? (string) $data['current_split'] : null,
            'current_cap_cents' => isset($data['current_cap']) ? (int) round((float) $data['current_cap'] * 100) : null,
            'current_fee_cents' => isset($data['current_fee']) ? (int) round((float) $data['current_fee'] * 100) : null,
            'owner_user_id' => $owner !== null && $owner->can('recruiting.manage') ? $owner->id : $recruit->owner_user_id])->save();
    }

    /** An alert on whoever is recruiting this agent (the daily refresh calls it). */
    public function alertFor(MlsAgent $agent, string $kind): void
    {
        $recruit = Recruit::query()->where('mls_agent_id', $agent->id)->whereIn('stage', Recruit::OPEN)->first();
        if ($recruit === null) {
            return;
        }
        $db = DB::connection(Connections::cell());
        if ($kind === 'production' && $db->table('rec_alerts')->where('tenant_id', $this->tenants->id())->where('recruit_id', $recruit->id)
            ->where('kind', 'production')->where('created_at', '>=', now()->subDays(90))->exists()) {
            return; // once a quarter is enough
        }
        $message = $kind === 'moved'
            ? "{$recruit->name} moved from ".($agent->previous_office_name ?? 'their office').' to '.($agent->office_name ?? 'another office').'. A good moment to check in.'
            : "{$recruit->name} closed {$agent->sides} sides in the last 12 months, up from {$agent->sides_before}.";
        $db->table('rec_alerts')->insert(['tenant_id' => $this->tenants->id(), 'recruit_id' => $recruit->id, 'kind' => $kind, 'message' => $message,
            'created_at' => now(), 'updated_at' => now()]);
        $this->log($recruit, null, 'alert', $message);
        if ($kind === 'moved') {
            $recruit->fill(['brokerage' => $agent->office_name ?? $recruit->brokerage])->save();
        }
    }

    /**
     * Unseen alerts, newest first.
     *
     * @return list<array{id: int, recruit: string, name: string, kind: string, message: string, at: string}>
     */
    public function alerts(): array
    {
        return array_values(DB::connection(Connections::cell())->table('rec_alerts as a')->join('rec_recruits as r', 'r.id', '=', 'a.recruit_id')
            ->where('a.tenant_id', $this->tenants->id())->whereNull('a.seen_at')->orderByDesc('a.id')->limit(20)
            ->get(['a.id', 'r.ulid', 'r.name', 'a.kind', 'a.message', 'a.created_at'])
            ->map(fn (object $a): array => ['id' => (int) $a->id, 'recruit' => (string) $a->ulid, 'name' => (string) $a->name, 'kind' => (string) $a->kind,
                'message' => (string) $a->message, 'at' => CarbonImmutable::parse((string) $a->created_at)->toDateString()])->all());
    }

    public function seen(Recruit $recruit): void
    {
        DB::connection(Connections::cell())->table('rec_alerts')->where('tenant_id', $this->tenants->id())->where('recruit_id', $recruit->id)->whereNull('seen_at')
            ->update(['seen_at' => now()]);
    }

    /** Open recruits whose next step is due (today or before), or who have no next step. */
    public function dueCount(?User $owner = null): int
    {
        return Recruit::query()->whereIn('stage', Recruit::OPEN)->when($owner !== null, fn ($q) => $q->where('owner_user_id', $owner?->id))
            ->where(fn ($q) => $q->whereNull('next_step_on')->orWhere('next_step_on', '<=', CarbonImmutable::today()->toDateString()))->count();
    }

    private function log(Recruit $recruit, ?User $by, string $kind, string $body): void
    {
        RecruitNote::query()->create(['recruit_id' => $recruit->id, 'user_id' => $by?->id, 'kind' => $kind, 'body' => $body, 'occurred_at' => now()]);
    }

    private static function phone(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', (string) $raw) ?? '';
        if (strlen($d) === 10) {
            return '+1'.$d;
        }

        return strlen($d) === 11 && $d[0] === '1' ? '+'.$d : ($d === '' ? null : mb_substr((string) $raw, 0, 20));
    }
}
