<?php

declare(strict_types=1);

namespace Modules\Core\Switches;

/**
 * The module registry for switches and seats (FL1, specs/core/16): each area of the product that can be switched on or
 * off for a brokerage, the pages it owns, and whether it can be limited to people holding a seat. Modules add their
 * own areas here; a new area starts on for everyone unless it says otherwise (a beta starts off and is rolled out).
 */
final class Areas
{
    /** @var array<string, array{key: string, label: string, about: string, paths: list<string>, seats: bool, default_on: bool}> */
    private array $areas = [];

    /**
     * @param  list<string>  $paths  page paths the area owns (a path and everything under it)
     */
    public function register(string $key, string $label, string $about, array $paths, bool $seats = false, bool $defaultOn = true): void
    {
        $this->areas[$key] = ['key' => $key, 'label' => $label, 'about' => $about, 'paths' => $paths, 'seats' => $seats, 'default_on' => $defaultOn];
    }

    /** @return array<string, array{key: string, label: string, about: string, paths: list<string>, seats: bool, default_on: bool}> */
    public function all(): array
    {
        $areas = $this->areas;
        uasort($areas, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $areas;
    }

    public function has(string $key): bool
    {
        return isset($this->areas[$key]);
    }

    /** @return array{key: string, label: string, about: string, paths: list<string>, seats: bool, default_on: bool}|null */
    public function get(string $key): ?array
    {
        return $this->areas[$key] ?? null;
    }

    /** The area a page belongs to (the longest matching path wins), or null for pages no area owns. */
    public function forPath(string $path): ?string
    {
        $path = '/'.ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $best = null;
        $length = 0;
        foreach ($this->areas as $key => $area) {
            foreach ($area['paths'] as $p) {
                if (($path === $p || str_starts_with($path, rtrim($p, '/').'/')) && strlen($p) > $length) {
                    $best = $key;
                    $length = strlen($p);
                }
            }
        }

        return $best;
    }
}
