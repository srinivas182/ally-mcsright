<?php

declare(strict_types=1);

namespace Modules\Core\Identity\Enums;

enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
}
