<?php

declare(strict_types=1);

namespace Modules\Compensation\Exceptions;

use InvalidArgumentException;

/**
 * Plan rules failed validation. Carries every problem found, keyed by rule path.
 */
final class InvalidRules extends InvalidArgumentException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid plan rules: '.implode(' ', array_map(
            fn (string $path, string $message): string => "{$path}: {$message}",
            array_keys($errors),
            $errors,
        )));
    }
}
