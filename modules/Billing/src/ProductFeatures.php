<?php

declare(strict_types=1);

namespace Modules\Billing;

/**
 * The features plans and packages switch on (specs/core/07). "Minimal" is what an agent without a
 * plan keeps when the brokerage chooses minimal access: what the brokerage needs from every agent
 * (profile, commission statements, deals and documents, announcements, training).
 */
final class ProductFeatures
{
    public const MINIMAL = ['backoffice.minimal'];

    /**
     * @return array<string, string> key => plain-English name
     */
    public static function all(): array
    {
        return [
            'backoffice.minimal' => 'Profile, commission statements, deals and documents, announcements, training',
            'backoffice.basic' => 'Agent back office: contacts, tasks, pipelines, follow-up plans',
            'crm.ai' => 'AI contact brief, Ask your database, voice',
            'crm.intelligence' => "Today's 10, opportunity scores and alerts",
            'website' => 'Own website with AIlly',
        ];
    }
}
