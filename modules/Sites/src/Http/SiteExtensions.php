<?php

declare(strict_types=1);

namespace Modules\Sites\Http;

use Closure;
use Illuminate\Routing\Router;

/**
 * Pages other modules add to the public website (e.g. Recruiting's /careers, specs/grow/17), without the website
 * knowing about them: their routes (matched before the page catch-all) and, when live, their place in sitemap.xml
 * and llms.txt.
 */
final class SiteExtensions
{
    /** @var list<Closure(Router): void> */
    private array $routes = [];

    /** @var list<Closure(): list<array{path: string, title: string, description: string}>> */
    private array $pages = [];

    /** @param  Closure(Router): void  $routes */
    public function routes(Closure $routes): void
    {
        $this->routes[] = $routes;
    }

    /** @param  Closure(): list<array{path: string, title: string, description: string}>  $pages  only the pages that are live now */
    public function pages(Closure $pages): void
    {
        $this->pages[] = $pages;
    }

    public function apply(Router $router): void
    {
        foreach ($this->routes as $add) {
            $add($router);
        }
    }

    /** @return list<array{path: string, title: string, description: string}> */
    public function livePages(): array
    {
        $out = [];
        foreach ($this->pages as $pages) {
            foreach ($pages() as $p) {
                $out[] = $p;
            }
        }

        return $out;
    }
}
