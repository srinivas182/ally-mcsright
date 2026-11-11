<?php

declare(strict_types=1);

namespace Modules\AI\Security;

/**
 * Guarded streaming: text is released to the user only after the output policy has checked
 * everything received so far, only up to a word boundary, and never the last HOLD_BACK characters,
 * so an identifier (email, phone, record ID) can never appear half-written before it is checked.
 */
final class StreamGuard
{
    public const HOLD_BACK = 32;

    private string $received = '';

    private int $released = 0;

    public function __construct(
        private readonly OutputPolicy $policy,
        private readonly string $permitted,
    ) {}

    /**
     * Adds a piece of text and returns what can safely be shown now ('' when nothing yet).
     * Returns null when the output policy is violated: the stream must stop.
     */
    public function push(string $delta): ?string
    {
        $this->received .= $delta;

        if ($this->violated()) {
            return null;
        }

        $limit = mb_strlen($this->received) - self::HOLD_BACK;
        if ($limit <= $this->released) {
            return '';
        }

        $candidate = mb_substr($this->received, $this->released, $limit - $this->released);
        $boundary = max(mb_strrpos($candidate, ' ') ?: -1, mb_strrpos($candidate, "\n") ?: -1);
        if ($boundary < 0) {
            return '';
        }

        $safe = mb_substr($candidate, 0, $boundary + 1);
        $this->released += mb_strlen($safe);

        return $safe;
    }

    /**
     * Final check of the complete answer; returns the remaining text, or null if violated.
     */
    public function finish(): ?string
    {
        if ($this->violated()) {
            return null;
        }

        $rest = mb_substr($this->received, $this->released);
        $this->released = mb_strlen($this->received);

        return $rest;
    }

    public function received(): string
    {
        return $this->received;
    }

    public function releasedAnything(): bool
    {
        return $this->released > 0;
    }

    private function violated(): bool
    {
        return $this->policy->violations($this->received, $this->permitted) !== [];
    }
}
