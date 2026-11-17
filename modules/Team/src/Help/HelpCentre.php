<?php

declare(strict_types=1);

namespace Modules\Team\Help;

use Illuminate\Support\Str;

/**
 * The help centre (CH1, specs/team/01): short how-to articles written for busy agents, kept as Markdown files in
 * the code so they ship with each update and match the screens. Each starts with a small header:
 *   title, section, summary, keywords, and optionally "who: owner" for broker-only articles.
 * Modules add their own folders with addPath(). Search is plain word matching (title words count most), so it
 * works the same with no AI and no outside service.
 */
final class HelpCentre
{
    /** @var list<string> */
    private array $paths = [];

    /** @var array<string, array{slug: string, title: string, section: string, summary: string, keywords: string, who: string, body: string}>|null */
    private ?array $cache = null;

    public function __construct()
    {
        $this->paths[] = __DIR__.'/../../resources/help';
    }

    public function addPath(string $path): void
    {
        $this->paths[] = $path;
        $this->cache = null;
    }

    /**
     * @return array<string, array{slug: string, title: string, section: string, summary: string, keywords: string, who: string, body: string}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        foreach ($this->paths as $dir) {
            foreach (glob(rtrim($dir, '/').'/*.md') ?: [] as $file) {
                $a = self::parse(basename($file, '.md'), (string) file_get_contents($file));
                if ($a !== null) {
                    $out[$a['slug']] = $a;
                }
            }
        }
        uasort($out, fn (array $a, array $b): int => [$a['section'], $a['title']] <=> [$b['section'], $b['title']]);

        return $this->cache = $out;
    }

    /**
     * Articles this person may read: broker-only ones only for owners and staff.
     *
     * @return array<string, array{slug: string, title: string, section: string, summary: string, keywords: string, who: string, body: string}>
     */
    public function visible(bool $leader): array
    {
        return array_filter($this->all(), fn (array $a): bool => $a['who'] !== 'owner' || $leader);
    }

    /**
     * @return array{slug: string, title: string, section: string, summary: string, keywords: string, who: string, body: string}|null
     */
    public function find(string $slug, bool $leader): ?array
    {
        return $this->visible($leader)[$slug] ?? null;
    }

    /**
     * Best matches first; title words count three times, keywords twice, the text once.
     *
     * @return list<array{slug: string, title: string, section: string, summary: string}>
     */
    public function search(string $query, bool $leader, int $limit = 8): array
    {
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($query)) ?: [], fn (string $w): bool => mb_strlen($w) >= 2
            && ! in_array($w, ['how', 'do', 'to', 'the', 'my', 'an', 'is', 'in', 'of', 'on', 'can', 'what', 'and', 'for', 'it', 'me', 'with', 'where'], true)));
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach ($this->visible($leader) as $a) {
            $title = mb_strtolower($a['title']);
            $keys = mb_strtolower($a['keywords'].' '.$a['summary']);
            $body = mb_strtolower($a['body']);
            $score = 0;
            foreach ($words as $w) {
                $stem = mb_strlen($w) > 4 ? rtrim($w, 's') : $w;
                $score += (str_contains($title, $stem) ? 3 : 0) + (str_contains($keys, $stem) ? 2 : 0) + (str_contains($body, $stem) ? 1 : 0);
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'slug' => $a['slug'], 'title' => $a['title'], 'section' => $a['section'], 'summary' => $a['summary']];
            }
        }
        usort($scored, fn (array $x, array $y): int => [$y['score'], $x['title']] <=> [$x['score'], $y['title']]);

        return array_map(fn (array $s): array => ['slug' => $s['slug'], 'title' => $s['title'], 'section' => $s['section'], 'summary' => $s['summary']], array_slice($scored, 0, $limit));
    }

    /** The article's Markdown as safe HTML (raw HTML in the file is escaped; links stay in the app). */
    public static function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * @return array{slug: string, title: string, section: string, summary: string, keywords: string, who: string, body: string}|null
     */
    public static function parse(string $slug, string $text): ?array
    {
        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $text, $m) !== 1 || preg_match('/^[a-z0-9-]{2,80}$/', $slug) !== 1) {
            return null;
        }
        $meta = [];
        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            if (preg_match('/^([a-z]+):\s*(.*)$/', trim($line), $kv) === 1) {
                $meta[$kv[1]] = trim($kv[2]);
            }
        }
        if (($meta['title'] ?? '') === '') {
            return null;
        }

        return ['slug' => $slug, 'title' => $meta['title'], 'section' => $meta['section'] ?? 'Getting started', 'summary' => $meta['summary'] ?? '',
            'keywords' => $meta['keywords'] ?? '', 'who' => $meta['who'] ?? 'everyone', 'body' => trim($m[2])];
    }
}
