<?php

declare(strict_types=1);

namespace Modules\Core\Connect;

use InvalidArgumentException;

/** The apps people can connect (specs/connect/00). Modules add theirs when they boot. */
final class AppCatalog
{
    /** @var array<string, App> */
    private array $apps = [];

    public function add(App $app): void
    {
        if (! isset(App::CATEGORIES[$app->category])) {
            throw new InvalidArgumentException("Unknown app category [{$app->category}].");
        }
        $this->apps[$app->key] = $app;
    }

    public function find(string $key): ?App
    {
        return $this->apps[$key] ?? null;
    }

    /** @return list<App> by category order, then available first, then name */
    public function all(): array
    {
        $order = array_flip(array_keys(App::CATEGORIES));
        $apps = array_values($this->apps);
        usort($apps, fn (App $a, App $b): int => [$order[$a->category], $a->available() ? 0 : 1, $a->name] <=> [$order[$b->category], $b->available() ? 0 : 1, $b->name]);

        return $apps;
    }
}
