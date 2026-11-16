<?php

declare(strict_types=1);

namespace Modules\Core\Access;

use App\Models\User;

/**
 * Field-level hiding (specs/core/03, R1). Some fields need a permission on top of seeing the record: someone can
 * work a deal without seeing what it pays. People always see their own figures (and assistants their agent's), owners see everything, and staff
 * without a role keep what they saw before.
 */
final class Fields
{
    /** field => [permission that reveals it, plain-English name] */
    public const HIDEABLE = [
        'commission' => ['finance.amounts.view', 'Commission amounts and earnings'],
        'bank' => ['finance.bank_details.manage', 'Bank and payout details'],
    ];

    public static function visible(User $viewer, string $field, ?int $ownerUserId = null): bool
    {
        if ($ownerUserId !== null && ($ownerUserId === $viewer->id || $viewer->assisted_user_id === $ownerUserId)) {
            return true;
        }
        $permission = self::HIDEABLE[$field][0] ?? null;

        return $permission !== null && Can::manage($viewer, $permission);
    }

    /**
     * The hidden fields for a set of permissions (for the role editor and "preview as").
     *
     * @param  array<string, string|null>  $permissions
     * @return list<string>
     */
    public static function hiddenFor(array $permissions): array
    {
        $out = [];
        foreach (self::HIDEABLE as [$permission, $label]) {
            if (! array_key_exists($permission, $permissions)) {
                $out[] = $label;
            }
        }

        return $out;
    }
}
