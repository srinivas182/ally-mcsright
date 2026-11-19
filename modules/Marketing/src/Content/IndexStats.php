<?php

declare(strict_types=1);

namespace Modules\Marketing\Content;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use RuntimeException;
use stdClass;

/**
 * MK3: how much of a desk's published content search engines have indexed, from the Search Console service HQ (or the
 * brokerage) switched on. These numbers — not anyone's wish — move a desk up or down a level (Cadence).
 *
 * - Google: the URL Inspection API, for the desk's most recent published pages (up to 50 a day per desk, well within
 *   Google's daily quota). "Submitted and indexed" counts as indexed; a failing verdict counts as a warning.
 * - Bing: URL information from Bing Webmaster Tools; a page Bing has crawled counts as indexed.
 * - Typed in by hand: HQ types the numbers (saveManual).
 */
final class IndexStats
{
    public const SAMPLE = 50;

    public function __construct(private readonly OutsideServices $services) {}

    /** Fetch today's numbers for one desk. Returns false when no Search Console service is on (desk stays at level 1). */
    public function refresh(stdClass $desk): bool
    {
        $active = $this->services->active('search_console', (int) $desk->tenant_id > 0 ? (int) $desk->tenant_id : null);
        if ($active === null || $active['provider'] === 'manual') {
            return false;
        }
        $urls = DB::connection(Connections::control())->table('content_drafts')->where('desk_id', $desk->id)->where('status', 'published')->whereNotNull('published_url')
            ->orderByDesc('reviewed_at')->limit(self::SAMPLE)->pluck('published_url')->map(fn ($u): string => (string) $u)->values()->all();
        $urls = array_values($urls);
        if ($urls === []) {
            return false;
        }
        [$indexed, $warnings] = $active['provider'] === 'google' ? $this->google($active['keys'], $urls) : $this->bing($active['keys'], $urls);
        $this->store($desk, count($urls), $indexed, $warnings, $active['provider']);

        return true;
    }

    public function saveManual(stdClass $desk, int $submitted, int $indexed, int $warnings): void
    {
        $this->store($desk, max(0, $submitted), max(0, min($indexed, $submitted)), max(0, $warnings), 'manual');
    }

    private function store(stdClass $desk, int $submitted, int $indexed, int $warnings, string $source): void
    {
        DB::connection(Connections::control())->table('content_index_stats')->updateOrInsert(['desk_id' => $desk->id, 'day' => CarbonImmutable::now()->toDateString()],
            ['submitted' => $submitted, 'indexed' => $indexed, 'warnings' => $warnings, 'source' => $source, 'updated_at' => now(), 'created_at' => now()]);
    }

    /**
     * @param  array<string, string>  $keys
     * @param  list<string>  $urls
     * @return array{0: int, 1: int}
     */
    private function google(array $keys, array $urls): array
    {
        $token = $this->googleToken((string) ($keys['service_account'] ?? ''));
        $indexed = 0;
        $warnings = 0;
        foreach ($urls as $url) {
            $r = Http::timeout(20)->withToken($token)->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                ['inspectionUrl' => $url, 'siteUrl' => (string) ($keys['site_url'] ?? '')]);
            if ($r->failed()) {
                continue;
            }
            $result = (array) $r->json('inspectionResult.indexStatusResult', []);
            $indexed += str_contains((string) ($result['coverageState'] ?? ''), 'indexed') && ! str_contains(strtolower((string) ($result['coverageState'] ?? '')), 'not indexed') ? 1 : 0;
            $warnings += ($result['verdict'] ?? '') === 'FAIL' ? 1 : 0;
        }

        return [$indexed, $warnings];
    }

    /**
     * @param  array<string, string>  $keys
     * @param  list<string>  $urls
     * @return array{0: int, 1: int}
     */
    private function bing(array $keys, array $urls): array
    {
        $indexed = 0;
        foreach ($urls as $url) {
            $r = Http::timeout(20)->get('https://ssl.bing.com/webmaster/api.svc/json/GetUrlInfo', ['apikey' => (string) ($keys['api_key'] ?? ''),
                'siteUrl' => (string) ($keys['site_url'] ?? ''), 'url' => $url]);
            $indexed += $r->successful() && (string) $r->json('d.LastCrawledDate', '') !== '' ? 1 : 0;
        }

        return [$indexed, 0];
    }

    /** A Google access token from a service account key (JWT bearer grant, read-only Search Console scope). */
    private function googleToken(string $json): string
    {
        $sa = json_decode($json, true);
        if (! is_array($sa) || ! isset($sa['client_email'], $sa['private_key'])) {
            throw new RuntimeException('The Search Console service account key is not valid JSON.');
        }
        $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $unsigned = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode(['iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
        if (! openssl_sign($unsigned, $signature, (string) $sa['private_key'], 'sha256WithRSAEncryption')) {
            throw new RuntimeException('Could not sign with the Search Console service account key.');
        }
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$b64($signature)]);
        if ($r->failed()) {
            throw new RuntimeException('Google did not accept the Search Console service account.');
        }

        return (string) $r->json('access_token');
    }
}
