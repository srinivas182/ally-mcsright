<?php

declare(strict_types=1);

namespace Modules\ListIq\Staging;

/** Virtual staging (LI1): furnishes an empty room photo in a style. */
interface VirtualStager
{
    /** Whether HQ has switched the service on with its key. */
    public function ready(): bool;

    /** The staged photo (JPEG bytes), or null when it didn't work. */
    public function stage(string $publicUrl, string $room, string $style): ?string;
}
