<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;

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

it('keeps the busiest list pages flat as a brokerage grows', function (string $url): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    growBrokerage(3, 2);
    $small = pageQueries($owner, $url);
    growBrokerage(12, 3);
    $large = pageQueries($owner, $url);

    expect($large - $small)->toBeLessThanOrEqual(2, "{$url} ran {$small} queries for a small brokerage and {$large} for a bigger one.");
})->with(['/deals', '/directory', '/engage/activity', '/apps', '/settings/credits', '/roster', '/insights/database', '/contacts', '/tasks', '/users', '/agent', '/finance/payouts', '/compensation/plans', '/compensation/revenue-share', '/insights', '/contacts/board', '/deals/money', '/listings', '/learn', '/outreach', '/audit', '/settings/approvals', '/finance/receipts', '/finance/statements', '/finance/trust', '/finance/journals', '/finance/agent-billing', '/recruiting', '/reviews', '/studio', '/insights/reports']); // PS1, PS2, S59

/** S59: contacts with a task and a note each, spread over the agents (company contacts, so the owner sees them). */
function growContacts(int $agents, int $each): void
{
    static $n = 0;
    foreach (User::factory()->count($agents)->create() as $a) {
        foreach (range(1, $each) as $i) {
            $n++;
            $c = app(ManageContacts::class)->create(['first_name' => 'P'.$n, 'last_name' => 'Person', 'email' => "p{$n}@x.test",
                'phone' => '512555'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'source' => 'zillow', 'sphere' => 'company'], $a);
            app(Tasks::class)->create($c, ['title' => 'Call '.$n, 'due' => 'today'], $a);
            app(Timeline::class)->system($c, 'Spoke about 78704', $a->id);
        }
    }
}

it('keeps the contact pages flat as the database grows', function (string $url): void {
    $this->onTenantHost('acme');
    $owner = User::factory()->owner()->create();
    growContacts(2, 3);
    $small = pageQueries($owner, $url);
    growContacts(6, 5);
    $large = pageQueries($owner, $url);

    expect($large - $small)->toBeLessThanOrEqual(2, "{$url} ran {$small} queries for a small database and {$large} for a bigger one.");
})->with(['/contacts', '/contacts/board', '/contacts/pond', '/contacts/duplicates', '/contacts/work', '/tasks', '/today', '/leads', '/leads/report', '/inbox', '/outreach/calls', '/engage']);
