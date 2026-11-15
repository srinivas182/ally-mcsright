<?php

declare(strict_types=1);

namespace Modules\Ads\Social;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** A social post that one place refused, with the reason in plain words. */
final class PostFailed extends RuntimeException
{
    public static function from(Response $r, string $app): self
    {
        $reason = $r->json('error.message') ?? $r->json('message') ?? $r->json('error_description') ?? null;
        $signIn = in_array($r->status(), [401, 403], true) || (int) $r->json('error.code') === 190;

        return new self($signIn ? $app.' needs you to connect again (the sign-in expired or permission was removed).'
            : $app.' refused the post'.(is_string($reason) && $reason !== '' ? ': '.mb_substr($reason, 0, 200) : ' ('.$r->status().').'));
    }
}
