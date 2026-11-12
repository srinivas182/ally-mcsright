<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Weekly security digest for owners (specs/core/04, addition 6). Payout and bank-detail changes are
 * always listed first, even when there are none.
 */
final class SecurityDigest
{
    /**
     * @return array{sections: list<array{title: string, lines: list<string>}>, quiet: bool}
     */
    public function build(CarbonImmutable $since): array
    {
        /** @var Collection<int, AuditEvent> $events */
        $events = AuditEvent::query()->where('occurred_at', '>=', $since->format('Y-m-d H:i:s.u'))->orderBy('chain_seq')->get();
        $activity = app(AuditActivity::class);
        $pick = fn (callable $filter): array => array_map(fn (array $l): string => $l['sentence'].' · '.CarbonImmutable::parse($l['at'])->toDayDateTimeString(),
            $activity->lines($events->filter($filter)->sortByDesc('chain_seq')->values()));

        $payout = $pick(fn (AuditEvent $e): bool => str_starts_with($e->action, 'finance.payout_details.') || str_starts_with($e->action, 'finance.bank_details.'));
        $sections = [
            ['title' => 'Payout and bank-detail changes', 'lines' => $payout === [] ? ['No payout or bank-detail changes this week.'] : $payout],
            ['title' => 'New-device sign-ins', 'lines' => $pick(fn (AuditEvent $e): bool => $e->action === 'core.auth.new_device')],
            ['title' => '"Log in as" sessions', 'lines' => $pick(fn (AuditEvent $e): bool => $e->action === 'core.impersonation.started')],
            ['title' => 'People added, removed or changed', 'lines' => $pick(fn (AuditEvent $e): bool => $e->action === 'core.user.created' || ($e->action === 'core.user.updated' && (isset($e->changes['kind']) || isset($e->changes['status']))))],
            ['title' => 'Failed sign-ins and lockouts', 'lines' => $pick(fn (AuditEvent $e): bool => in_array($e->action, ['core.auth.sign_in_failed', 'core.auth.locked_out'], true))],
            ['title' => 'Deleted contacts and legal holds', 'lines' => $pick(fn (AuditEvent $e): bool => $e->action === 'crm.contact.deleted' || str_starts_with($e->action, 'core.audit.legal_hold'))],
        ];

        $quiet = $payout === [] && collect($sections)->slice(1)->every(fn (array $s): bool => $s['lines'] === []);

        return ['sections' => array_values(array_filter($sections, fn (array $s, int $i): bool => $i === 0 || $s['lines'] !== [], ARRAY_FILTER_USE_BOTH)), 'quiet' => $quiet];
    }
}
