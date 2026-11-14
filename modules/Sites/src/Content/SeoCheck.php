<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Modules\Sites\Models\SitePost;

/**
 * Checks a page or post against the SEO and AEO standard (specs/websites/04) while it is edited: title and
 * description length, one main heading, alt text, internal links, an answer near the top (what AI assistants quote),
 * question-and-answer content, length, and indexing. Each check says exactly what to fix.
 */
final class SeoCheck
{
    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array{score: int, checks: list<array{key: string, ok: bool, weight: int, message: string}>}
     */
    public static function page(string $title, ?string $description, array $sections, bool $noindex, string $siteName): array
    {
        $visible = array_values(array_filter($sections, fn (array $s): bool => ! ($s['hidden'] ?? false)));
        $h1 = 0;
        $words = 0;
        $links = 0;
        $answer = false;
        $faq = false;
        foreach ($visible as $i => $s) {
            $p = (array) ($s['props'] ?? []);
            $type = (string) ($s['type'] ?? '');
            if ($type === 'hero' || ($type === 'contact' && $i === 0)) {
                $h1++;
            }
            $text = trim(implode(' ', array_filter([$p['title'] ?? '', $p['headline'] ?? '', $p['subhead'] ?? '', $p['body'] ?? '', $p['caption'] ?? ''], 'is_string')));
            foreach ((array) ($p['items'] ?? []) as $it) {
                $text .= ' '.implode(' ', array_filter((array) $it, 'is_string'));
            }
            $count = str_word_count(strip_tags($text));
            $words += $count;
            if ($i < 3 && in_array($type, ['text', 'hero'], true) && str_word_count((string) ($p['body'] ?? $p['subhead'] ?? '')) >= 20) {
                $answer = true;
            }
            if ($type === 'faq' && ($p['items'] ?? []) !== []) {
                $faq = true;
            }
            if (in_array($type, ['cta', 'listings', 'neighbourhoods', 'contact'], true) || ($type === 'cta' && str_starts_with((string) ($p['link'] ?? ''), '/'))) {
                $links++;
            }
        }
        if ($visible === []) {
            $h1 = 0;
        }
        $full = trim($title.' | '.$siteName);

        return self::score([
            self::check('title', mb_strlen($full) >= 30 && mb_strlen($full) <= 65, 15, mb_strlen($full) < 30
                ? 'The page title is short ('.mb_strlen($full).' characters with your site name). Aim for 30–65, naming the topic and place.'
                : (mb_strlen($full) > 65 ? 'The page title is long ('.mb_strlen($full).' characters); Google cuts it after about 60. Shorten it.' : 'Title length is good.')),
            self::check('description', $description !== null && mb_strlen($description) >= 70 && mb_strlen($description) <= 160, 15, $description === null || $description === ''
                ? 'Add a search description: one or two sentences (70–160 characters) that answer what this page offers.'
                : (mb_strlen($description) < 70 ? 'The search description is short. Aim for 70–160 characters.' : (mb_strlen($description) > 160 ? 'The search description is long; Google cuts it after about 160 characters.' : 'Search description length is good.'))),
            self::check('h1', $h1 === 1, 10, $h1 === 0 ? 'Start the page with a hero (its headline is the main heading) so search engines know the topic.'
                : ($h1 > 1 ? 'There are '.$h1.' main headings; keep one hero per page.' : 'One main heading.')),
            self::check('answer', $answer, 20, $answer ? 'The page answers its question near the top, which AI assistants quote.'
                : 'Add a text section near the top that answers the page\'s main question in 20–60 words. AI assistants quote answers like this.'),
            self::check('faq', $faq, 10, $faq ? 'Has questions and answers (marked up for Google and AI answers).' : 'Add a questions-and-answers section with what people ask about this topic.'),
            self::check('length', $words >= 150, 10, $words >= 150 ? 'Enough content ('.$words.' words).' : 'Only '.$words.' words. Pages with at least 150 useful words rank better.'),
            self::check('links', $links >= 1, 10, $links >= 1 ? 'Links to other pages on your site.' : 'Add a call to action or homes section that links to another page.'),
            self::check('indexing', ! $noindex, 10, $noindex ? 'This page is hidden from search engines. Turn that off unless it is a private page.' : 'Visible to search engines.'),
        ]);
    }

