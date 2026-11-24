<?php

declare(strict_types=1);

namespace Modules\Compensation\Enums;

/**
 * A plan is for deal commissions. CM1: onboarding plans were removed (joining, monthly and annual fees live in
 * the fee catalogue); the case stays only so archived rows from before still load.
 */
enum PlanType: string
{
    case Onboarding = 'onboarding';
    case Commission = 'commission';
}
