<?php

declare(strict_types=1);

namespace Modules\Compensation\Enums;

/**
 * Published versions are immutable; a newer published version supersedes them.
 */
enum VersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
