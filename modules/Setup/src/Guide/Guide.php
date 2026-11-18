<?php

declare(strict_types=1);

namespace Modules\Setup\Guide;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\PlanVersion;
use Modules\Compensation\PlanReader;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Identity\Enums\UserStatus;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Leads\AiResponder;
use Modules\Crm\Leads\LeadRouting;
use Modules\Crm\Models\RoutingRule;
use Modules\Sites\Models\Site;
use Modules\Sites\SiteSetup;
use Throwable;

/**
 * The guided setup (SU2, specs/setup/02): a few plain questions, or the broker's own words read by AI, and RightAlly
 * sets itself up: a draft commission plan (published by the owner after checking it), how new leads are given out,
 * whether the assistant answers new leads straight away, and a website in a chosen style. Every answer can be
 * changed; nothing about money is switched on without the owner publishing it.
 */
final class Guide
{
    public const FEATURE = 'setup.describe';

    public const STYLES = ['light' => 'modern', 'dark' => 'aurum', 'warm' => 'hearth'];

    public const ROUTING = ['round_robin' => 'In turn, to each agent', 'first_claim' => 'To whoever claims it first', 'owner' => 'To me'];

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array{answers: array<string, mixed>, applied: array<string, string>} */
    public function state(): array
    {
        $row = DB::connection(Connections::cell())->table('setup_guide')->where('tenant_id', $this->tenants->id())->first();

        return ['answers' => (array) json_decode((string) ($row->answers ?? '{}'), true), 'applied' => (array) json_decode((string) ($row->applied ?? '{}'), true)];
    }

    /**
     * The broker's own words, read by AI into answers for them to check (nothing is set up yet).
     *
     * @return array{answers: array<string, mixed>, unclear: list<string>}
     */
    public function describe(string $words, User $by): array
    {
        $words = trim($words);
        if (mb_strlen($words) < 20) {
            throw ValidationException::withMessages(['words' => 'Tell us a little more: how you pay agents, who gets new leads, and the look you want.']);
        }
        try {
            $j = (array) app(AiGateway::class)->run(new AiTask(self::FEATURE, 'setup.describe', 'setup-describe:'.$by->id.':'.now()->format('YmdHisv'),
                inputs: ['brokerage' => $this->tenants->current()->name], untrustedContent: [mb_substr($words, 0, 4000)],
                outputSchema: ['type' => 'object', 'required' => ['unclear'], 'properties' => ['unclear' => ['type' => 'array']]], userId: $by->id))->json;
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['words' => $e->getMessage()]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['words' => 'We couldn\'t read that just now. Please answer the questions below instead.']);
        }
        $num = fn (mixed $v): ?float => is_numeric($v) && (float) $v >= 0 ? (float) $v : null;
        $answers = array_filter([
            'pay_mode' => in_array($j['pay_mode'] ?? null, ['split', 'flat'], true) ? $j['pay_mode'] : null,
            'agent_percent' => ($p = $num($j['agent_percent'] ?? null)) !== null && $p <= 100 ? $p : null,
            'cap_dollars' => $num($j['cap_dollars'] ?? null),
            'fee_dollars' => $num($j['fee_dollars'] ?? null),
            'flat_fee_dollars' => $num($j['flat_fee_dollars'] ?? null),
            'lead_routing' => array_key_exists((string) ($j['lead_routing'] ?? ''), self::ROUTING) ? $j['lead_routing'] : null,
            'ai_first_reply' => is_bool($j['ai_first_reply'] ?? null) ? $j['ai_first_reply'] : null,
            'website_style' => array_key_exists((string) ($j['website_style'] ?? ''), self::STYLES) ? $j['website_style'] : null,
        ], fn ($v): bool => $v !== null);

