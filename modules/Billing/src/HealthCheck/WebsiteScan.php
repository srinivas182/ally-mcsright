<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\SafeUrl;
use Throwable;

/**
 * The Health Check's website check (specs/billing/06). Reads only the public home page and three small files
 * (robots.txt, sitemap.xml, llms.txt) of a site the broker says they manage, over HTTPS, to public addresses only
 * (each hop is checked and the connection is pinned to the checked IP). It never downloads images or other pages,
 * and keeps no copy of the page: only which checks passed.
 */
final class WebsiteScan
{
    private const MAX_BYTES = 1_500_000;

    /** key => [label, weight, fix] */
    public const CHECKS = [
        'https' => ['Opens on a secure https:// address', 2, 'Serve the site over HTTPS; browsers mark other sites "Not secure".'],
        'speed' => ['Home page answers in under 2.5 seconds', 1, 'Slow pages lose visitors and rank lower.'],
        'mobile' => ['Set up for phones', 2, 'Add a mobile layout; most home buyers search on their phone.'],
        'title' => ['Has a clear page title', 1, 'Give the home page a title of 10–70 characters naming the brokerage and area.'],
        'description' => ['Has a search description', 1, 'Add a description of 50–170 characters; Google shows it under your name.'],
        'heading' => ['Has a main heading', 1, 'Add one main heading saying who you are and where you work.'],
        'language' => ['Says which language it is in', 1, 'Mark the page language so screen readers and search engines read it correctly.'],
        'schema' => ['Tells search engines it is a real estate business', 2, 'Add RealEstateAgent or Organization markup so Google and AI answers know who you are.'],
        'faq' => ['Answers common questions in a way AI tools can quote', 1, 'Add a short FAQ with FAQPage markup; AI answers quote pages like this.'],
        'llms' => ['Has an llms.txt guide for AI assistants', 1, 'Publish /llms.txt so AI assistants can find your key pages.'],
        'sitemap' => ['Has a sitemap', 1, 'Publish /sitemap.xml so new listings and pages are found quickly.'],
        'robots' => ['Lets search engines in', 1, 'Your robots.txt blocks all search engines; remove "Disallow: /".'],
        'search' => ['Visitors can search for homes', 2, 'Add MLS home search; it is the main reason buyers visit.'],
        'spanish' => ['Has Spanish pages', 1, 'Add Spanish pages to reach Spanish-speaking buyers and sellers.'],
        'fair_housing' => ['Shows the Equal Housing Opportunity notice', 1, 'Show the Equal Housing Opportunity logo or wording on every page.'],
        'privacy' => ['Links to a privacy policy', 1, 'Link to a privacy policy, especially if the site collects leads.'],
        'images' => ['Pictures have descriptions', 1, 'Describe images (alt text) for visitors who use screen readers.'],
        'contact' => ['Easy to get in touch', 1, 'Show a phone number, email or contact form on the home page.'],
    ];

