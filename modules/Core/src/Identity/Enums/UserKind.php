<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Enums;

/**
 * Drives the landing page. Permissions come from roles (specs/core/03).
 */
enum UserKind: string
{
    case HqStaff = 'hq_staff';
    case Owner = 'owner';
    case Staff = 'staff';
    case Agent = 'agent';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::HqStaff => 'RightAlly HQ staff',
            self::Owner => 'Owner',
            self::Staff => 'Staff',
            self::Agent => 'Agent',
            self::Assistant => 'Assistant',
        };
    }
}
