<?php

declare(strict_types=1);

namespace Modules\Team\Help;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;

/**
 * SX5: "Help me" on every page — the how-to for the page the person is on, its 60-second video (when HQ has added
 * one), a printable cheat sheet, and "call me back".
 *
 * Pages are matched by the start of their address (the longest match wins). Videos are kept by HQ per page (control
 * database, help_videos) and must be YouTube, Vimeo or an https MP4; YouTube plays from youtube-nocookie.com.
 */
final class PageHelp
{
    /** Start of the page's address → help article. */
    public const PAGES = [
        '/' => 'getting-started', '/simple' => 'getting-started', '/agent' => 'getting-started', '/dashboard' => 'getting-started', '/today' => 'leads-and-contacts',
        '/contacts' => 'add-a-contact', '/leads' => 'leads-and-contacts', '/inbox' => 'texting-rules', '/deals' => 'deals-and-documents', '/forms' => 'forms-and-signing',
        '/sign' => 'forms-and-signing', '/smart-forms' => 'smart-forms', '/finance' => 'getting-paid', '/payouts' => 'getting-paid', '/pricing' => 'explain-and-compare',
        '/settings/website' => 'website-and-calculators', '/settings/security' => 'security', '/team' => 'team-chat', '/help' => 'getting-help',
    ];

    /** Video addresses we play. */
    private const VIDEO = '#^https://(www\.)?(youtube\.com/watch\?v=|youtu\.be/|youtube-nocookie\.com/embed/|vimeo\.com/|player\.vimeo\.com/video/)[\w\-?=&/]+$|^https://[\w.\-]+/[\w\-./]+\.mp4$#i';

    public function __construct(private readonly HelpCentre $help) {}

    /**
     * @return array{article: array{slug: string, title: string, summary: string}|null, video: array{title: string, url: string, kind: string}|null}
     */
    public function for(string $path, bool $leader): array
    {
        $prefix = self::match($path, array_keys(self::PAGES));
        $a = $prefix !== null ? $this->help->find(self::PAGES[$prefix], $leader) : null;
        $videos = DB::connection(Connections::control())->table('help_videos')->pluck('url', 'path')->all();
        $vPrefix = self::match($path, array_map('strval', array_keys($videos)));
        $video = null;
        if ($vPrefix !== null) {
            $row = DB::connection(Connections::control())->table('help_videos')->where('path', $vPrefix)->first(['title', 'url']);
            if ($row !== null) {
                $video = ['title' => (string) $row->title, 'url' => self::embed((string) $row->url), 'kind' => str_ends_with(strtolower((string) $row->url), '.mp4') ? 'file' : 'embed'];
            }
        }

        return ['article' => $a !== null ? ['slug' => $a['slug'], 'title' => $a['title'], 'summary' => $a['summary']] : null, 'video' => $video];
    }

    /** HQ: set (or, with an empty address, remove) the video for a page. */
    public static function setVideo(string $path, string $title, string $url, int $by): void
    {
        $path = trim(strtolower($path));
        // MN3: a lesson in a menu's help panel has its own address, such as guide:crm:new-leads.
        $guide = preg_match('#^guide:[a-z0-9\-]{1,40}:[a-z0-9\-]{1,40}$#', $path) === 1;
        $path = $guide ? $path : '/'.trim($path, '/ ');
        if (! $guide && preg_match('#^/[a-z0-9\-/]*$#', $path) !== 1) {
            throw ValidationException::withMessages(['path' => 'Use the start of the page\'s address, such as /contacts, or a help-panel lesson, such as guide:crm:new-leads.']);
        }
        $db = DB::connection(Connections::control())->table('help_videos');
        if (trim($url) === '') {
            $db->where('path', $path)->delete();

            return;
        }
        if (preg_match(self::VIDEO, trim($url)) !== 1) {
            throw ValidationException::withMessages(['url' => 'Use a YouTube or Vimeo link, or an https link to an MP4 file.']);
        }
        $db->updateOrInsert(['path' => $path], ['title' => mb_substr(trim($title) ?: 'How this page works', 0, 120), 'url' => trim($url), 'updated_by' => $by, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** @return list<array{path: string, title: string, url: string}> */
    public static function videos(): array
    {
        return array_values(DB::connection(Connections::control())->table('help_videos')->orderBy('path')->get(['path', 'title', 'url'])
            ->map(fn (object $v): array => ['path' => (string) $v->path, 'title' => (string) $v->title, 'url' => (string) $v->url])->all());
    }

    /** YouTube and Vimeo page links as their player address; MP4 files as they are. */
    public static function embed(string $url): string
    {
        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube-nocookie\.com/embed/)([\w\-]{6,20})#', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d{5,12})#', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/'.$m[1].'?dnt=1';
        }

        return $url;
    }

    /** @param list<string> $prefixes */
    private static function match(string $path, array $prefixes): ?string
    {
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $best = null;
        foreach ($prefixes as $p) {
            $hit = $p === '/' ? $path === '/' : ($path === $p || str_starts_with($path, rtrim($p, '/').'/'));
            if ($hit && ($best === null || strlen($p) > strlen($best))) {
                $best = $p;
            }
        }

        return $best ?? (in_array('/', $prefixes, true) ? '/' : null);
    }
}
