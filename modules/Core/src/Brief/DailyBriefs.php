<?php

declare(strict_types=1);

namespace Modules\Core\Brief;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The daily brief across the whole business (specs/core/09): one ranked list each morning for agents and for
 * brokers, built from every module's own rules (no AI needed). Each item says why it matters and links to its
 * next step. Modules add sources; a source that fails is left out, never the brief.
 *
 * Ranking: what is overdue or due today first, then this week, then good to know; within each, the most
 * commission at stake first.
 */
final class DailyBriefs
{
    /** @var array<string, \Closure(User): list<array<string, mixed>>> */
    private array $sources = [];

    /**
     * A source returns items: key (unique), title, why, action (button label), url, urgency (3 now, 2 this week,
     * 1 good to know) and stakes_cents (commission at stake, or null). Use the same access rules as the screens.
     *
     * @param  \Closure(User): list<array<string, mixed>>  $source
     */
    public function add(string $name, \Closure $source): void
    {
        $this->sources[$name] = $source;
    }

    /**
     * @return list<array{key: string, source: string, title: string, why: string, action: string, url: string, urgency: int, stakes_cents: int|null}>
     */
    public function for(User $user, int $limit = 25): array
    {
        $items = [];
        foreach ($this->sources as $name => $source) {
            try {
                foreach ($source($user) as $i) {
                    $items[] = ['key' => (string) ($i['key'] ?? $name.':'.count($items)), 'source' => $name, 'title' => (string) ($i['title'] ?? ''), 'why' => (string) ($i['why'] ?? ''),
                        'action' => (string) ($i['action'] ?? 'Open'), 'url' => (string) ($i['url'] ?? '/'), 'urgency' => max(1, min(3, (int) ($i['urgency'] ?? 1))),
                        'stakes_cents' => isset($i['stakes_cents']) && is_numeric($i['stakes_cents']) && (int) $i['stakes_cents'] > 0 ? (int) $i['stakes_cents'] : null];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        usort($items, fn (array $a, array $b): int => [$b['urgency'], $b['stakes_cents'] ?? 0, $a['title']] <=> [$a['urgency'], $a['stakes_cents'] ?? 0, $b['title']]);

        return array_slice($items, 0, $limit);
    }

    public static function manager(User $user): bool
    {
        return in_array($user->kind, [UserKind::Owner, UserKind::Staff], true);
    }

    /** Whether this person gets the morning email (on unless they turned it off). */
    public function emailOn(User $user): bool
    {
        $v = DB::connection(Connections::cell())->table('core_brief_prefs')->where('tenant_id', app(TenantContext::class)->id())->where('user_id', $user->id)->value('email');

        return $v === null || (bool) $v;
    }

    public function setEmail(User $user, bool $on): void
    {
        DB::connection(Connections::cell())->table('core_brief_prefs')->updateOrInsert(['tenant_id' => app(TenantContext::class)->id(), 'user_id' => $user->id],
            ['email' => $on, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Sends the morning email to everyone whose 7am it is (their own time zone, else the brokerage's). Returns emails sent. */
    public function sendDue(): int
    {
        $tenants = app(TenantContext::class);
        $brokerTz = (string) ($tenants->current()->getAttribute('timezone') ?: 'America/Chicago');
        $db = DB::connection(Connections::cell())->table('core_brief_prefs');
        $sent = 0;
        foreach (User::query()->where('status', 'active')->whereIn('kind', [UserKind::Owner, UserKind::Staff, UserKind::Agent])->get() as $user) {
            $local = CarbonImmutable::now($user->timezone !== null && $user->timezone !== '' ? $user->timezone : $brokerTz);
            if ($local->hour < 7 || $local->isWeekend() || ! $this->emailOn($user)) {
                continue;
            }
            $row = (clone $db)->where('tenant_id', $tenants->id())->where('user_id', $user->id)->first();
            if ($row !== null && (string) ($row->last_sent_on ?? '') === $local->toDateString()) {
                continue;
            }
            $items = $this->for($user, 10);
            (clone $db)->updateOrInsert(['tenant_id' => $tenants->id(), 'user_id' => $user->id], ['last_sent_on' => $local->toDateString(), 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
            if ($items === []) {
                continue; // nothing needs them: no email
            }
            $user->notify(new DailyBriefMail($items, $user->first_name, (string) $tenants->current()->name, self::manager($user) ? '/dashboard' : '/agent'));
            $sent++;
        }

        return $sent;
    }
}
