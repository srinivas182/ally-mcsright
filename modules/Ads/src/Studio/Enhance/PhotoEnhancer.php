<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Enhance;

/**
 * An AI photo-enhancement service for Ally Studio (specs/grow/13, SA5a). Work is asynchronous: start a photo, check
 * on it, then fetch the result. A twilight starts from an enhanced photo the service already holds.
 */
interface PhotoEnhancer
{
    /** Whether HQ has switched the service on with its key. */
    public function ready(): bool;

    /** Starts enhancing a photo (HDR, colour, verticals, sky and lawn); returns the service's reference. */
    public function start(string $bytes, string $name): string;

    /** Starts a virtual twilight of an enhanced photo; returns the new reference. */
    public function twilight(string $ref): string;

    /** @return 'processing'|'ready'|'failed' */
    public function status(string $ref): string;

    /** The finished photo (JPEG). */
    public function fetch(string $ref): string;
}
