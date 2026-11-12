<?php

declare(strict_types=1);

namespace Modules\Core\Audit;

/**
 * Canonical JSON and the SHA-256 chain: hash = sha256(prev_hash . canonical(event)).
 */
final class AuditChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Fields covered by the hash (everything that describes the event). */
    public const HASHED = ['ulid', 'tenant_id', 'chain_seq', 'occurred_at', 'actor_type', 'actor_id', 'actor_name', 'actor_kind',
        'impersonator_id', 'impersonator_name', 'on_behalf_of_user_id', 'automation', 'automation_scope', 'ip', 'user_agent',
        'session_ref', 'request_id', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'metadata', 'severity'];

    /**
     * @param  array<string, mixed>  $event
     */
    public static function hash(string $prevHash, array $event): string
    {
        $data = [];
        foreach (self::HASHED as $field) {
            $value = $event[$field] ?? null;
            if (in_array($field, ['changes', 'metadata'], true) && is_string($value)) {
                $value = json_decode($value, true);
            }
            $data[$field] = $value === null ? null : (in_array($field, ['tenant_id', 'chain_seq', 'actor_id', 'impersonator_id', 'on_behalf_of_user_id'], true) ? (int) $value : $value);
        }

        return hash('sha256', $prevHash.self::canonical($data));
    }

    public static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) || is_int($value) || is_bool($value) || $value === null ? $value : (string) $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value);

        return array_map(self::sort(...), $value);
    }
}
