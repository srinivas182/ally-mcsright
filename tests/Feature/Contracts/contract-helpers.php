<?php

declare(strict_types=1);
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * Shared by the page contract tests: the data each page's React component destructures from its props,
 * minus anything its Props type declares optional (name?:).
 */
if (! function_exists('requiredProps')) {
    /**
     * @return list<string>
     */
    function requiredProps(string $component): array
    {
        $source = (string) file_get_contents(base_path("resources/js/pages/{$component}.tsx"));
        if (preg_match('/export default function \w+\(\{([^}]*)\}\s*:/s', $source, $m) !== 1) {
            return [];
        }
        $names = array_values(array_filter(array_map(fn ($p) => trim((string) preg_replace('/[:=].*$/s', '', trim($p))), explode(',', $m[1]))));
        preg_match_all('/^\s*(\w+)\?\s*:/m', $source, $optional);

        return array_values(array_diff($names, $optional[1]));
    }

    function assertPageContract(TestResponse $response, string $component): void
    {
        $required = requiredProps($component);
        $response->assertOk()->assertInertia(function (AssertableInertia $page) use ($component, $required): void {
            $page->component($component);
            foreach ($required as $prop) {
                $page->has($prop);
            }
        });
    }
}
