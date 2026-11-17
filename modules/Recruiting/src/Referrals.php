<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\Models\MlsAgent;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Models\RecruitNote;

/**
 * Agents refer recruits (RC3, specs/grow/17), usually for their revenue share: they suggest someone they know, or
 * (when the broker allows it) someone from the MLS agent database. The brokerage's recruiters take it from there;
 * the agent sees only how far it has gone. An AI draft helps them write their own personal note, which they send
 * themselves: RightAlly never sends it, and never in bulk.
 */
final class Referrals
{
    public const FEATURE = 'recruiting.outreach';

    public function __construct(private readonly TenantContext $tenants) {}

    public function agentsSeeDatabase(): bool
    {
        return (bool) DB::connection(Connections::cell())->table('rec_settings')->where('tenant_id', $this->tenants->id())->value('agents_see_database');
    }

    public function setAgentsSeeDatabase(bool $on): void
    {
        DB::connection(Connections::cell())->table('rec_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['agents_see_database' => $on, 'updated_at' => now()]);
        app(AuditLogger::class)->record('recruiting.referrals.database', metadata: ['on' => $on]);
    }

    /**
     * Producing agents outside the brokerage worth a word, most likely to move first (only when the broker allows it).
     *
     * @return list<array{id: int, name: string, office: string|null, sides: int, reason: string|null}>
     */
    public function suggestions(): array
    {
        if (! $this->agentsSeeDatabase() || app(AgentDatabase::class)->sources() === []) {
            return [];
        }
        $taken = Recruit::query()->whereNotNull('mls_agent_id')->pluck('mls_agent_id')->all();

        return array_values(MlsAgent::query()->where('ours', false)->where('sides', '>=', 3)->whereIn('mls_source_id', app(AgentDatabase::class)->sources())
            ->whereNotIn('id', $taken ?: [0])->orderByDesc('move_score')->orderByDesc('sides')->limit(20)->get()
            ->map(fn (MlsAgent $a): array => ['id' => $a->id, 'name' => $a->displayName(), 'office' => $a->office_name, 'sides' => $a->sides,
                'reason' => $a->sides_before > 0 && $a->sides > $a->sides_before ? 'Growing: '.$a->sides.' closings, up from '.$a->sides_before : null])->all());
    }

    /**
     * @param  array<string, mixed>  $in  name, email, phone, brokerage, note; or mls_agent (id)
     */
    public function refer(User $sponsor, array $in): Recruit
    {
        $agent = isset($in['mls_agent']) && $this->agentsSeeDatabase() ? MlsAgent::query()->find((int) $in['mls_agent']) : null;
        if ($agent !== null) {
            if (Recruit::query()->where('mls_agent_id', $agent->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'The brokerage is already in touch with them. Thank you!']);
            }
            $data = ['name' => $agent->displayName(), 'brokerage' => $agent->office_name, 'note' => $in['note'] ?? null];
        } else {
            $data = validator($in, ['name' => ['required', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:20'],
                'brokerage' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:1000']])->validate();
            $email = isset($data['email']) ? mb_strtolower((string) $data['email']) : null;
            if ($email !== null && Recruit::query()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'The brokerage is already in touch with them. Thank you!']);
            }
            $data['email'] = $email;
        }
        $recruit = app(Pipeline::class)->add(['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'brokerage' => $data['brokerage'] ?? null,
            'source' => 'referral', 'next_step' => "Call (referred by {$sponsor->first_name})"], $sponsor);
        $recruit->fill(['mls_agent_id' => $agent?->id, 'sponsor_user_id' => $sponsor->id, 'owner_user_id' => null])->save();
        if (trim((string) ($data['note'] ?? '')) !== '') {
            RecruitNote::query()->create(['recruit_id' => $recruit->id, 'user_id' => $sponsor->id, 'kind' => 'note', 'body' => trim((string) $data['note']), 'occurred_at' => now()]);
        }
        DB::connection(Connections::cell())->table('rec_alerts')->insert(['tenant_id' => $this->tenants->id(), 'recruit_id' => $recruit->id, 'kind' => 'referral',
            'message' => "{$sponsor->name} referred {$recruit->name}.", 'created_at' => now(), 'updated_at' => now()]);

        return $recruit;
    }

    /**
     * The agent's own referrals and how far each has gone (stage only: the recruiters' notes stay private).
     *
     * @return list<array{name: string, stage: string, since: string}>
     */
    public function mine(User $sponsor): array
    {
        return array_values(Recruit::query()->where('sponsor_user_id', $sponsor->id)->orderByDesc('id')->get()
            ->map(fn (Recruit $r): array => ['name' => $r->name, 'stage' => $r->stage === 'not_now' ? 'Not right now' : Recruit::STAGES[$r->stage],
                'since' => $r->created_at->toDateString()])->all());
    }

    /** A first draft of a personal note, for the agent to edit and send themselves. */
    public function draft(User $writer, string $name): string
    {
        $name = trim(mb_substr(strip_tags($name), 0, 120));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Who is the note for?']);
        }
        $careers = app(Careers::class)->settings();
        $result = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'recruiting.outreach', 'recruit-note:'.$writer->id.':'.CarbonImmutable::now()->format('YmdHisv'),
            inputs: ['writer' => $writer->name, 'brokerage' => $this->tenants->current()->name, 'reasons' => implode('; ', $careers['reasons'])],
            untrustedContent: ['The other agent: '.$name], outputSchema: ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string']]], userId: $writer->id));
        $message = trim((string) ($result->json['message'] ?? ''));
        // Belt and braces: no links, no money, never long.
        $message = (string) preg_replace('~https?://\S+~i', '', $message);
        if ($message === '' || preg_match('/\$\s?\d|\d+\s?%/', $message) === 1) {
            return "Hi {$name}, it's {$writer->first_name}. I've really enjoyed working at {$this->tenants->current()->name} and thought of you. Would you be up for a coffee sometime? Happy to show you what you'd keep here, with your own numbers.";
        }

        return mb_substr($message, 0, 900);
    }
}
