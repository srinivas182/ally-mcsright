<?php

declare(strict_types=1);

namespace Modules\Core\Navigation;

use Modules\Core\Identity\Enums\UserKind;

/**
 * A menu entry registered by a module. Shown only to the listed user kinds (empty = all) and only
 * when the user passes the ability (if any).
 */
final readonly class NavItem
{
    /**
     * @param  list<UserKind>  $kinds
     */
    public function __construct(
        public string $group,
        public string $title,
        public string $href,
        public string $icon,
        public int $order = 100,
        public ?string $ability = null,
        public array $kinds = [],
        public bool $mobile = false,
    ) {}
}
