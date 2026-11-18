<?php

declare(strict_types=1);

namespace Modules\Core\Search;

use App\Models\User;
use Closure;

/**
 * Universal search (PW1, specs/core/17). Modules register what they can find; each provider only returns records the
 * person may see (it uses the module's own visibility rules), at most a few per kind, newest or best match first.
 */
final class SearchRegistry
{
    /** @var array<string, array{label: array{en: string, es: string}, order: int, find: Closure(User, string, int): list<array{title: string, subtitle: string|null, href: string}>}> */
    private array $providers = [];

    /**
     * @param  array{en: string, es: string}  $label  the group heading in each language
     * @param  Closure(User, string, int): list<array{title: string, subtitle: string|null, href: string}>  $find
     */
    public function register(string $key, array $label, int $order, Closure $find): void
    {
        $this->providers[$key] = ['label' => $label, 'order' => $order, 'find' => $find];
    }

    /** @return array<string, array{label: array{en: string, es: string}, order: int, find: Closure(User, string, int): list<array{title: string, subtitle: string|null, href: string}>}> */
    public function all(): array
    {
        $providers = $this->providers;
        uasort($providers, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $providers;
    }
}
