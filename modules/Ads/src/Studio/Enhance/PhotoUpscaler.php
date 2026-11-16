<?php

declare(strict_types=1);

namespace Modules\Ads\Studio\Enhance;

/** Doubles a photo's size with an AI upscaler, so crops for posts and Stories stay sharp (SA5a). */
interface PhotoUpscaler
{
    public function ready(): bool;

    /** The photo at twice the size (JPEG), or null when it couldn't be done (the enhanced photo is used as it is). */
    public function upscale(string $publicUrl): ?string;
}
