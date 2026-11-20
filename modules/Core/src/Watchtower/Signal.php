<?php

declare(strict_types=1);

namespace Modules\Core\Watchtower;

use Throwable;

/** Something that went wrong, ready to be grouped into a problem (OB1). Already masked. */
final readonly class Signal
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $kind,
        public string $group,               // what makes two occurrences "the same problem"
        public string $title,
        public string $severity = 'normal', // the least this kind of problem can be
        public ?string $exceptionClass = null,
        public ?string $message = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $location = null,
        public ?string $surface = null,
        public ?string $trace = null,
        public array $context = [],
        public bool $ongoing = false,
    ) {}

    public function fingerprint(): string
    {
        return sha1($this->kind.'|'.$this->group);
    }

    /**
     * A server error. Grouped by its class, where in RightAlly's own code it happened (the first place outside
     * vendor/), and its message with the specifics taken out.
     *
     * @param  array<string, mixed>  $context
     */
    public static function fromThrowable(Throwable $e, string $kind, ?string $location, ?string $surface, array $context = [], string $severity = 'normal'): self
    {
        [$file, $line] = self::origin($e);
        $class = $e::class;
        $message = Mask::text($e->getMessage(), 1000);

        return new self(
            kind: $kind,
            group: $class.'|'.$file.':'.$line.'|'.Mask::normalise($e->getMessage()).'|'.($kind === 'exception' ? '' : (string) $location),
            title: mb_substr(class_basename($class).($message !== '' ? ': '.$message : ''), 0, 250),
            severity: $severity,
            exceptionClass: $class,
            message: $message,
            file: $file,
            line: $line,
            location: $location,
            surface: $surface,
            trace: self::trace($e),
            context: $context,
        );
    }

    /** @return array{0: string, 1: int} the first place in RightAlly's own code (not a library) */
    public static function origin(Throwable $e): array
    {
        foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $f) {
            $file = (string) ($f['file'] ?? '');
            if ($file !== '' && ! str_contains($file, '/vendor/')) {
                return [Mask::path($file), (int) ($f['line'] ?? 0)];
            }
        }

        return [Mask::path($e->getFile()), $e->getLine()];
    }

    /** The top frames, without arguments (which may hold personal data). */
    public static function trace(Throwable $e, int $frames = 25): string
    {
        $lines = [Mask::path($e->getFile()).':'.$e->getLine()];
        foreach (array_slice($e->getTrace(), 0, $frames) as $i => $f) {
            $where = isset($f['file']) ? Mask::path($f['file']).':'.($f['line'] ?? '?') : '[internal]';
            $call = (isset($f['class']) ? $f['class'].($f['type'] ?? '->') : '').$f['function'];
            $lines[] = '#'.$i.' '.$where.' '.str_replace(rtrim(base_path(), '/').'/', '', $call).'()';
        }
        if (($previous = $e->getPrevious()) !== null) {
            $lines[] = 'Caused by '.$previous::class.': '.Mask::text($previous->getMessage(), 300).' at '.Mask::path($previous->getFile()).':'.$previous->getLine();
        }

        return implode("\n", $lines);
    }
}
