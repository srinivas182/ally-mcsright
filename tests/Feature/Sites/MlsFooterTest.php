<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Listings\Idx\FooterDisclosures;
use Modules\Sites\SiteSetup;

/*
| SU2: every design's footer carries the MLS disclosure for each board that is live for the brokerage, set by
| RightAlly from the board's display rules; the brokerage cannot edit it. Before a board is live it says the homes
| shown are samples.
*/

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    app(SiteSetup::class)->create('ember', ['name' => 'Acme Realty', 'area' => 'Austin'], $this->owner)->update(['status' => 'live']);
    $this->web = 'http://acme.rightally.site';
});

it('says the homes are samples until a board is live, then shows each live board\'s disclosure', function (): void {
    $this->get($this->web.'/')->assertOk()->assertSee('class="mls-disclosure"', false)->assertSee('are samples while our MLS listings are being connected');

    $control = DB::connection(Connections::control());
    $profile = DB::connection(Connections::listings())->table('mls_rules_profiles')->insertGetId(['name' => 'HAR IDX', 'program' => 'idx',
        'attribution_template' => 'Listing courtesy of {office}', 'disclaimer' => 'HAR.com data is deemed reliable but not guaranteed. © 2026 HAR.',
        'logo_url' => 'https://www.har.com/logo.png', 'created_at' => now(), 'updated_at' => now()]);
    $control->table('idx_boards')->where('code', 'HAR')->update(['rules_profile_id' => $profile]);
    $board = $control->table('idx_boards')->where('code', 'HAR')->value('id');
    $control->table('idx_subscriptions')->insert(['ulid' => (string) str()->ulid(), 'tenant_id' => $this->tenant->id, 'board_id' => $board, 'status' => 'live',
        'created_at' => now(), 'updated_at' => now()]);
    FooterDisclosures::forget($this->tenant->id);

    $this->get($this->web.'/')->assertOk()->assertSee('HAR.com data is deemed reliable but not guaranteed. © 2026 HAR.')
        ->assertSee('src="https://www.har.com/logo.png"', false)->assertDontSee('are samples while our MLS listings');
});