        return ['answers' => $answers, 'unclear' => array_values(array_map(fn ($u): string => mb_substr((string) $u, 0, 200), array_filter((array) ($j['unclear'] ?? []), 'is_string')))];
    }

    /**
     * Saves the answers and sets up what they describe. Returns what was set up, in plain words.
     *
     * @param  array<string, mixed>  $a
     * @return array<string, string>
     */
    public function apply(array $a, User $by): array
    {
        $done = $this->state()['applied'];
        if (($a['pay_mode'] ?? null) !== null) {
            $done['pay'] = $this->pay($a, $by);
        }
        if (($a['lead_routing'] ?? null) !== null) {
            $done['leads'] = $this->leads((string) $a['lead_routing'], $by);
        }
        if (($a['ai_first_reply'] ?? null) !== null) {
            $responder = app(AiResponder::class);
            $responder->saveSettings(['enabled' => (bool) $a['ai_first_reply']] + $responder->settings(), $by);
            $done['first_reply'] = (bool) $a['ai_first_reply'] ? 'The assistant answers new leads within a minute.' : 'New leads are answered by your agents.';
        }
        if (($a['website_style'] ?? null) !== null) {
            $done['website'] = $this->website((string) $a['website_style'], $by);
        }
        DB::connection(Connections::cell())->table('setup_guide')->updateOrInsert(['tenant_id' => $this->tenants->id()],
            ['answers' => json_encode(array_merge($this->state()['answers'], $a)), 'applied' => json_encode($done), 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('setup.guide.applied', metadata: ['answers' => array_keys($a)], severity: 'notice');

        return $done;
    }

    /** @param  array<string, mixed>  $a */
    private function pay(array $a, User $by): string
    {
        $flat = $a['pay_mode'] === 'flat';
        [$rules] = PlanReader::rules([
            'agent_percent' => $flat ? null : ($a['agent_percent'] ?? null),
            'flat_fee_dollars' => $flat ? ($a['flat_fee_dollars'] ?? null) : null,
            'cap_dollars' => $flat ? null : ($a['cap_dollars'] ?? null),
            'post_split' => ! $flat && is_numeric($a['fee_dollars'] ?? null) && (float) $a['fee_dollars'] > 0
                ? [['name' => 'Transaction fee', 'kind' => 'flat', 'amount' => (float) $a['fee_dollars'], 'payable_to' => 'brokerage']] : [],
        ]);
        $name = $flat ? 'Flat fee $'.number_format((float) ($a['flat_fee_dollars'] ?? 0)).' (from setup)' : (int) ($a['agent_percent'] ?? 100).'/'.(100 - (int) ($a['agent_percent'] ?? 100)).' plan (from setup)';
        try {
            $plan = app(ManagePlans::class)->create(PlanType::Commission, mb_substr($name, 0, 70), $rules, $by->id, 'Drafted by the guided setup. Check it, then publish it.');
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['agent_percent' => (string) collect($e->errors())->flatten()->first()]);
        }

        return "A draft commission plan, \"{$plan->name}\", is ready for you to check and publish in Compensation › Plans. Nothing is paid on it until you publish it.";
    }

    private function leads(string $routing, User $by): string
    {
        $agents = User::query()->where('kind', UserKind::Agent)->where('status', UserStatus::Active)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($routing !== 'owner' && $agents === []) {
            return 'Lead routing is waiting for your agents: invite them first, then answer this again.';
        }
        $rule = RoutingRule::query()->where('name', 'New leads (from setup)')->first();
        app(LeadRouting::class)->saveRule(['name' => 'New leads (from setup)', 'method' => $routing === 'owner' ? 'agent' : $routing,
            'agent_ids' => $routing === 'owner' ? [$by->id] : $agents, 'intent' => 'any', 'active' => true], $by, $rule);

        return 'New company leads go '.mb_strtolower(self::ROUTING[$routing]).'.';
    }

    private function website(string $style, User $by): string
    {
        $template = self::STYLES[$style];
        $site = Site::query()->where('kind', 'brokerage')->first();
        if ($site !== null) {
            return 'Your website already exists; change its look in Settings › Website.';
        }
        $tenant = $this->tenants->current();
        app(SiteSetup::class)->create($template, ['name' => $tenant->name], $by);

        return 'Your website is drafted in the '.ucfirst($template).' style. Review it in Settings › Website, then publish it.';
    }

    /**
     * How ready the brokerage is to go live, step by step, from its own data.
     *
     * @return array{score: int, steps: list<array{key: string, label: string, done: bool, href: string}>}
     */
    public function readiness(): array
    {
        $db = DB::connection(Connections::cell());
        $tenant = $this->tenants->id();
        $steps = [
            ['key' => 'plan', 'label' => 'A commission plan is published', 'done' => PlanVersion::query()->whereNotNull('published_at')->exists(), 'href' => '/compensation/plans'],
            ['key' => 'people', 'label' => 'Your people are brought in', 'done' => $db->table('crm_contacts')->where('tenant_id', $tenant)->exists(), 'href' => '/setup/import'],
            ['key' => 'agents', 'label' => 'Your agents are invited', 'done' => User::query()->where('kind', UserKind::Agent)->exists()
                || $db->table('invitations')->where('tenant_id', $tenant)->where('kind', 'agent')->exists(), 'href' => '/setup/import'],
            ['key' => 'leads', 'label' => 'New leads have somewhere to go', 'done' => RoutingRule::query()->where('active', true)->exists(), 'href' => '/leads/settings'],
            ['key' => 'website', 'label' => 'Your website is live', 'done' => Site::query()->where('kind', 'brokerage')->where('status', 'live')->exists(), 'href' => '/settings/website'],
            ['key' => 'deal', 'label' => 'Your first deal is in RightAlly', 'done' => $db->table('tx_deals')->where('tenant_id', $tenant)->exists(), 'href' => '/deals'],
        ];
        $done = count(array_filter($steps, fn (array $s): bool => $s['done']));

        return ['score' => (int) round(100 * $done / count($steps)), 'steps' => $steps];
    }
}
