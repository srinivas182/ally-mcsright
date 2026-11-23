<?php

declare(strict_types=1);

namespace Modules\Sites\Content;

use Illuminate\Http\Request;
use Modules\Listings\Samples\SampleHomes;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Modules\Sites\Support\Designs;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * TP4: what one visitor of the demo website chose: a design, their brokerage's name, their area and state. Read from
 * the preview link (?design=&name=&area=&state=) and kept in a cookie, so every page and link of the demo shows their
 * choice. Nothing is saved: the design is swapped when the website is loaded and the name and area are written into
 * the page as it is sent. The demo is never indexed or cached.
 */
final class DemoView
{
    public const COOKIE = 'ra_demo';

    public const NAME = 'Your Brokerage';

    public const AREA = 'Your City';

    private bool $active = false;

    private ?string $design = null;

    private string $name = '';

    private string $area = '';

    private ?string $state = null;

    private bool $fromLink = false;

    public function begin(Request $request): void
    {
        $this->active = true;
        $saved = json_decode(base64_decode((string) $request->cookies->get(self::COOKIE, ''), true) ?: '[]', true);
        $saved = is_array($saved) ? $saved : [];
        $this->fromLink = $request->query->has('design');
        $pick = fn (string $k): mixed => $this->fromLink ? $request->query($k) : ($saved[$k] ?? null);
        $design = $pick('design');
        $this->design = is_string($design) && array_key_exists($design, (array) config('sites.templates')) ? $design : 'modern';
        $clean = static fn (mixed $v): string => is_string($v) ? mb_substr(trim(strip_tags($v)), 0, 80) : '';
        $this->name = $clean($pick('name'));
        $this->area = $clean($pick('area'));
        $state = strtoupper($clean($pick('state')));
        $this->state = isset(SampleHomes::MARKETS[$state]) ? $state : null;
        // The sample homes shown are the visitor's state's (Listings reads this for this request only).
        config(['listings.sample_state' => $this->state]);
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function design(): ?string
    {
        return $this->active ? $this->design : null;
    }

    /** LP1: the visitor is looking at a landing page design (one page about the brokerage, no home search). */
    public function landing(): bool
    {
        return $this->active && Designs::isLanding($this->design);
    }

    /**
     * LP1: the landing page design's own pages, shown instead of the demo's full website pages.
     *
     * @return list<array{path: string, title: string, in_menu: bool, meta: string, sections: list<array<string, mixed>>}>
     */
    public function landingPages(): array
    {
        return $this->landing() ? StarterSite::landingPages(['name' => self::NAME, 'area' => self::AREA, 'state' => $this->state], $this->design) : [];
    }

    /** LP1: the landing design's page at this path, as an unsaved page, or null. */
    public function landingPage(string $path): ?SitePage
    {
        foreach ($this->landingPages() as $p) {
            if ($p['path'] === $path) {
                return (new SitePage)->forceFill(['path' => $p['path'], 'title' => $p['title'], 'meta_description' => $p['meta'], 'sections' => $p['sections'], 'status' => 'published', 'in_menu' => $p['in_menu']]);
            }
        }

        return null;
    }

    /** Called when the demo's website is loaded: it shows in the chosen design, with that design's colours and banner photo. */
    public function apply(Site $site): void
    {
        if (! $this->active || $site->kind !== 'brokerage' || $this->design === null) {
            return;
        }
        $brand = (array) config("sites.templates.{$this->design}.brand", []);
        $site->setAttribute('template', $this->design);
        $site->setAttribute('brand', $brand + ['hero_image' => StarterSite::heroPhoto($this->design)]);
    }

    /** The page as sent: the visitor's name and area in place of the placeholders, never indexed, never cached. */
    public function finish(Response $response): Response
    {
        if (! $this->active) {
            return $response;
        }
        $content = $response->getContent();
        if (is_string($content) && str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'html')) {
            $response->setContent(str_replace([self::NAME, self::AREA], [e($this->name !== '' ? $this->name : self::NAME), e($this->area !== '' ? $this->area : 'your city')], $content));
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($this->fromLink) {
            $value = base64_encode((string) json_encode(['design' => $this->design, 'name' => $this->name, 'area' => $this->area, 'state' => $this->state]));
            $response->headers->setCookie(Cookie::create(self::COOKIE, $value, time() + 86_400, '/', null, null, true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    public function reset(): void
    {
        $this->active = false;
        $this->design = null;
        $this->name = $this->area = '';
        $this->state = null;
        $this->fromLink = false;
        config(['listings.sample_state' => null]);
    }
}
