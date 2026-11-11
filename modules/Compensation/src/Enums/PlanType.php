<?php

declare(strict_types=1);

namespace Modules\Compensation\Enums;

/**
 * Legacy rule 1: a plan is for onboarding fees or for deal commissions.
 */
enum PlanType: string
{
    case Onboarding = 'onboarding';
    case Commission = 'commission';
}
