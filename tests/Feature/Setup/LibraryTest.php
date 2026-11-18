<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Models\Plan;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\Template;
use Modules\Setup\Library\Catalog;
use Modules\Setup\Library\Library;

/*
| SU3 (specs/setup/03): ready-made items install in one click, updates reach copies nobody changed, and changes
| are never overwritten.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
});

it('installs every item in the library cleanly (Fair Housing screen and merge fields included)', function (): void {
    foreach (array_keys(Catalog::ITEMS) as $key) {
        $this->actingAs($this->owner)->post("/setup/library/{$key}")->assertSessionHasNoErrors();
    }

    expect(Plan::query()->count())->toBe(4)
        ->and(Plan::query()->get()->every(fn (Plan $p): bool => $p->versions()->whereNotNull('published_at')->doesntExist()))->toBeTrue()
        ->and(FollowUpPlan::query()->whereIn('name', ['Investor lead (30 days)', 'Vendedor nuevo (14 días, en español)'])->count())->toBe(2)
        ->and(Template::query()->whereNull('user_id')->count())->toBe(8);
    $this->actingAs($this->owner)->get('/setup/library')->assertInertia(fn (Assert $page) => $page->component('setup/library')
        ->where('items', fn ($items) => collect($items)->every(fn ($i) => $i['status'] === 'installed')));
});

it('offers a new version only to copies nobody changed, and never overwrites a change', function (): void {
    $library = app(Library::class);
    $library->install('msg_closing_day', $this->owner);
    $library->install('msg_price_drop', $this->owner);
    // RightAlly ships version 2 of both.
    DB::table('setup_library_installs')->update(['version' => 0]);

    // The owner edits one copy.
    Template::query()->where('name', 'Price drop on a home they liked (text)')->update(['body' => 'My own words, {first_name}.']);

    $status = fn () => collect($library->items())->keyBy('key');
    expect($status()['msg_closing_day']['status'])->toBe('update')->and($status()['msg_price_drop']['status'])->toBe('changed');

    $this->actingAs($this->owner)->post('/setup/library/msg_closing_day')->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/setup/library/msg_price_drop')->assertSessionHasErrors('item');
    expect(Template::query()->where('name', 'Price drop on a home they liked (text)')->value('body'))->toBe('My own words, {first_name}.');

    $this->actingAs($this->owner)->post('/setup/library/msg_price_drop', ['copy' => 1])->assertSessionHasNoErrors();
    expect(Template::query()->where('name', 'Price drop on a home they liked (text)')->count())->toBe(2)
        ->and($status()['msg_closing_day']['status'])->toBe('installed')
        ->and(Template::query()->where('name', 'Closing day (text)')->count())->toBe(1);
});

it('never updates a commission plan in place: a newer version arrives as a new draft', function (): void {
    $library = app(Library::class);
    $library->install('plan_80_20_cap', $this->owner);
    DB::table('setup_library_installs')->update(['version' => 0]);

    $this->actingAs($this->owner)->post('/setup/library/plan_80_20_cap')->assertSessionHasNoErrors();

    expect(Plan::query()->where('name', '80/20 with an $18,000 cap')->count())->toBe(2);
});

it('is for owners and staff', function (): void {
    $agent = User::factory()->create();
    $this->actingAs($agent)->get('/setup/library')->assertForbidden();
    $this->actingAs($agent)->post('/setup/library/msg_closing_day')->assertForbidden();
    $this->actingAs($this->owner)->post('/setup/library/no_such_item')->assertSessionHasErrors('item');
});
