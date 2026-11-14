<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Sites\Models\Site;

/**
 * Redirects for addresses that moved (specs/websites/08): a brokerage moving from another website provider keeps its
 * Google rankings by sending old addresses to the new pages. Checked only when a page is not found.
 */
final class Redirects
{
    public const MAX = 2000;

    /**
     * @return array{to: string, code: int}|null
     */
    public function find(Site $site, string $path): ?array
    {
        $path = self::normalise($path);
        $row = DB::connection(Connections::cell())->table('site_redirects')->where('site_id', $site->id)->where('from_path', $path)->first();
        if ($row === null) {
            return null;
        }
        DB::connection(Connections::cell())->table('site_redirects')->where('id', $row->id)->update(['hits' => (int) $row->hits + 1, 'last_hit_at' => CarbonImmutable::now()]);

        return ['to' => (string) $row->to_url, 'code' => (int) $row->code];
    }

    public function save(Site $site, string $from, string $to, int $code = 301): void
    {
        $from = self::normalise($from);
        $to = trim($to);
        if ($from === '/' || preg_match('#^/[^\s]{1,299}$#', $from) !== 1) {
            throw ValidationException::withMessages(['from' => 'Enter the old address, like /listings/old-page.html.']);
        }
        if (preg_match('#^/[A-Za-z0-9/_\-?=&%.]{0,490}$#', $to) !== 1 && preg_match('#^https://[^\s"<>]{1,490}$#', $to) !== 1) {
            throw ValidationException::withMessages(['to' => 'Enter a page on this site (starting with /) or an https link.']);
        }
        if (self::normalise($to) === $from) {
            throw ValidationException::withMessages(['to' => 'A page cannot redirect to itself.']);
        }
        $db = DB::connection(Connections::cell())->table('site_redirects');
        if (! (clone $db)->where('site_id', $site->id)->where('from_path', $from)->exists() && (clone $db)->where('site_id', $site->id)->count() >= self::MAX) {
            throw ValidationException::withMessages(['from' => 'You have reached '.self::MAX.' redirects.']);
        }
        $db->updateOrInsert(['site_id' => $site->id, 'from_path' => $from], ['tenant_id' => $site->tenant_id, 'to_url' => $to, 'code' => in_array($code, [301, 302], true) ? $code : 301,
            'updated_at' => CarbonImmutable::now(), 'created_at' => CarbonImmutable::now()]);
    }

    /**
     * Adds redirects from pasted lines "old new" or "old,new"; returns how many were saved and the lines that failed.
     *
     * @return array{saved: int, failed: list<string>}
     */
    public function import(Site $site, string $lines): array
    {
        $saved = 0;
        $failed = [];
        foreach (array_slice(preg_split('/\r?\n/', $lines) ?: [], 0, 1000) as $line) {
            $parts = preg_split('/[\s,]+/', trim($line)) ?: [];
            if (count($parts) < 2) {
                if (trim($line) !== '') {
                    $failed[] = mb_substr($line, 0, 120);
                }

                continue;
            }
            try {
                $this->save($site, self::pathOf($parts[0]), $parts[1]);
                $saved++;
            } catch (ValidationException) {
                $failed[] = mb_substr($line, 0, 120);
            }
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /** Old addresses may be pasted as full links from the old site. */
    private static function pathOf(string $v): string
    {
        return str_starts_with($v, 'http') ? ((string) (parse_url($v, PHP_URL_PATH) ?: '/')).((string) (parse_url($v, PHP_URL_QUERY) ? '?'.parse_url($v, PHP_URL_QUERY) : '')) : $v;
    }

    private static function normalise(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');

        return mb_substr($path !== '/' ? rtrim($path, '/') : $path, 0, 300);
    }
}