    /**
     * @return array{score: int, checks: list<array{key: string, ok: bool, weight: int, message: string}>}
     */
    public static function post(SitePost $post, string $siteName): array
    {
        $title = $post->seo_title ?: $post->title;
        $full = trim($title.' | '.$siteName);
        $description = $post->meta_description ?: $post->excerpt;
        $r = Posts::render($post->body);
        $h1 = preg_match_all('/^#\s/m', $post->body);
        $noAlt = preg_match_all('/!\[\s*\]\(/', $post->body);
        $internal = preg_match_all('#\]\((/[^)\s]*)\)#', $post->body);
        // The opening paragraph: the first <p> of the rendered post.
        $firstPara = preg_match('#<p>(.*?)</p>#s', $r['html'], $pm) === 1 ? trim(html_entity_decode(strip_tags($pm[1]))) : '';
        $answer = str_word_count($firstPara) >= 25 && str_word_count($firstPara) <= 90;
        $questions = count(array_filter($r['toc'], fn (array $h): bool => str_ends_with($h['text'], '?')));

        return self::score([
            self::check('title', mb_strlen($full) >= 30 && mb_strlen($full) <= 65, 15, mb_strlen($full) >= 30 && mb_strlen($full) <= 65 ? 'Title length is good.'
                : 'Aim for a 30–65 character title (with your site name); it is '.mb_strlen($full).'.'),
            self::check('description', $description !== null && mb_strlen($description) >= 70 && mb_strlen($description) <= 160, 15,
                $description !== null && mb_strlen($description) >= 70 && mb_strlen($description) <= 160 ? 'Search description length is good.' : 'Write a 70–160 character search description or summary.'),
            self::check('h1', $h1 === 0, 5, $h1 === 0 ? 'The title is the only main heading.' : 'Use ## for headings inside the post; the title is already the main heading.'),
            self::check('answer', $answer, 20, $answer ? 'The first paragraph answers the topic directly, which AI assistants quote.'
                : 'Open with a 25–90 word paragraph that answers the question in the title before going into detail.'),
            self::check('headings', count($r['toc']) >= 2, 10, count($r['toc']) >= 2 ? 'Organised with headings.' : 'Break the post into sections with ## headings.'),
            self::check('questions', $questions >= 1, 10, $questions >= 1 ? 'Has question headings, the way people ask AI assistants.' : 'Phrase at least one heading as a question people ask (for example "How much are closing costs in Austin?").'),
            self::check('length', $r['words'] >= 600, 10, $r['words'] >= 600 ? 'Thorough ('.$r['words'].' words).' : $r['words'].' words. Posts of 600+ words rank better.'),
            self::check('links', $internal >= 1, 5, $internal >= 1 ? 'Links to other pages on your site.' : 'Link to a related page on your site, such as a market report or /homes search.'),
            self::check('alt', $noAlt === 0, 5, $noAlt === 0 ? 'Images have descriptions.' : 'Describe every image: ![what it shows](link).'),
            self::check('indexing', ! $post->noindex, 5, $post->noindex ? 'This post is hidden from search engines.' : 'Visible to search engines.'),
        ]);
    }

    /**
     * @return array{key: string, ok: bool, weight: int, message: string}
     */
    private static function check(string $key, bool $ok, int $weight, string $message): array
    {
        return ['key' => $key, 'ok' => $ok, 'weight' => $weight, 'message' => $message];
    }

    /**
     * @param  list<array{key: string, ok: bool, weight: int, message: string}>  $checks
     * @return array{score: int, checks: list<array{key: string, ok: bool, weight: int, message: string}>}
     */
    private static function score(array $checks): array
    {
        $total = array_sum(array_column($checks, 'weight'));
        $got = array_sum(array_map(fn (array $c): int => $c['ok'] ? $c['weight'] : 0, $checks));

        return ['score' => $total > 0 ? (int) round($got * 100 / $total) : 0, 'checks' => $checks];
    }
}
