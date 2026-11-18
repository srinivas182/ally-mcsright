<?php

declare(strict_types=1);

namespace Modules\Marketing;

use Illuminate\Validation\ValidationException;

/**
 * The building blocks of a RightAlly website page (SG1, specs/marketing/01), checked on every save. Text only (no
 * HTML); links are site paths or https addresses. Testimonials need the person's permission ticked: the site never
 * shows a made-up review.
 */
final class Blocks
{
    public const MAX = 30;

    /** type => [plain-English name, fields] */
    public const TYPES = [
        'hero' => 'Top of the page',
        'answer' => 'Short answer (for search and AI answers)',
        'features' => 'Features',
        'faq' => 'Questions and answers',
        'compare' => 'Comparison table',
        'pricing' => 'Prices (live from billing)',
        'steps' => 'Steps',
        'text' => 'Text',
        'testimonials' => 'What customers say',
        'cta' => 'Call to action',
    ];

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function clean(array $blocks): array
    {
        if (count($blocks) > self::MAX) {
            throw ValidationException::withMessages(['blocks' => 'A page can have up to '.self::MAX.' blocks.']);
        }
        $out = [];
        foreach (array_values($blocks) as $i => $b) {
            $n = $i + 1;
            if (! is_array($b) || ! isset(self::TYPES[$b['type'] ?? ''])) {
                throw ValidationException::withMessages(['blocks' => "Block {$n} has an unknown type."]);
            }
            $out[] = match ($b['type']) {
                'hero' => ['type' => 'hero', 'heading' => self::text($b, 'heading', 120, $n, true), 'text' => self::text($b, 'text', 400, $n),
                    'cta_label' => self::text($b, 'cta_label', 40, $n), 'cta_href' => self::link($b, 'cta_href', $n),
                    'second_label' => self::text($b, 'second_label', 40, $n), 'second_href' => self::link($b, 'second_href', $n)],
                'answer' => self::answer($b, $n),
                'features' => ['type' => 'features', 'heading' => self::text($b, 'heading', 120, $n), 'items' => self::items($b, $n, 24, ['title' => 80, 'text' => 400], ['title'])],
                'faq' => ['type' => 'faq', 'heading' => self::text($b, 'heading', 120, $n), 'items' => self::items($b, $n, 30, ['q' => 200, 'a' => 1200], ['q', 'a'])],
                'compare' => self::compare($b, $n),
                'pricing' => ['type' => 'pricing', 'heading' => self::text($b, 'heading', 120, $n), 'note' => self::text($b, 'note', 300, $n)],
                'steps' => ['type' => 'steps', 'heading' => self::text($b, 'heading', 120, $n), 'items' => self::items($b, $n, 10, ['title' => 80, 'text' => 300], ['title'])],
                'text' => ['type' => 'text', 'heading' => self::text($b, 'heading', 120, $n), 'body' => self::text($b, 'body', 8000, $n, true)],
                'testimonials' => self::testimonials($b, $n),
                'cta' => ['type' => 'cta', 'heading' => self::text($b, 'heading', 120, $n, true), 'text' => self::text($b, 'text', 300, $n),
                    'label' => self::text($b, 'label', 40, $n, true), 'href' => self::link($b, 'href', $n, true)],
            };
        }

        return $out;
    }

    /** @param array<string, mixed> $b */
    private static function text(array $b, string $key, int $max, int $n, bool $required = false): string
    {
        $value = trim(strip_tags((string) ($b[$key] ?? '')));
        if ($required && $value === '') {
            throw ValidationException::withMessages(['blocks' => "Block {$n}: fill in “{$key}”."]);
        }
        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages(['blocks' => "Block {$n}: “{$key}” is too long (at most {$max} characters)."]);
        }

