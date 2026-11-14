<?php

declare(strict_types=1);

namespace Modules\Core\Risk;

/**
 * A module's contribution to the broker risk register (protect/01). Modules tag implementations with
 * `risk.sources` so the register never reaches into another module's tables.
 */
interface RiskSource
{
    /**
     * @return list<array{level: string, category: string, title: string, deal: ?string, street: ?string, owner: string, due: ?string, link: string}>
     */
    public function risks(): array;
}