    /**
     * @return array{status: string, message: string|null, url: string, checks: list<array{key: string, label: string, ok: bool, fix: string}>, score: int, theme_color: string|null}
     */
    public function run(string $url): array
    {
        $url = self::normalise($url);
        $fail = fn (string $m): array => ['status' => 'failed', 'message' => $m, 'url' => $url, 'checks' => [], 'score' => 0, 'theme_color' => null];
        try {
            $started = hrtime(true);
            $home = $this->fetch($url);
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        } catch (ValidationException $e) {
            return $fail((string) collect($e->errors())->flatten()->first());
        } catch (Throwable) {
            return $fail('We could not open the site on a secure https:// address.');
        }
        if ($home === null || $home['status'] >= 400 || stripos($home['type'], 'html') === false) {
            return $fail('The home page did not answer with a web page.');
        }
        $root = (string) preg_replace('#^(https://[^/]+).*$#', '$1', $home['url']);
        $file = function (string $path) use ($root): ?string {
            try {
                $r = $this->fetch($root.$path);
            } catch (Throwable) {
                return null;
            }

            return $r !== null && $r['status'] === 200 ? $r['body'] : null;
        };

        $dom = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$home['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $x = new DOMXPath($dom);
        $nodes = fn (string $q): array => ($list = $x->query($q)) === false ? [] : array_values(iterator_to_array($list));
        $attr = fn (string $q, string $a): string => ($n = $nodes($q)[0] ?? null) instanceof DOMElement ? trim($n->getAttribute($a)) : '';
        $count = fn (string $q): int => count($nodes($q));
        $text = mb_strtolower((string) preg_replace('/\s+/', ' ', $dom->textContent));
        $types = self::schemaTypes($x);
        $robots = $file('/robots.txt');
        $titleNode = $nodes('//title')[0] ?? null;
        $title = $titleNode instanceof DOMElement ? trim($titleNode->textContent) : '';
        $description = $attr('//meta[translate(@name,"DESCRIPTION","description")="description"]', 'content');
        $images = $count('//img');
        $described = $count('//img[normalize-space(@alt)!=""]');
        $hrefs = mb_strtolower(implode(' ', array_map(fn ($n): string => $n instanceof DOMElement ? $n->getAttribute('href').' '.$n->textContent : '', $nodes('//a[@href]'))));
        $llms = $file('/llms.txt');

        $ok = [
            'https' => true,
            'speed' => $ms < 2500,
            'mobile' => str_contains(mb_strtolower($attr('//meta[@name="viewport"]', 'content')), 'width=device-width'),
            'title' => mb_strlen($title) >= 10 && mb_strlen($title) <= 70,
            'description' => mb_strlen($description) >= 50 && mb_strlen($description) <= 170,
            'heading' => $count('//h1') > 0,
            'language' => $attr('//html', 'lang') !== '',
            'schema' => array_intersect($types, ['RealEstateAgent', 'RealEstateBroker', 'Organization', 'LocalBusiness', 'Corporation']) !== [],
            'faq' => in_array('FAQPage', $types, true),
            'llms' => $llms !== null && trim($llms) !== '' && ! str_starts_with(ltrim($llms), '<'),
            'sitemap' => $file('/sitemap.xml') !== null || ($robots !== null && stripos($robots, 'sitemap:') !== false),
            'robots' => $robots === null || ! preg_match('/user-agent:\s*\*\s*(?:\R(?!user-agent)[^\n]*)*?\Rdisallow:\s*\/\s*$/im', $robots),
            'search' => (bool) preg_match('/(search|listings|homes-for-sale|properties|idx|mls)/', $hrefs),
            'spanish' => $count('//link[starts-with(@hreflang,"es")]') > 0 || str_contains($hrefs, '/es/') || str_contains($text, 'español'),
            'fair_housing' => str_contains($text, 'equal housing') || str_contains($text, 'fair housing') || $count('//img[contains(translate(@alt,"EQUALHOSING","equalhosing"),"equal housing")]') > 0,
            'privacy' => str_contains($hrefs, 'privacy'),
            'images' => $images === 0 || $described / $images >= 0.8,
            'contact' => $count('//a[starts-with(@href,"tel:") or starts-with(@href,"mailto:")]') > 0 || $count('//form') > 0,
        ];
        $checks = [];
        $earned = 0;
        $total = 0;
        foreach (self::CHECKS as $key => [$label, $weight, $fix]) {
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok[$key], 'fix' => $fix];
            $total += $weight;
            $earned += $ok[$key] ? $weight : 0;
        }
        $theme = $attr('//meta[@name="theme-color"]', 'content');

        return ['status' => 'done', 'message' => null, 'url' => $home['url'], 'checks' => $checks, 'score' => (int) round(100 * $earned / $total),
            'theme_color' => preg_match('/^#[0-9a-f]{6}$/i', $theme) ? mb_strtolower($theme) : null];
    }

    /** "example.com" and "http://example.com" are checked as https://example.com/. */
    public static function normalise(string $url): string
    {
        $url = trim($url);
        $url = (string) preg_replace('#^http://#i', 'https://', $url);
        if (! preg_match('#^https://#i', $url)) {
            $url = 'https://'.$url;
        }
        if (parse_url($url, PHP_URL_PATH) === null) {
            $url .= '/';
        }

        return $url;
    }

    /**
     * Up to three redirects, each to a checked public HTTPS address; the body is cut at 1.5 MB.
     *
     * @return array{status: int, type: string, body: string, url: string}|null
     */
    private function fetch(string $url): ?array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $target = SafeUrl::check($url);
            $r = Http::withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]]])
                ->withHeaders(['User-Agent' => 'RightAlly Health Check (+https://'.config('core.hq_host').'/health-check)', 'Accept' => 'text/html,text/plain,application/xml'])
                ->timeout(8)->connectTimeout(4)->get($url);
            if ($r->redirect() && $r->header('Location') !== '') {
                $next = $r->header('Location');
                $url = str_starts_with($next, '/') ? (string) preg_replace('#^(https://[^/]+).*$#', '$1', $url).$next : $next;

                continue;
            }

            return ['status' => $r->status(), 'type' => $r->header('Content-Type'), 'body' => substr($r->body(), 0, self::MAX_BYTES), 'url' => $url];
        }

        return null;
    }

    /** @return list<string> every schema.org type in the page's JSON-LD, including inside @graph */
    private static function schemaTypes(DOMXPath $x): array
    {
        $types = [];
        $walk = function (mixed $node) use (&$walk, &$types): void {
            if (! is_array($node)) {
                return;
            }
            foreach ((array) ($node['@type'] ?? []) as $t) {
                if (is_string($t)) {
                    $types[] = $t;
                }
            }
            foreach ($node as $k => $v) {
                if ($k !== '@type' && is_array($v)) {
                    $walk($v);
                }
            }
        };
        $scripts = $x->query('//script[@type="application/ld+json"]');
        foreach ($scripts === false ? [] : $scripts as $script) {
            $walk(json_decode((string) ($script instanceof DOMElement ? $script->textContent : ''), true));
        }

        return array_values(array_unique($types));
    }
}
