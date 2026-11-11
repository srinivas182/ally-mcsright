<?php

declare(strict_types=1);

namespace Modules\Compensation\Calculator;

use Modules\Compensation\Rules\CommissionRules;

final readonly class ResolvedPlan
{
    public function __construct(
        public CommissionRules $rules,
        public string $planName,
        public ?int $planVersionId = null,
        public ?int $versionNumber = null,
    ) {}
}
