<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| PS1 (specs/core/14): the busiest list pages run the same number of database queries whether a brokerage has 5
| agents or 50. A page that grows with the data (a query per row) fails here before it reaches a big brokerage.
*/

function pageQueries(User $as, string $url): int
{
    foreach (DB::getConnections() as $c) {
        $c->enableQueryLog();
        $c->flushQueryLog();
    }
    test()->actingAs($as)->get($url)->assertOk();
    $n = 0;
    foreach (DB::getConnections() as $c) {
        $n += count($c->getQueryLog());
        $c->disableQueryLog();
    }

    return $n;
}

function growBrokerage(int $agents, int $dealsEach): void
{
    foreach (User::factory()->count($agents)->create() as $a) {
        foreach (range(1, $dealsEach) as $n) {
            test()->actingAs($a)->post('/deals', ['type' => 'buyer', 'street' => $a->id.'-'.$n.' Elm St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '400000']);
        }
    }
}

it('keeps the deals list, directory, agent activity and AI assistants pages flat as a brokerage grows', function (string $url): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    growBrokerage(3, 2);
    $small = pageQueries($owner, $url);
    growBrokerage(12, 3);
    $large = pageQueries($owner, $url);

    expect($large - $small)->toBeLessThanOrEqual(2, "{$url} ran {$small} queries for a small brokerage and {$large} for a bigger one.");
})->with(['/deals', '/directory', '/engage/activity', '/apps']);
