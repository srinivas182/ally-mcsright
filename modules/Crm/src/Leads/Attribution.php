<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

/**
 * Which channel a lead came from (specs/grow/04, websites/04): AI answer engines are their own channel, so a
 * brokerage can see what its AEO work brings in, next to organic search (SEO), paid, social and the rest.
 */
final class Attribution
{
    public const CHANNELS = ['ai_search', 'organic', 'paid', 'social', 'portal', 'email', 'referral_site', 'direct'];

    /** Hosts (and utm_source values) of AI answer engines. */
    public const AI_ENGINES = ['chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'gemini.google.com', 'bard.google.com', 'copilot.microsoft.com',
        'claude.ai', 'meta.ai', 'you.com', 'phind.com', 'grok.com', 'deepseek.com', 'chat.deepseek.com', 'chatgpt'];

    private const SEARCH = ['google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.com', 'yahoo.com', 'ecosia.org', 'search.brave.com', 'baidu.com', 'yandex.'];

    private const SOCIAL = ['facebook.com', 'fb.com', 'instagram.com', 'l.instagram.com', 'linkedin.com', 'lnkd.in', 'twitter.com', 'x.com', 't.co', 'tiktok.com',
        'youtube.com', 'pinterest.com', 'nextdoor.com', 'reddit.com', 'threads.net'];

    private const PORTALS = ['zillow.com', 'realtor.com', 'homes.com', 'trulia.com', 'redfin.com', 'apartments.com'];

    /**
     * @param  array<string, string>  $utm
     */
    public static function channel(?string $referrer, array $utm, ?string $source = null): string
    {
        $utmSource = mb_strtolower(trim($utm['utm_source'] ?? ''));
        $medium = mb_strtolower(trim($utm['utm_medium'] ?? ''));
        $host = self::host($referrer);

        if (self::matches($utmSource, self::AI_ENGINES) || self::matches($host, self::AI_ENGINES)) {
            return 'ai_search';
        }
        if (in_array($medium, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_social', 'paidsocial', 'display', 'ads'], true) || isset($utm['gclid'])) {
            return 'paid';
        }
        if (in_array($source, ['zillow', 'realtor_com', 'homes_com'], true) || self::matches($host, self::PORTALS) || self::matches($utmSource, self::PORTALS)) {
            return 'portal';
        }
        if ($medium === 'email' || $medium === 'newsletter') {
            return 'email';
        }
        if (in_array($medium, ['social', 'social-media', 'social_media'], true) || self::matches($host, self::SOCIAL) || self::matches($utmSource, self::SOCIAL) || in_array($source, ['facebook'], true)) {
            return 'social';
        }
        if ($medium === 'organic' || self::matches($host, self::SEARCH)) {
            return 'organic';
        }
        if ($host !== '') {
            return 'referral_site';
        }

        return 'direct';
    }

    public static function host(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', mb_strtolower($host)) ?? '' : '';
    }

    /**
     * UTM tags and click ids from an array of fields and/or the query string of a landing URL.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string>
     */
    public static function utm(array $fields, ?string $landingUrl = null): array
    {
        $query = [];
        $q = parse_url((string) $landingUrl, PHP_URL_QUERY);
        if (is_string($q)) {
            parse_str($q, $query);
        }
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'] as $key) {
            $value = $fields[$key] ?? $query[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = mb_substr(trim((string) $value), 0, 150);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $list
     */
    private static function matches(string $host, array $list): bool
    {
        if ($host === '') {
            return false;
        }
        foreach ($list as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain) || (str_ends_with($domain, '.') && (str_starts_with($host, $domain) || str_contains($host, '.'.$domain)))) {
                return true;
            }
        }

        return false;
    }
}
