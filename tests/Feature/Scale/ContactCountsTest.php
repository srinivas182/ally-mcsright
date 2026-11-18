<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Health\DatabaseHealth;
use Modules\Crm\SmartLists;

/*
| Sprint 60 (load test): in a big brokerage, counts over the whole database (smart lists, database health) are kept
| for a few minutes, and start fresh the moment a contact changes. Small brokerages are always counted live.
*/

beforeEach(function (): void {
    $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    foreach (['Ann', 'Bo', 'Cy'] as $n) {
        app(ManageContacts::class)->create(['first_name' => $n, 'email' => strtolower($n).'@x.test', 'sphere' => 'company'], $this->owner);
    }
});

function ccAll(User $u): int
{
    return (int) collect(app(SmartLists::class)->listsFor($u))->firstWhere('key', 'new-leads')['count'];
}

it('keeps counts in a big brokerage until a contact changes', function (): void {
    config(['crm.counts_from' => 3]);
    expect(ccAll($this->owner))->toBe(3)->and(app(DatabaseHealth::class)->for(null)['total'])->toBe(3);

    // A change that does not go through a contact (a raw import) is seen within the five minutes, not at once.
    DB::connection(Connections::cell())->table('crm_contacts')->where('first_name', 'Cy')->update(['deleted_at' => now()]);
    expect(ccAll($this->owner))->toBe(3);

    // Any contact change starts fresh numbers.
    app(ManageContacts::class)->create(['first_name' => 'Di', 'email' => 'di@x.test', 'sphere' => 'company'], $this->owner);
    expect(ccAll($this->owner))->toBe(3)->and(app(DatabaseHealth::class)->for(null)['total'])->toBe(3); // Cy gone, Di added

    $this->travel(6)->minutes();
    DB::connection(Connections::cell())->table('crm_contacts')->where('first_name', 'Di')->update(['deleted_at' => now()]);
    $this->travel(6)->minutes();
    expect(ccAll($this->owner))->toBe(2); // older than five minutes: counted again
});

it('always counts a small brokerage live', function (): void {
    expect(ccAll($this->owner))->toBe(3);
    DB::connection(Connections::cell())->table('crm_contacts')->where('first_name', 'Cy')->update(['deleted_at' => now()]);
    expect(ccAll($this->owner))->toBe(2);
});
