<?php

declare(strict_types=1);

namespace Modules\Core\Tenancy;

/**
 * Tenant slug rules (specs/core/01): 3-40 characters, lower-case letters, digits and
 * single hyphens, starting and ending with a letter or digit, not reserved.
 */
final class Slug
{
    private const PATTERN = '/^[a-z0-9](?:[a-z0-9]|-(?!-))*[a-z0-9]$/';

    public static function isValid(string $slug): bool
    {
        return self::problem($slug) === null;
    }

    /**
     * Returns a human-readable reason the slug is not allowed, or null when it is valid.
     */
    public static function problem(string $slug): ?string
    {
        $min = (int) config('core.slug.min', 3);
        $max = (int) config('core.slug.max', 40);
        $length = strlen($slug);

        if ($length < $min || $length > $max) {
            return "The address must be between {$min} and {$max} characters.";
        }

        if (preg_match(self::PATTERN, $slug) !== 1) {
            return 'Use lower-case letters, numbers and single hyphens; start and end with a letter or number.';
        }

        /** @var list<string> $reserved */
        $reserved = (array) config('core.slug.reserved', []);

        if (in_array($slug, $reserved, true)) {
            return 'This address is reserved. Please choose another.';
        }

        return null;
    }
}
