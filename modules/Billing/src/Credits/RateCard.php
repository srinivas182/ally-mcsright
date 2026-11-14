<?php

declare(strict_types=1);

namespace Modules\Billing\Credits;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AI\FeatureCatalog;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * HQ's RightAlly Credits price list (specs/billing/01): what one credit is worth and how many credits each action
 * costs. Actions without a row use the defaults below; actions HQ marks as automations (the assistant, the AI first
 * responder, summaries) always draw from the brokerage pool and are reported separately.
 */
final class RateCard
{
    /** Defaults until HQ sets a price: [credits, automation, label]. */
    public const DEFAULTS = [
        'ai.crm_brief' => [2, false, 'AI contact brief'],
        'ai.crm_ask' => [1, false, 'Ask your database'],
        'ai.voice' => [1, false, 'Speech to text'],
        'ai.crm_voice_log' => [1, false, 'Spoken contact update'],
        'ai.crm_quick_add' => [1, false, 'Spoken quick add'],
        'ai.crm_intents' => [1, true, 'Reading plans and dates from notes'],
        'ai.crm_lead_email' => [1, true, 'Reading forwarded lead emails'],
        'ai.crm_reply_draft' => [1, false, 'Suggested inbox reply'],
        'ai.crm_ai_responder' => [1, true, 'AI first responder message'],
        'ai.forms_summary' => [3, false, 'Forms packet summary'],
        'ai.site_journey' => [1, true, 'Visitor journey summary'],
        'ai.site_post' => [20, false, 'AI blog post draft'],
        'ai.site_page' => [15, false, 'AI page draft'],
        'ai.site_assist' => [2, false, 'AI editing help'],
        'ai.site_market' => [20, true, 'Monthly market update draft'],
        'ai.doc_read' => [2, true, 'DocIQ document reading'],
        'ai.doc_ask' => [1, false, 'DocIQ question'],
        'ai.compliance_rule' => [2, false, 'Deal-IQ rule'],
        'ai.ailly' => [1, true, 'AIlly assistant reply'],
    ];

    /** @var array<string, array{credits: int, automation: bool, label: string}>|null */
    private ?array $cache = null;

    /** What one credit is worth to the customer, in millionths of a cent. */
    public function creditValueMicrocents(): int
    {
        return (int) (DB::connection(Connections::control())->table('credit_settings')->value('credit_value_microcents') ?? 1_000_000);
    }

    /**
     * @return array{credits: int, automation: bool, label: string}
     */
    public function rate(string $actionKey): array
    {
        return $this->all()[$actionKey] ?? ['credits' => 1, 'automation' => false, 'label' => $actionKey];
    }

    /**
     * Every priced action: HQ rows over defaults, plus any declared AI feature.
     *
     * @return array<string, array{credits: int, automation: bool, label: string}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        foreach (self::DEFAULTS as $key => [$credits, $automation, $label]) {
            $out[$key] = ['credits' => $credits, 'automation' => $automation, 'label' => $label];
        }
        foreach (app(FeatureCatalog::class)->all() as $feature) {
            $out[$feature['credit_action']] ??= ['credits' => 1, 'automation' => false, 'label' => $feature['description']];
        }
        foreach (DB::connection(Connections::control())->table('credit_rate_card')->where('active', true)->get() as $row) {
            $out[(string) $row->action_key] = ['credits' => (int) $row->credits, 'automation' => (bool) $row->automation, 'label' => (string) $row->label];
        }
        ksort($out);

        return $this->cache = $out;
    }

    public function saveRate(string $actionKey, int $credits, bool $automation, string $label, User $by): void
    {
        if (preg_match('/^[a-z0-9_.]{2,80}$/', $actionKey) !== 1 || $credits < 0 || $credits > 100_000) {
            throw ValidationException::withMessages(['credits' => 'Use 0 to 100,000 credits.']);
        }
        $old = $this->rate($actionKey);
        DB::connection(Connections::control())->table('credit_rate_card')->updateOrInsert(['action_key' => $actionKey],
            ['label' => mb_substr(trim($label), 0, 120) ?: $old['label'], 'credits' => $credits, 'automation' => $automation, 'active' => true, 'updated_by' => $by->id,
                'updated_at' => now(), 'created_at' => now()]);
        $this->cache = null;
        app(AuditLogger::class)->record('billing.credits.rate_changed', changes: ['credits' => ['old' => $old['credits'], 'new' => $credits]],
            metadata: ['action' => $actionKey, 'automation' => $automation], severity: 'notice');
    }

    public function saveCreditValue(int $microcents, User $by): void
    {
        if ($microcents < 1000 || $microcents > 1_000_000_000) {
            throw ValidationException::withMessages(['credit_value' => 'Set a value between $0.00001 and $10 per credit.']);
        }
        $db = DB::connection(Connections::control())->table('credit_settings');
        $old = $this->creditValueMicrocents();
        $db->exists() ? $db->update(['credit_value_microcents' => $microcents, 'updated_by' => $by->id, 'updated_at' => now()])
            : $db->insert(['credit_value_microcents' => $microcents, 'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.credits.value_changed', changes: ['credit_value_microcents' => ['old' => $old, 'new' => $microcents]], severity: 'notice');
    }

    /** Whether running out of credits blocks AI actions (off during rollout: usage is still recorded and shown). */
    public function enforced(): bool
    {
        return (bool) (DB::connection(Connections::control())->table('credit_settings')->value('enforce') ?? false);
    }

    public function saveEnforced(bool $enforce, User $by): void
    {
        $db = DB::connection(Connections::control())->table('credit_settings');
        $old = $this->enforced();
        $db->exists() ? $db->update(['enforce' => $enforce, 'updated_by' => $by->id, 'updated_at' => now()])
            : $db->insert(['enforce' => $enforce, 'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('billing.credits.enforcement', changes: ['enforce' => ['old' => $old, 'new' => $enforce]], severity: 'warning');
    }
}
