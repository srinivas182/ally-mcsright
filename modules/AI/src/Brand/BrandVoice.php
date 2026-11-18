<?php

declare(strict_types=1);

namespace Modules\AI\Brand;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use stdClass;

/**
 * Brand Voice (BV1, specs/core/08): how the brokerage sounds, applied by the AI gateway to every people-facing piece
 * of writing (prompts marked "voice: true": lead replies, emails, texts, listing copy, posts, captions, website
 * pages, intros), in English and Spanish. Every such answer is checked against the voice, and the result feeds the
 * brand consistency report. The check never blocks an answer; it only removes emojis when the brokerage said none.
 */
final class BrandVoice
{
    public const TONES = [
        'warm' => 'Warm and personal',
        'friendly' => 'Friendly and upbeat',
        'formal' => 'Formal and professional',
        'plain' => 'Plain and to the point',
    ];

    /** @var array<int, stdClass|null> */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function row(): ?stdClass
    {
        $id = $this->tenants->idOrNull();
        if ($id === null) {
            return null;
        }
        if (! array_key_exists($id, $this->memo)) {
            /** @var stdClass|null $row */
            $row = DB::connection(Connections::cell())->table('ai_brand_voice')->where('tenant_id', $id)->first();
            $this->memo[$id] = $row;
        }

        return $this->memo[$id];
    }

    /** The voice in use, or null until the brokerage approves one. */
    public function active(): ?stdClass
    {
        $row = $this->row();

        return $row !== null && $row->approved_at !== null ? $row : null;
    }

