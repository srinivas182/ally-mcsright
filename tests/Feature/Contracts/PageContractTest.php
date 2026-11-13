<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Page contracts: each page's React component names the data it destructures from its props. This test
 * reads those names from the page's source and renders the page for the right user, failing if the server
 * does not send any non-optional one. It exists because edits to a page's data have silently missed before,
 * and a missing prop only crashes in the browser.
 */

/**
 * @return list<string> required prop names the page destructures
 */
function requiredProps(string $component): array
{
    $source = (string) file_get_contents(base_path("resources/js/pages/{$component}.tsx"));
    if (preg_match('/export default function \w+\(\{([^}]*)\}\s*:/s', $source, $m) !== 1) {
        return [];
    }
    $names = array_values(array_filter(array_map(fn ($p) => trim((string) preg_replace('/[:=].*$/s', '', trim($p))), explode(',', $m[1]))));
    // Props declared optional in the page's type (name?:) may be absent.
    preg_match_all('/^\s*(\w+)\?\s*:/m', $source, $optional);

    return array_values(array_diff($names, $optional[1]));
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
});

it('sends every page the data its component needs', function (string $who, string $url, string $component): void {
    $user = $who === 'agent' ? $this->agent : $this->owner;
    $required = requiredProps($component);
    expect($required)->not->toBe([]);

    $this->actingAs($user)->get($url)->assertOk()->assertInertia(function (Assert $page) use ($component, $required): void {
        $page->component($component);
        foreach ($required as $prop) {
            $page->has($prop);
        }
    });
})->with([
    'payouts' => ['owner', '/finance/payouts', 'finance/payouts'],
    'money received' => ['owner', '/finance/receipts', 'finance/receipts'],
    'agent billing' => ['owner', '/finance/agent-billing', 'finance/agent-billing'],
    'tax' => ['owner', '/finance/tax', 'finance/tax'],
    'trust' => ['owner', '/finance/trust', 'finance/trust'],
    'my money' => ['agent', '/my-money', 'finance/my-money'],
    'statement' => ['agent', '/my-money/statement', 'finance/statement'],
    'roster' => ['owner', '/roster', 'roster/index'],
    'my roster' => ['agent', '/roster/me', 'roster/agent'],
    'directory' => ['agent', '/directory', 'roster/directory'],
    'deals' => ['owner', '/deals', 'deals/index'],
    'review queue' => ['owner', '/deals/review', 'deals/review'],
    'transactions settings' => ['owner', '/settings/transactions', 'settings/transactions'],
]);
