<?php

declare(strict_types=1);

namespace Modules\Ledger\Close;

/**
 * One thing a month-end check found, in plain words. "stop" must be fixed before the month can close;
 * "check" is worth a look and the month can still close with a written note (specs/finance/22).
 */
final readonly class Finding
{
    public const STOP = 'stop';

    public const CHECK = 'check';

    public function __construct(
        public string $level,
        public string $title,
        public string $detail,
        public string $ref = '',
        public ?string $link = null,
    ) {}

    public static function stop(string $title, string $detail, string $ref = '', ?string $link = null): self
    {
        return new self(self::STOP, $title, $detail, $ref, $link);
    }

    public static function check(string $title, string $detail, string $ref = '', ?string $link = null): self
    {
        return new self(self::CHECK, $title, $detail, $ref, $link);
    }
}
