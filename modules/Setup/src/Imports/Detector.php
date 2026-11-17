<?php

declare(strict_types=1);

namespace Modules\Setup\Imports;

use Modules\AI\AiGateway;
use Modules\AI\Data\AiTask;
use Throwable;

/**
 * Works out what a file is (SU1): people, agents or deals, which system it came from, and which column is which.
 * Known exports and common column words first; only when a needed column is still unmatched does the AI suggest
 * matches, from the column names and the kind of values in them (an email, a date, a number), never the values.
 */
final class Detector
{
    public const FEATURE = 'setup.map_columns';

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $sample  a few rows, used only to describe each column's kind of value
     * @return array{kind: string, source: string|null, label: string, mapping: array<string, string>, missing: list<string>, ai: bool}
     */
    public function detect(array $headers, array $sample, ?string $kind = null, ?int $userId = null): array
    {
        $normal = array_map(fn (string $h): string => Formats::normal($h), $headers);
        $source = null;
        $best = 0.0;
        foreach (Formats::SOURCES as $key => $s) {
            if ($kind !== null && $s['kind'] !== $kind) {
                continue;
            }
            $score = count(array_intersect($s['columns'], $normal)) / count($s['columns']);
            if ($score > $best) {
                [$best, $source] = [$score, $key];
            }
        }
        if ($best < 0.8) {
            $source = null;
        }
        if ($source !== null) {
            $kind = Formats::SOURCES[$source]['kind'];
        }
        if ($kind === null) {
            $kind = $this->guessKind($headers);
        }
        $mapping = self::byWords($headers, $kind);
        $missing = self::missing($kind, $mapping);
        $ai = false;
        if ($missing !== []) {
            $suggested = $this->askAi($headers, $sample, $kind, $userId);
            foreach ($suggested as $header => $field) {
                if (! isset($mapping[$header]) && ! in_array($field, $mapping, true)) {
                    $mapping[$header] = $field;
                    $ai = true;
                }
            }
            $missing = self::missing($kind, $mapping);
        }

        return ['kind' => $kind, 'source' => $source, 'label' => $source !== null ? Formats::SOURCES[$source]['label'] : Formats::KINDS[$kind],
            'mapping' => $mapping, 'missing' => $missing, 'ai' => $ai];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string> header => field
     */
    public static function byWords(array $headers, string $kind): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $n = Formats::normal($header);
            foreach (Formats::FIELDS[$kind] as $field => $words) {
                if (($n === $field || in_array($n, $words, true)) && ! in_array($field, $mapping, true)) {
                    $mapping[$header] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Needed fields still unmatched, in plain words.
     *
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    public static function missing(string $kind, array $mapping): array
    {
        $out = [];
        foreach (Formats::REQUIRED[$kind] as $group) {
            if (array_intersect($group, $mapping) === []) {
                $out[] = implode(' or ', array_map(fn (string $f): string => str_replace('_', ' ', $f), $group));
            }
        }

        return $out;
    }

    /** @param  list<string>  $headers */
    private function guessKind(array $headers): string
    {
        $best = 'people';
        $bestScore = -1;
        foreach (array_keys(Formats::KINDS) as $kind) {
            $mapping = self::byWords($headers, $kind);
            $score = count($mapping) * 2 - count(self::missing($kind, $mapping)) * 5;
            if ($kind === 'deals' && array_intersect(['status', 'closing_date', 'price'], $mapping) !== []) {
                $score += 4;
            }
            if ($kind === 'agents' && in_array('licence_number', $mapping, true)) {
                $score += 4;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$kind, $score];
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $sample
     * @return array<string, string>
     */
    private function askAi(array $headers, array $sample, string $kind, ?int $userId): array
    {
        $columns = [];
        foreach ($headers as $h) {
            $columns[] = $h.' ('.self::shape(array_map(fn (array $r): string => (string) ($r[$h] ?? ''), $sample)).')';
        }
        try {
            $json = app(AiGateway::class)->run(new AiTask(self::FEATURE, 'setup.map_columns', 'setup-map:'.hash('sha256', $kind.implode('|', $headers)).':'.now()->format('YmdHi'),
                inputs: ['kind' => Formats::KINDS[$kind], 'fields' => implode(', ', array_keys(Formats::FIELDS[$kind])), 'columns' => implode('; ', $columns)],
                outputSchema: ['type' => 'object', 'required' => ['mapping'], 'properties' => ['mapping' => ['type' => 'object']]], userId: $userId))->json;
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ((array) ($json['mapping'] ?? []) as $header => $field) {
            if (in_array($header, $headers, true) && is_string($field) && array_key_exists($field, Formats::FIELDS[$kind])) {
                $out[(string) $header] = $field;
            }
        }

        return $out;
    }

    /**
     * What kind of values a column holds, without the values themselves.
     *
     * @param  list<string>  $values
     */
    public static function shape(array $values): string
    {
        $values = array_values(array_filter(array_map('trim', $values), fn (string $v): bool => $v !== ''));
        if ($values === []) {
            return 'empty';
        }
        $kinds = array_map(fn (string $v): string => match (true) {
            filter_var($v, FILTER_VALIDATE_EMAIL) !== false => 'email',
            preg_match('/^\+?[\d\s().-]{10,}$/', $v) === 1 => 'phone number',
            preg_match('/^\d{4}-\d{2}-\d{2}|^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $v) === 1 => 'date',
            preg_match('/^\d{5}(-\d{4})?$/', $v) === 1 => 'ZIP code',
            preg_match('/^\$?[\d,]+(\.\d+)?$/', $v) === 1 => 'number',
            preg_match('/^[A-Z]{2}$/', $v) === 1 => 'two-letter code',
            mb_strlen($v) <= 30 => 'short text',
            default => 'long text',
        }, $values);
        $counts = array_count_values($kinds);
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
