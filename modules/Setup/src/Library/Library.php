<?php

declare(strict_types=1);

namespace Modules\Setup\Library;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Plan;
use Modules\Compensation\PlanReader;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Inbox\Templates;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\PlanStep;
use Modules\Crm\Models\Template;

/**
 * Installs and updates ready-made items (SU3, specs/setup/03). Each install remembers a fingerprint of what was put
 * in. An update is offered only when RightAlly has a newer version AND the brokerage hasn't changed its copy; a copy
 * someone changed is never overwritten (they can install the new version alongside). Commission plans are always
 * installed as drafts and never updated in place: a newer version arrives as a new draft.
 */
final class Library
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Every item with its status for this brokerage.
     *
     * @return list<array{key: string, kind: string, title: string, description: string, language: string, version: int, status: string, preview: list<string>}>
     */
    public function items(): array
    {
        $installs = DB::connection(Connections::cell())->table('setup_library_installs')->where('tenant_id', $this->tenants->id())->get()->keyBy('item_key');
        $out = [];
        foreach (Catalog::ITEMS as $key => $item) {
            $i = $installs->get($key);
            $status = 'available';
            if ($i !== null) {
                $now = $this->fingerprint($item['kind'], (int) $i->local_id);
                $status = match (true) {
                    $now === null => 'removed',
                    $now !== (string) $i->content_hash => 'changed',
                    $item['version'] > (int) $i->version => 'update',
                    default => 'installed',
                };
            }
            $out[] = ['key' => $key, 'kind' => $item['kind'], 'title' => $item['title'], 'description' => $item['description'], 'language' => $item['language'],
                'version' => $item['version'], 'status' => $status, 'preview' => self::preview($item)];
        }

        return $out;
    }

    /** Installs an item (or, when RightAlly has a newer version, updates an unchanged copy). */
    public function install(string $key, User $by, bool $asCopy = false): string
    {
        $item = Catalog::ITEMS[$key] ?? throw ValidationException::withMessages(['item' => 'That item is not in the library.']);
        $db = DB::connection(Connections::cell());
        $i = $db->table('setup_library_installs')->where('tenant_id', $this->tenants->id())->where('item_key', $key)->first();
        $existing = null;
        if ($i !== null && ! $asCopy) {
            $now = $this->fingerprint($item['kind'], (int) $i->local_id);
            if ($now !== null && $now !== (string) $i->content_hash) {
                throw ValidationException::withMessages(['item' => 'You changed your copy, so we won\'t overwrite it. Install the new version as a copy instead.']);
            }
            if ($now !== null && $item['version'] <= (int) $i->version) {
                throw ValidationException::withMessages(['item' => 'You already have the latest version.']);
            }
            $existing = $now !== null && $item['kind'] !== 'plan' ? (int) $i->local_id : null;
        }
        $localId = match ($item['kind']) {
            'plan' => $this->plan($item, $by),
            'follow_up' => $this->followUp($item, $by, $existing),
            default => $this->message($item, $by, $existing),
        };
        $db->table('setup_library_installs')->updateOrInsert(['tenant_id' => $this->tenants->id(), 'item_key' => $key],
            ['version' => $item['version'], 'local_type' => $item['kind'], 'local_id' => $localId, 'content_hash' => (string) $this->fingerprint($item['kind'], $localId),
                'installed_by' => $by->id, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('setup.library.installed', metadata: ['item' => $key, 'version' => $item['version'], 'update' => $existing !== null], severity: 'info');

        return $item['kind'] === 'plan' ? "\"{$item['title']}\" is a draft plan in Compensation › Plans. Check it, then publish it."
            : ($existing !== null ? "\"{$item['title']}\" is updated." : "\"{$item['title']}\" is ready to use.");
    }

    /** @param  array{title: string, content: array<string, mixed>}  $item */
    private function plan(array $item, User $by): int
    {
        [$rules] = PlanReader::rules($item['content']);

        return app(ManagePlans::class)->create(PlanType::Commission, mb_substr($item['title'], 0, 70), $rules, $by->id, 'From the RightAlly library. Check it, then publish it.')->id;
    }

    /** @param  array{title: string, description: string, content: array<string, mixed>}  $item */
    private function followUp(array $item, User $by, ?int $existing): int
    {
        $plan = $existing !== null ? FollowUpPlan::query()->find($existing) : null;
        $steps = [];
        foreach ((array) $item['content']['steps'] as $s) {
            $steps[] = ['day' => (int) $s[0], 'type' => (string) $s[1], 'title' => (string) $s[2], 'message' => $s[3] !== null ? (string) $s[3] : null];
        }

        return app(FollowUpPlans::class)->save($plan, ['name' => $item['title'], 'description' => $item['description'], 'steps' => $steps, 'trigger' => 'manual', 'shared' => true], $by)->id;
    }

    /** @param  array{title: string, content: array<string, mixed>}  $item */
    private function message(array $item, User $by, ?int $existing): int
    {
        $template = $existing !== null ? Template::query()->find($existing) : null;

        return app(Templates::class)->save(['name' => $item['title'], 'channel' => $item['content']['channel'], 'subject' => $item['content']['subject'] ?? null,
            'body' => $item['content']['body'], 'shared' => true], $by, $template)->id;
    }

    /** A fingerprint of the brokerage's copy as it is now (null when it has been deleted). */
    private function fingerprint(string $kind, int $id): ?string
    {
        $data = match ($kind) {
            'plan' => ($p = Plan::query()->find($id)) !== null ? [$p->name, $p->versions()->orderBy('version')->first()?->rules] : null,
            'follow_up' => ($f = FollowUpPlan::query()->find($id)) !== null
                ? [$f->name, PlanStep::query()->where('plan_id', $id)->orderBy('sort')->get(['day', 'type', 'title', 'message'])->toArray()] : null,
            default => ($t = Template::query()->find($id)) !== null ? [$t->name, $t->channel, $t->subject, $t->body] : null,
        };

        return $data === null ? null : hash('sha256', (string) json_encode($data));
    }

    /**
     * What the item holds, in plain lines.
     *
     * @param  array{kind: string, content: array<string, mixed>}  $item
     * @return list<string>
     */
    private static function preview(array $item): array
    {
        $c = $item['content'];

        return match ($item['kind']) {
            'plan' => array_values(array_filter([
                isset($c['agent_percent']) ? 'Agent keeps '.$c['agent_percent'].'%' : null,
                isset($c['flat_fee_dollars']) ? 'Agent pays $'.number_format((float) $c['flat_fee_dollars']).' per deal' : null,
                isset($c['cap_dollars']) ? 'Cap: $'.number_format((float) $c['cap_dollars']).' a year' : null,
                ...array_map(fn (array $f): string => $f['name'].': $'.number_format((float) $f['amount']).' per deal', (array) ($c['post_split'] ?? [])),
            ])),
            'follow_up' => array_values(array_map(fn (array $s): string => 'Day '.$s[0].': '.$s[2], (array) $c['steps'])),
            default => array_values(array_filter([isset($c['subject']) ? 'Subject: '.$c['subject'] : null, (string) $c['body']])),
        };
    }
}
