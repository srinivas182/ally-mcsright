<?php

declare(strict_types=1);

namespace Modules\Core\Privacy\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Throwable;

/**
 * SX1 (agent privacy): keeps clients' personal details out of log files and error reports.
 *
 * Attached to every log channel with `'tap' => [RedactPersonalData::class]` (config/logging.php; an architecture
 * test fails if a channel forgets it). Before a line is written it:
 *
 * - replaces email addresses, phone numbers, Social Security numbers and card-like numbers in the message and in
 *   every text value of the context with a label such as "[email]";
 * - blanks the value of any context key that names a secret or a personal detail (password, token, ssn, email,
 *   phone, account or routing number, date of birth, ...), however deep it sits;
 * - turns an exception in the context into its class, redacted message, file, line and stack, so a database error
 *   that quotes a client's email ("insert ... values ('jane@x.test')") is written without it.
 *
 * Ids (ULIDs, numeric ids), dates, times, prices and addresses are kept: support needs them to find the record.
 */
final class RedactPersonalData
{
    /** Context keys whose values are never written (matched as a whole word inside the key, any case). */
    public const KEYS = ['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie', 'ssn', 'social_security',
        'tax_id', 'tin', 'email', 'phone', 'mobile', 'account_number', 'routing_number', 'card_number', 'cvv', 'dob', 'date_of_birth', 'license_number'];

    /** Pattern → label. Order matters: SSN before the phone and card patterns that would also match parts of it. */
    private const PATTERNS = [
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
        '/(?<![\w-])\d{3}-\d{2}-\d{4}(?![\w-])/' => '[ssn]',
        '/(?<![\w.])(?:\d[ -]?){12,18}\d(?![\w.])/' => '[number]',
        '/(?<![\w+])\+\d{10,15}(?!\w)/' => '[phone]',
        '/(?<![\w.])(?:1[\s.\-]?)?(?:\(\d{3}\)\s?|\d{3}[\s.\-])\d{3}[\s.\-]\d{4}(?![\w.])/' => '[phone]',
    ];

    /** Laravel's tap hook: add the processor to every handler's logger. */
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $this->process($record));
        }
    }

    public function process(LogRecord $record): LogRecord
    {
        return $record->with(message: self::text($record->message), context: self::context($record->context), extra: self::context($record->extra));
    }

    /** Replace personal details inside free text. */
    public static function text(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * Redact a context array, keeping its shape.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function context(array $context, int $depth = 0): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && self::sensitiveKey($key)) {
                $context[$key] = '[removed]';
            } elseif ($value instanceof Throwable) {
                $context[$key] = self::exception($value);
            } elseif (is_string($value)) {
                $context[$key] = self::text($value);
            } elseif (is_array($value)) {
                $context[$key] = $depth < 6 ? self::context($value, $depth + 1) : '[nested]';
            } elseif (is_object($value) && ! $value instanceof \Stringable && ! $value instanceof \DateTimeInterface && ! $value instanceof \UnitEnum) {
                // Models and other objects: their properties may hold anything, so write only what they are.
                $context[$key] = '['.$value::class.']';
            } elseif ($value instanceof \Stringable) {
                $context[$key] = self::text((string) $value);
            }
        }

        return $context;
    }

    public static function sensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::KEYS as $word) {
            if (preg_match('/(^|[^a-z])'.preg_quote($word, '/').'($|[^a-z])/', $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /** An exception as plain text: class, redacted message, where, and the redacted stack (and its causes). */
    public static function exception(Throwable $e): string
    {
        $out = [];
        for ($i = 0; $e !== null && $i < 4; $e = $e->getPrevious(), $i++) {
            $out[] = ($i > 0 ? 'Caused by ' : '').$e::class.': '.self::text($e->getMessage()).' at '.$e->getFile().':'.$e->getLine()."\n".self::text($e->getTraceAsString());
        }

        return implode("\n", $out);
    }
}