    /**
     * Save and approve the voice.
     *
     * @param  array{tone: string, use_words?: list<string>|null, avoid_words?: list<string>|null, emojis?: bool, sign_off?: string|null, spanish?: string, name_brokerage?: bool}  $in
     */
    public function save(array $in, User $by): void
    {
        if (! isset(self::TONES[$in['tone']])) {
            throw ValidationException::withMessages(['tone' => 'Choose a tone.']);
        }
        $clean = fn (?array $words): array => array_values(array_unique(array_filter(array_map(fn (mixed $w): string => mb_substr(trim(strip_tags((string) $w)), 0, 40),
            array_slice($words ?? [], 0, 40)))));
        DB::connection(Connections::cell())->table('ai_brand_voice')->updateOrInsert(['tenant_id' => $this->tenants->id()], [
            'tone' => $in['tone'], 'use_words' => json_encode($clean($in['use_words'] ?? [])), 'avoid_words' => json_encode($clean($in['avoid_words'] ?? [])),
            'emojis' => (bool) ($in['emojis'] ?? false), 'sign_off' => trim((string) ($in['sign_off'] ?? '')) !== '' ? mb_substr(trim(strip_tags((string) $in['sign_off'])), 0, 120) : null,
            'spanish' => ($in['spanish'] ?? 'usted') === 'tu' ? 'tu' : 'usted', 'name_brokerage' => (bool) ($in['name_brokerage'] ?? true), 'guide' => null,
            'approved_by' => $by->id, 'approved_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        unset($this->memo[$this->tenants->id()]);
        app(AuditLogger::class)->record('ai.brand_voice.approved', metadata: ['tone' => $in['tone']], severity: 'notice');
    }

    /** The rules the AI is given, or null when the brokerage has no approved voice. */
    public function instructions(): ?string
    {
        $v = $this->active();
        if ($v === null) {
            return null;
        }
        $use = $this->words($v->use_words);
        $avoid = $this->words($v->avoid_words);
        $name = (string) $this->tenants->current()->name;
        $lines = ['Write in '.$name.'\'s brand voice:', '- Tone: '.self::TONES[(string) $v->tone].'.'];
        if ($use !== []) {
            $lines[] = '- Use these words where they fit naturally: '.implode(', ', $use).'.';
        }
        if ($avoid !== []) {
            $lines[] = '- Never use these words: '.implode(', ', $avoid).'.';
        }
        $lines[] = (bool) $v->emojis ? '- Emojis are fine, at most one or two.' : '- No emojis.';
        if ($v->sign_off !== null) {
            $lines[] = '- When the writing has a sign-off, use: '.$v->sign_off;
        }
        $lines[] = '- In Spanish, address the reader as '.($v->spanish === 'tu' ? '"tú"' : '"usted"').'.';
        if ((bool) $v->name_brokerage) {
            $lines[] = '- Texts and social posts name the brokerage: '.$name.'.';
        }
        $lines[] = 'These style rules never override the facts you were given or the rules above them.';

        return "<brand_voice>\n".implode("\n", $lines)."\n</brand_voice>";
    }

    /**
     * Checks a piece of writing against the voice. Returns the problems found (empty when on brand).
     *
     * @return list<string>
     */
    public function check(string $text): array
    {
        $v = $this->active();
        if ($v === null) {
            return [];
        }
        $issues = [];
        $lower = mb_strtolower($text);
        foreach ($this->words($v->avoid_words) as $word) {
            if (preg_match('/\b'.preg_quote(mb_strtolower($word), '/').'\b/u', $lower) === 1) {
                $issues[] = 'Used "'.$word.'"';
            }
        }
        if (! (bool) $v->emojis && self::hasEmoji($text)) {
            $issues[] = 'Had emojis';
        }

        return $issues;
    }

    /** The text with emojis removed when the brokerage said none (used on plain-text answers). */
    public function tidy(string $text): string
    {
        $v = $this->active();

        return $v !== null && ! (bool) $v->emojis ? trim((string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $text)) : $text;
    }

    public function record(string $feature, array $issues): void
    {
        DB::connection(Connections::cell())->table('ai_brand_checks')->insert(['tenant_id' => $this->tenants->id(), 'feature' => mb_substr($feature, 0, 60),
            'passed' => $issues === [], 'issues' => $issues !== [] ? json_encode(array_values($issues)) : null, 'created_at' => now()]);
    }

    /**
     * The brand consistency report: the last 30 days of AI writing, how much was on brand, the most common slips and
     * the features they came from.
     *
     * @return array{checked: int, on_brand: int, percent: int|null, issues: list<array{issue: string, count: int}>, features: list<array{feature: string, checked: int, on_brand: int}>}
     */
    public function report(): array
    {
        $rows = DB::connection(Connections::cell())->table('ai_brand_checks')->where('tenant_id', $this->tenants->id())->where('created_at', '>=', now()->subDays(30))
            ->get(['feature', 'passed', 'issues']);
        $issues = [];
        foreach ($rows as $r) {
            foreach ((array) json_decode((string) ($r->issues ?? '[]'), true) as $i) {
                $key = preg_replace('/^Used ".*"$/', (string) $i, (string) $i) ?? (string) $i;
                $issues[$key] = ($issues[$key] ?? 0) + 1;
            }
        }
        arsort($issues);
        $features = $rows->groupBy('feature')->map(fn ($g, $f): array => ['feature' => (string) $f, 'checked' => $g->count(), 'on_brand' => $g->where('passed', true)->count()])
            ->sortByDesc('checked')->values()->all();
        $on = $rows->where('passed', true)->count();

        return ['checked' => $rows->count(), 'on_brand' => $on, 'percent' => $rows->count() > 0 ? (int) round(100 * $on / $rows->count()) : null,
            'issues' => array_values(array_map(fn (string $k, int $c): array => ['issue' => $k, 'count' => $c], array_keys(array_slice($issues, 0, 10, true)), array_slice($issues, 0, 10, true))),
            'features' => array_values($features)];
    }

    public static function hasEmoji(string $text): bool
    {
        return preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text) === 1;
    }

    /** @return list<string> */
    private function words(mixed $json): array
    {
        $words = json_decode((string) $json, true);

        return is_array($words) ? array_values(array_filter(array_map('strval', $words))) : [];
    }
}