        return $value;
    }

    /** @param array<string, mixed> $b */
    private static function link(array $b, string $key, int $n, bool $required = false): string
    {
        $value = trim((string) ($b[$key] ?? ''));
        if ($value === '' && ! $required) {
            return '';
        }
        $ok = (str_starts_with($value, '/') && ! str_starts_with($value, '//') && preg_match('/^[\/A-Za-z0-9._~?=&%#-]*$/', $value) === 1)
            || (str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false);
        if (! $ok || mb_strlen($value) > 300) {
            throw ValidationException::withMessages(['blocks' => "Block {$n}: links start with / (a page on this site) or https://."]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $b
     * @param  array<string, int>  $fields  field => max length
     * @param  list<string>  $required
     * @return list<array<string, string>>
     */
    private static function items(array $b, int $n, int $max, array $fields, array $required): array
    {
        $items = is_array($b['items'] ?? null) ? array_values($b['items']) : [];
        if ($items === [] || count($items) > $max) {
            throw ValidationException::withMessages(['blocks' => "Block {$n}: add between 1 and {$max} items."]);
        }

        return array_map(function (mixed $item) use ($fields, $required, $n): array {
            $item = is_array($item) ? $item : [];
            $row = [];
            foreach ($fields as $f => $len) {
                $row[$f] = self::text($item, $f, $len, $n, in_array($f, $required, true));
            }

            return $row;
        }, $items);
    }

    /**
     * Answer-first (SEO and AEO standard, rule 3): a question and a direct answer of 25 to 80 words.
     *
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function answer(array $b, int $n): array
    {
        $answer = self::text($b, 'answer', 700, $n, true);
        $words = str_word_count($answer);
        if ($words < 25 || $words > 80) {
            throw ValidationException::withMessages(['blocks' => "Block {$n}: a short answer is 25 to 80 words (now {$words}). Answer the question in the first sentence."]);
        }

        return ['type' => 'answer', 'question' => self::text($b, 'question', 160, $n, true), 'answer' => $answer];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function compare(array $b, int $n): array
    {
        $columns = array_values(array_filter(array_map(fn (mixed $c): string => trim(strip_tags((string) $c)), is_array($b['columns'] ?? null) ? $b['columns'] : [])));
        if (count($columns) < 2 || count($columns) > 5) {
            throw ValidationException::withMessages(['blocks' => "Block {$n}: a comparison has 2 to 5 columns."]);
        }
        $rows = [];
        foreach (is_array($b['rows'] ?? null) ? array_values($b['rows']) : [] as $row) {
            $row = is_array($row) ? $row : [];
            $values = array_map(fn (mixed $v): string => mb_substr(trim(strip_tags((string) $v)), 0, 120), array_slice(is_array($row['values'] ?? null) ? array_values($row['values']) : [], 0, count($columns)));
            $rows[] = ['label' => self::text($row, 'label', 120, $n, true), 'values' => array_pad($values, count($columns), '')];
        }
        if ($rows === [] || count($rows) > 40) {
            throw ValidationException::withMessages(['blocks' => "Block {$n}: a comparison has 1 to 40 rows."]);
        }

        return ['type' => 'compare', 'heading' => self::text($b, 'heading', 120, $n), 'columns' => $columns, 'rows' => $rows,
            'note' => self::text($b, 'note', 400, $n), 'checked_on' => self::text($b, 'checked_on', 20, $n, true)];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function testimonials(array $b, int $n): array
    {
        $items = is_array($b['items'] ?? null) ? array_values($b['items']) : [];
        foreach ($items as $item) {
            if (! is_array($item) || ($item['permission'] ?? false) !== true) {
                throw ValidationException::withMessages(['blocks' => "Block {$n}: tick that each customer agreed to their words being shown. Only real customers' own words can be used."]);
            }
        }
        $clean = self::items($b, $n, 12, ['quote' => 500, 'name' => 80, 'role' => 120], ['quote', 'name']);

        return ['type' => 'testimonials', 'heading' => self::text($b, 'heading', 120, $n), 'items' => array_map(fn (array $i): array => $i + ['permission' => true], $clean)];
    }
}
