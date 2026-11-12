<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Plain-English activity (specs/core/04, addition 5): sentences, not codes; repeated actions grouped.
 */
final class AuditActivity
{
    /** Always shown with "Important only". */
    public const IMPORTANT_PREFIXES = ['core.auth.locked_out', 'core.auth.new_device', 'core.impersonation.', 'core.user.created', 'core.user.deleted',
        'core.audit.', 'crm.contact.deleted', 'crm.consent.', 'finance.payout_details.', 'finance.bank_details.', 'export.'];

    /** @var array<int, string> */
    private array $names = [];

    /**
     * @param  Collection<int, AuditEvent>  $events  newest first
     * @return list<array{sentence: string, at: string, count: int, important: bool, items: list<array<string, mixed>>}>
     */
    public function lines(Collection $events): array
    {
        $ids = $events->flatMap(fn (AuditEvent $e): array => [(int) ($e->changes['owner_user_id']['old'] ?? 0), (int) ($e->changes['owner_user_id']['new'] ?? 0),
            (int) ($e->on_behalf_of_user_id ?? 0), (int) ($e->metadata['by_user_id'] ?? 0)])->filter()->unique();
        $this->names = User::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name])->all();

        $lines = [];
        $open = []; // group key => index of its line, while the group is within 10 minutes
        foreach ($events as $e) {
            $row = AuditPresenter::row($e);
            $key = $row['who'].'|'.$this->groupKey($e);
            $at = $e->occurred_at->getTimestamp();

            if ($e->subject_type !== null && isset($open[$key])) {
                $i = $open[$key];
                $newest = strtotime((string) $lines[$i]['items'][count($lines[$i]['items']) - 1]['at']);
                if (abs($newest - $at) <= 600) {
                    $lines[$i]['items'][] = $row;
                    $lines[$i]['count']++;

                    continue;
                }
            }

            $lines[] = ['key' => $key, 'sentence' => $this->sentence($e, $row), 'at' => $row['at'], 'count' => 1, 'important' => self::important($e), 'items' => [$row]];
            $open[$key] = count($lines) - 1;
        }

        $out = [];
        foreach ($lines as $line) {
            $first = $line['items'][0];
            $out[] = [
                'sentence' => $line['count'] > 1 ? $first['who'].' '.$this->plural((string) $first['action'], $line['count']) : (string) $line['sentence'],
                'at' => (string) $line['at'], 'count' => (int) $line['count'], 'important' => (bool) $line['important'],
                'items' => array_values($line['items']),
            ];
        }

        return $out;
    }

    public static function important(AuditEvent $e): bool
    {
        if (in_array($e->severity, ['warning', 'critical'], true)) {
            return true;
        }
        foreach (self::IMPORTANT_PREFIXES as $prefix) {
            if (str_starts_with($e->action, $prefix)) {
                return true;
            }
        }

        return $e->action === 'core.user.updated' && (isset($e->changes['kind']) || isset($e->changes['status']));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sentence(AuditEvent $e, array $row): string
    {
        $who = (string) $row['who'];
        $subject = $e->subject_label ?? 'a record';
        $c = $e->changes ?? [];

        if (isset($c['owner_user_id']) && $e->action === 'crm.contact.updated') {
            $to = $this->names[(int) ($c['owner_user_id']['new'] ?? 0)] ?? null;
            $from = $this->names[(int) ($c['owner_user_id']['old'] ?? 0)] ?? null;

            return match (true) {
                $to === null && $from !== null => "{$who} moved {$subject} from {$from} to the lead pond",
                $from === null && $to !== null => "{$who} gave {$subject} to {$to}",
                default => "{$who} reassigned {$subject} from {$from} to {$to}",
            };
        }
        if (isset($c['lifecycle']) && $e->action === 'crm.contact.updated') {
            return "{$who} moved {$subject} from ".str_replace('_', ' ', (string) $c['lifecycle']['old']).' to '.str_replace('_', ' ', (string) $c['lifecycle']['new']);
        }
        $for = $e->on_behalf_of_user_id !== null && isset($this->names[$e->on_behalf_of_user_id]) ? ' for '.$this->names[$e->on_behalf_of_user_id] : '';

        return match ($e->action) {
            'core.auth.signed_in' => "{$who} signed in",
            'core.auth.new_device' => "{$who} signed in from a new device (".($e->metadata['device'] ?? 'unknown').')',
            'core.auth.sign_in_failed' => 'Failed sign-in for '.($e->subject_label ?? 'an unknown email'),
            'core.auth.locked_out' => 'Sign-in locked after repeated failures',
            'core.impersonation.started' => ($this->names[(int) ($e->metadata['by_user_id'] ?? 0)] ?? $e->impersonator_name ?? 'Support').(($e->metadata['by_kind'] ?? '') === 'hq' ? ' (RightAlly support)' : '')." started viewing as {$subject}: ".($e->metadata['reason'] ?? ''),
            'core.impersonation.stopped' => ($this->names[(int) ($e->metadata['by_user_id'] ?? 0)] ?? $e->impersonator_name ?? 'Support')." stopped viewing as {$subject}",
            'core.audit.retention_purged' => 'Retention removed '.($e->metadata['count'] ?? 0).' event(s) older than '.($e->metadata['retention_years'] ?? '?').' years',
            'core.audit.legal_hold_placed' => "{$who} placed a legal hold: ".($e->metadata['reason'] ?? ''),
            'core.audit.legal_hold_released' => "{$who} released a legal hold",
            'core.audit.retention_changed' => "{$who} changed audit retention to ".($e->metadata['years'] ?? '?').' years',
            'crm.contact.evidence_pack' => "{$who} downloaded the evidence pack for {$subject}",
            default => "{$who} {$row['what']} {$subject}{$for}",
        };
    }

    private function groupKey(AuditEvent $e): string
    {
        $fields = $e->changes !== null ? implode(',', array_keys($e->changes)) : '';

        return $e->action.'|'.$fields;
    }

    private function plural(string $action, int $n): string
    {
        return match ($action) {
            'crm.contact.viewed' => "viewed {$n} contacts",
            'crm.contact.created' => "added {$n} contacts",
            'crm.contact.updated' => "changed {$n} contacts",
            'crm.contact.deleted' => "deleted {$n} contacts",
            'crm.task.created' => "created {$n} tasks",
            'crm.task.updated' => "changed {$n} tasks",
            'core.auth.sign_in_failed' => "had {$n} failed sign-ins",
            default => "did {$n} similar actions",
        };
    }
}
