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
        'ai.crm_call_summary' => [1, true, 'Call notes from a call transcript'],
        'ai.appointments_coaching' => [2, false, 'Appointment notes and coaching'],
        'ai.crm_ai_responder' => [1, true, 'AI first responder message'],
        'ai.crm_nurture' => [1, true, 'AI long follow-up message'],
        'ai.assistant' => [1, false, 'Your AI Assistant step'],
        'ai.forms_summary' => [3, false, 'Forms packet summary'],
        'ai.recruit_note' => [1, false, 'Personal recruiting note draft'],
        'ai.cma_summary' => [1, false, 'CMA pricing summary'],
        'ai.listing_description' => [2, false, 'List-IQ listing descriptions'],
        'ai.ad_compliance' => [1, false, 'Marketing check AI rewrite'],
        'ai.plan_reader' => [3, false, 'Commission plan set up from a document'],
        'listiq.staging' => [10, false, 'Virtual staging, per photo'],
        'listiq.video' => [5, false, 'Listing video from List-IQ photos'],
        'ai.site_journey' => [1, true, 'Visitor journey summary'],
        'ai.site_post' => [20, false, 'AI blog post draft'],
        'ai.site_page' => [15, false, 'AI page draft'],
        'ai.site_assist' => [2, false, 'AI editing help'],
        'ai.site_market' => [20, true, 'Monthly market update draft'],
        'ai.doc_read' => [2, true, 'DocIQ document reading'],
        'ai.doc_ask' => [1, false, 'DocIQ question'],
        'ai.doc_explain' => [2, false, 'DocIQ plain-English or Spanish explanation'],
        'ai.smart_fields' => [2, false, 'Form builder: name the fields found on a PDF'],
        'ai.course_builder' => [5, false, 'Learn-IQ: a course drafted by AI'],
        'ai.roleplay' => [1, false, 'Role-play: one reply from the practice client'],
        'ai.roleplay_feedback' => [2, false, 'Role-play: feedback on a practice conversation'],
        'ai.coach' => [2, false, 'Coaching card from call notes or a deal debrief'],
        'ai.game_coach' => [1, false, 'Game plan: the daily coach'],
        'ai.checkin_draft' => [1, true, 'Database assistant: a check-in email draft'],
        'ai.checkin_reply' => [1, true, 'Database assistant: reading a reply'],
        'ai.setup_map' => [1, false, 'Setup: matching the columns of a file from another system'],
        'ai.setup_describe' => [1, false, 'Setup: reading the broker\'s description into setup answers'],
        'ai.network_match' => [1, false, 'Networking assistant: reading what the client needs'],
        'ai.network_intro' => [1, false, 'Networking assistant: an introduction draft to another agent'],
        'ai.brand_guide' => [3, false, 'Brand guide: reading the brokerage\'s brand rules from its PDF'],
        'ai.compliance_rule' => [2, false, 'Deal-IQ rule'],
        'ai.ailly' => [1, true, 'AIlly assistant reply'],
        'ai.closing_brief' => [2, true, 'Closing brief on a website lead'],
        'ai.ads_copy' => [1, false, 'AI listing ad copy'],
        'ads.boost' => [1, false, 'RightAlly Boost ad spend (keep at 1: the quantity is the credits)'],
        'mail.postcards' => [1, false, 'Postcards, printed and mailed (keep at 1: the quantity is the credits)'],
        'apps.addon' => [1, false, 'Paid app add-on, a month (keep at 1: the quantity is the credits)'],
        // Ally Studio beyond a Studio package's monthly allowance (specs/grow/13).
        'ai.studio_caption' => [0, true, 'Ally Studio caption (included in each post)'],
        'studio.post' => [10, false, 'Ally Studio designed post beyond the allowance'],
        'studio.reel' => [60, false, 'Ally Studio Reel or Short beyond the allowance'],
        'studio.tour' => [120, false, 'Ally Studio YouTube listing tour beyond the allowance'],
        'studio.enhance' => [40, false, 'Ally Studio photo enhancement beyond the allowance'],
        'studio.voice' => [25, false, 'Ally Studio voice narration beyond the allowance'],
        'ai.movewise' => [0, true, 'Movewise AIlly reply (HQ pays)'],
        // Movewise introductions (specs/movewise/04): charged as the state's price in credits, one credit per unit.
        'movewise.connection' => [1, false, 'Movewise introduction, charged when the agent accepts (the state\'s price, in credits)'],
        // Property data (specs/connect/21): one lookup each, kept for 30 days.
        'data.property' => [40, false, 'Property facts, last sale and value range (ATTOM), per address'],
        'data.owner' => [15, false, 'Owner names and mailing address (BatchData), per address'],
        // Beyond a package's monthly allowances (specs/billing/03).
        'usage.text_segment' => [4, true, 'Text beyond the allowance (per segment)'],
        'usage.call_minute' => [4, true, 'Call minute beyond the allowance'],
        'usage.map_views_10' => [13, true, 'Website map views beyond the allowance (per 10)'],
    ];

    /**
     * Planning size of one use until HQ sets its own or real data exists: [tokens in, tokens out, audio seconds].
     * Generous on purpose (they include retries such as the SEO rewrite on drafts).
     */
    public const DEFAULT_ESTIMATES = [
        'ai.ads_copy' => [1500, 300, 0],
        'ai.recruit_note' => [1200, 300, 0],
        'ai.cma_summary' => [2500, 400, 0],
        'ai.listing_description' => [1500, 1200, 0],
        'ai.ad_compliance' => [1500, 600, 0],
        'ai.plan_reader' => [8000, 1500, 0],
        'ai.crm_brief' => [3000, 500, 0],
        'ai.crm_ask' => [2000, 300, 0],
        'ai.voice' => [0, 0, 30],
        'ai.crm_voice_log' => [800, 300, 0],
        'ai.crm_quick_add' => [600, 200, 0],
        'ai.crm_intents' => [800, 150, 0],
        'ai.crm_lead_email' => [2000, 300, 0],
        'ai.crm_reply_draft' => [2000, 300, 0],
        'ai.crm_call_summary' => [3000, 300, 0],
        'ai.appointments_coaching' => [9000, 800, 0],
        'ai.crm_ai_responder' => [2500, 300, 0],
        'ai.crm_nurture' => [2000, 250, 0],
        'ai.assistant' => [4000, 300, 0],
        'ai.forms_summary' => [8000, 800, 0],
        'ai.site_journey' => [1500, 300, 0],
        'ai.site_post' => [6000, 4000, 0],
        'ai.site_page' => [5000, 3000, 0],
        'ai.site_assist' => [1500, 600, 0],
        'ai.site_market' => [6000, 4000, 0],
        'ai.doc_read' => [8000, 600, 0],
        'ai.doc_ask' => [6000, 300, 0],
        'ai.doc_explain' => [9000, 900, 0],
        'ai.smart_fields' => [3000, 2500, 0],
        'ai.course_builder' => [6000, 3500, 0],
        'ai.roleplay' => [2500, 150, 0],
        'ai.roleplay_feedback' => [4000, 700, 0],
        'ai.coach' => [3000, 600, 0],
        'ai.game_coach' => [800, 300, 0],
        'ai.checkin_draft' => [600, 300, 0],
        'ai.checkin_reply' => [1500, 500, 0],
        'ai.setup_map' => [800, 400, 0],
        'ai.setup_describe' => [1200, 400, 0],
        'ai.network_match' => [800, 200, 0],
        'ai.network_intro' => [1000, 300, 0],
        'ai.brand_guide' => [9000, 500, 0],
        'ai.compliance_rule' => [1500, 400, 0],
        'ai.ailly' => [3000, 300, 0],
        'ai.closing_brief' => [5000, 900, 0],
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

    /**
     * Planning size of one use of each action (HQ's numbers over the defaults).
     *
     * @return array<string, array{tokens_in: int, tokens_out: int, audio_seconds: int}>
     */
    public function estimates(): array
    {
        $out = [];
        foreach (array_keys($this->all()) as $key) {
            [$in, $outTokens, $audio] = self::DEFAULT_ESTIMATES[$key] ?? [2000, 500, 0];
            $out[$key] = ['tokens_in' => $in, 'tokens_out' => $outTokens, 'audio_seconds' => $audio];
        }
        foreach (DB::connection(Connections::control())->table('credit_rate_card')->get(['action_key', 'est_tokens_in', 'est_tokens_out', 'est_audio_seconds']) as $row) {
            $key = (string) $row->action_key;
            if (isset($out[$key]) && $row->est_tokens_in !== null) {
                $out[$key] = ['tokens_in' => (int) $row->est_tokens_in, 'tokens_out' => (int) $row->est_tokens_out, 'audio_seconds' => (int) $row->est_audio_seconds];
            }
        }

        return $out;
    }

    public function saveEstimate(string $actionKey, int $tokensIn, int $tokensOut, int $audioSeconds, User $by): void
    {
        $rate = $this->rate($actionKey);
        DB::connection(Connections::control())->table('credit_rate_card')->updateOrInsert(['action_key' => $actionKey],
            ['label' => $rate['label'], 'credits' => $rate['credits'], 'automation' => $rate['automation'], 'active' => true, 'est_tokens_in' => $tokensIn,
                'est_tokens_out' => $tokensOut, 'est_audio_seconds' => $audioSeconds, 'updated_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        $this->cache = null;
        app(AuditLogger::class)->record('billing.credits.estimate_changed', metadata: ['action' => $actionKey, 'in' => $tokensIn, 'out' => $tokensOut, 'audio' => $audioSeconds]);
    }

    /**
     * HQ's margin rules: the margin prices aim for, the lowest acceptable, and the share of a package's price
     * that included usage may cost.
     *
     * @return array{target: int, floor: int, usage_budget: int}
     */
    public function margins(): array
    {
        $row = DB::connection(Connections::control())->table('credit_settings')->first(['target_margin_pct', 'floor_margin_pct', 'usage_budget_pct']);

        return ['target' => (int) ($row->target_margin_pct ?? 60), 'floor' => (int) ($row->floor_margin_pct ?? 40), 'usage_budget' => (int) ($row->usage_budget_pct ?? 20)];
    }

    public function saveMargins(int $target, int $floor, int $usageBudget, User $by): void
    {
        if ($floor > $target || $target > 95 || $floor < 0 || $usageBudget < 1 || $usageBudget > 90) {
            throw ValidationException::withMessages(['target' => 'The minimum margin must be at most the target (target up to 95%), and the usage budget 1–90%.']);
        }
        $old = $this->margins();
        $values = ['target_margin_pct' => $target, 'floor_margin_pct' => $floor, 'usage_budget_pct' => $usageBudget, 'updated_by' => $by->id, 'updated_at' => now()];
        $db = DB::connection(Connections::control())->table('credit_settings');
        $db->exists() ? $db->update($values) : $db->insert($values + ['created_at' => now()]);
        app(AuditLogger::class)->record('billing.credits.margins_changed', changes: ['margins' => ['old' => $old, 'new' => ['target' => $target, 'floor' => $floor, 'usage_budget' => $usageBudget]]],
            severity: 'notice');
    }

    /** HQ is emailed once a month when all websites together pass this many map views (nothing pauses). */
    public function mapsAlertViews(): int
    {
        return (int) (DB::connection(Connections::control())->table('credit_settings')->value('maps_alert_views') ?? 60_000);
    }

    public function saveMapsAlertViews(int $views, User $by): void
    {
        $values = ['maps_alert_views' => max(1000, min($views, 100_000_000)), 'updated_by' => $by->id, 'updated_at' => now()];
        $db = DB::connection(Connections::control())->table('credit_settings');
        $db->exists() ? $db->update($values) : $db->insert($values + ['created_at' => now()]);
    }
}
