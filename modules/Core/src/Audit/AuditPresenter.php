<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

/**
 * Short readable lines for audit events (the full plain-English activity view comes in part 2).
 */
final class AuditPresenter
{
    private const VERBS = [
        'core.auth.signed_in' => 'signed in',
        'core.auth.sign_in_failed' => 'failed sign-in',
        'core.auth.locked_out' => 'locked out after failed sign-ins',
        'core.impersonation.started' => 'started viewing as',
        'core.impersonation.stopped' => 'stopped viewing as',
        'core.user.created' => 'added user',
        'core.user.updated' => 'changed user',
        'crm.contact.created' => 'added contact',
        'crm.contact.updated' => 'changed contact',
        'crm.contact.deleted' => 'deleted contact',
        'crm.contact.viewed' => 'viewed contact',
        'crm.task.created' => 'created task',
        'crm.task.updated' => 'changed task',
        'crm.task.deleted' => 'deleted task',
    ];

    private const AUTOMATIONS = [
        'lead_pond' => 'Lead pond', 'follow_up_plan' => 'Follow-up plan', 'nightly_intelligence' => 'Nightly intelligence', 'ai_assistant' => 'AI assistant',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function row(AuditEvent $e): array
    {
        $who = $e->automation !== null
            ? (self::AUTOMATIONS[$e->automation] ?? $e->automation).' (automatic)'
            : ($e->actor_name ?? ($e->actor_type === 'system' ? 'System' : 'Someone'));
        if ($e->impersonator_name !== null) {
            $who .= ' — by '.$e->impersonator_name;
        }

        $verb = self::VERBS[$e->action] ?? (str_starts_with($e->action, 'crm.consent.') ? 'changed consent ('.str_replace(['crm.consent.', '_'], ['', ' '], $e->action).')' : $e->action);

        return [
            'ulid' => $e->ulid, 'at' => $e->occurred_at->toIso8601String(), 'who' => $who, 'what' => $verb,
            'subject' => $e->subject_label, 'subject_type' => $e->subject_type, 'severity' => $e->severity,
            'fields' => $e->changes !== null ? array_keys($e->changes) : [], 'changes' => $e->changes, 'metadata' => $e->metadata,
            'ip' => $e->ip, 'action' => $e->action, 'seq' => $e->chain_seq,
        ];
    }
}
