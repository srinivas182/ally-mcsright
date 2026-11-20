<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

/** Which version of RightAlly is running (OB1): APP_RELEASE and APP_COMMIT, set when the release is built. */
final class Release
{
    public static function current(): string
    {
        $r = config('core.release', 'dev');

        return is_string($r) && $r !== '' ? mb_substr($r, 0, 40) : 'dev';
    }

    public static function commit(): ?string
    {
        $c = config('core.commit');

        return is_string($c) && $c !== '' ? substr($c, 0, 12) : null;
    }
}
