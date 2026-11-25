<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Roster\MlsIds;
use Modules\Roster\RosterAgentCredentials;

/*
| ML1: agents' MLS IDs found on the brokerage's own listings in the MLS feed, matched by name, and confirmed by the
| agent with one click; typed by agents with no listings; checked by the owner when the name does not match.
*/

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olivia', 'last_name' => 'Owner']);
    $this->andy = User::factory()->create(['first_name' => 'Andrew', 'last_name' => 'Smith']);
    $this->maria = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Chen']);
    $this->source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'fake', 'base_url' => 'https://example.test', 'status' => 'active']);
    MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $this->source->id, 'broker_mls_id' => 'OFF1',
        'office_mls_ids' => ['OFF2'], 'scopes' => ['idx'], 'status' => 'active']);
    // Andy lists two homes from our office; someone at another brokerage lists one; "R. Jones" is ours but unknown.
    mlsHome('1', 'OFF1', 'A100', 'Andrew J. Smith', '27 Willow Ln');
    mlsHome('2', 'OFF2', 'A100', 'Andrew J. Smith', '9 Pine St');
    mlsHome('3', 'ELSE', 'X900', 'Maria Chen', '1 Far Away Rd');
    mlsHome('4', 'OFF1', 'J300', 'Robert Jones', '5 Oak St');
});

function mlsHome(string $key, string $office, string $agentId, string $name, string $street): void
{
    [$number, $rest] = explode(' ', $street, 2);
    DB::connection(Connections::listings())->table('listings')->insert(['ulid' => (string) Str::ulid(), 'mls_source_id' => test()->source->id, 'listing_key' => "K{$key}",
        'standard_status' => 'Active', 'street_number' => $number, 'street_name' => $rest, 'city' => 'Austin', 'list_office_mls_id' => $office,
        'list_agent_mls_id' => $agentId, 'list_agent_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
}

it('finds agents on our own listings, matches them by name, and saves only when the agent confirms', function (): void {
    expect(app(MlsIds::class)->scan())->toBe(2)->and(app(MlsIds::class)->scan())->toBe(0);

    $this->actingAs($this->andy)->get('/agent')->assertInertia(fn (Assert $p) => $p
        ->where('mlsId.suggestions.0.id', 'A100')->where('mlsId.suggestions.0.listings', 2)->where('mlsId.suggestions.0.mls', 'ACTRIS')
        ->where('mlsId.suggestions.0.sample', fn ($s) => collect($s)->contains('27 Willow Ln, Austin')));
    expect(app(RosterAgentCredentials::class)->mlsIds($this->andy->id))->toBe([]);

    $ulid = DB::table('roster_mls_matches')->where('agent_mls_id', 'A100')->value('ulid');
    $this->actingAs($this->andy)->post("/roster/mls-ids/{$ulid}/decide", ['mine' => true])->assertSessionHasNoErrors();
    expect(app(RosterAgentCredentials::class)->mlsIds($this->andy->id))->toBe(['A100'])
        ->and(DB::table('roster_credentials')->where('user_id', $this->andy->id)->value('verification_method'))->toBe('mls_feed');

    // Another brokerage's agent is never suggested; an unknown name of ours goes to the owner.
    expect(DB::table('roster_mls_matches')->where('agent_mls_id', 'X900')->exists())->toBeFalse()
        ->and(DB::table('roster_mls_matches')->where('agent_mls_id', 'J300')->value('status'))->toBe('review');
});

it('lets the owner say who an unmatched ID belongs to, and no one else can', function (): void {
    app(MlsIds::class)->scan();
    $ulid = DB::table('roster_mls_matches')->where('agent_mls_id', 'J300')->value('ulid');
    $this->actingAs($this->andy)->post("/roster/mls-ids/{$ulid}/assign", ['agent' => $this->andy->ulid])->assertSessionHasErrors('roster');

    $this->actingAs($this->owner)->get('/roster/mls-ids')->assertInertia(fn (Assert $p) => $p->component('roster/mls-ids')
        ->where('overview.review.0.id', 'J300')->where('overview.waiting.0.id', 'A100'));
    $this->actingAs($this->owner)->post("/roster/mls-ids/{$ulid}/assign", ['agent' => $this->maria->ulid])->assertSessionHasNoErrors();
    expect(app(RosterAgentCredentials::class)->mlsIds($this->maria->id))->toBe(['J300']);
});

it('lets an agent with no listings type their ID, and sends it to the owner when the listings show another name', function (): void {
    // Maria types an ID with no listings yet: offered back to her to confirm.
    $this->actingAs($this->maria)->post('/roster/mls-ids/typed', ['board' => $this->source->id, 'mls_id' => 'M555'])->assertSessionHasNoErrors();
    expect(DB::table('roster_mls_matches')->where('agent_mls_id', 'M555')->value('status'))->toBe('suggested');

    // She types Andy's ID: the listings say Andrew Smith, so the owner checks it and nothing is saved for her.
    $this->actingAs($this->maria)->post('/roster/mls-ids/typed', ['board' => $this->source->id, 'mls_id' => 'A100']);
    expect(DB::table('roster_mls_matches')->where('agent_mls_id', 'A100')->value('status'))->toBe('review')
        ->and(app(RosterAgentCredentials::class)->mlsIds($this->maria->id))->toBe([]);

    $this->actingAs($this->maria)->post('/roster/mls-ids/typed', ['board' => 999, 'mls_id' => 'M1'])->assertSessionHasErrors('board');
    $this->actingAs($this->maria)->post('/roster/mls-ids/typed', ['board' => $this->source->id, 'mls_id' => 'bad id!'])->assertSessionHasErrors('mls_id');
});

it('matches names the way MLSs write them', function (): void {
    $people = [['id' => 1, 'ulid' => '', 'name' => '', 'first' => 'andrew', 'last' => 'smith'], ['id' => 2, 'ulid' => '', 'name' => '', 'first' => 'maria', 'last' => 'chen']];
    expect(MlsIds::match('Andrew J. Smith', $people))->toBe(1)
        ->and(MlsIds::match('Smith, Andrew', $people))->toBe(1)
        ->and(MlsIds::match('ANDREW SMITH JR', $people))->toBe(1)
        ->and(MlsIds::match('Andy Smith', $people))->toBeNull()
        ->and(MlsIds::match('Smith', $people))->toBeNull();
    // Two people with the same name: no guess.
    $people[] = ['id' => 3, 'ulid' => '', 'name' => '', 'first' => 'andrew', 'last' => 'smith'];
    expect(MlsIds::match('Andrew Smith', $people))->toBeNull();
});

it('asks nothing of agents when the brokerage has no MLS board', function (): void {
    MlsAuthorization::query()->update(['status' => 'revoked']);
    $this->actingAs($this->andy)->get('/agent')->assertInertia(fn (Assert $p) => $p->where('mlsId', null));
});

it('saves a typed ID with no listings as unchecked, and takes it back when listings show it is someone else\'s', function (): void {
    $this->actingAs($this->maria)->post('/roster/mls-ids/typed', ['board' => $this->source->id, 'mls_id' => 'Q777']);
    $ulid = DB::table('roster_mls_matches')->where('agent_mls_id', 'Q777')->value('ulid');
    $this->actingAs($this->maria)->post("/roster/mls-ids/{$ulid}/decide", ['mine' => true]);
    expect(app(RosterAgentCredentials::class)->mlsIds($this->maria->id))->toBe(['Q777'])
        ->and(DB::table('roster_credentials')->where('user_id', $this->maria->id)->where('number', 'Q777')->value('verified_at'))->toBeNull();

    // A listing from our office appears under Q777 with Robert Jones's name: off Maria's record, to the owner.
    mlsHome('9', 'OFF1', 'Q777', 'Robert Jones', '3 Lake Dr');
    app(MlsIds::class)->scan();
    expect(app(RosterAgentCredentials::class)->mlsIds($this->maria->id))->toBe([])
        ->and(DB::table('roster_mls_matches')->where('agent_mls_id', 'Q777')->value('status'))->toBe('review');
});

it('lets an agent change their MLS ID, and the old one is free again', function (): void {
    app(MlsIds::class)->scan();
    $this->actingAs($this->andy)->post('/roster/mls-ids/'.DB::table('roster_mls_matches')->where('agent_mls_id', 'A100')->value('ulid').'/decide', ['mine' => true]);
    mlsHome('10', 'OFF1', 'A200', 'Andrew Smith', '12 New Rd');

    $this->actingAs($this->andy)->get('/roster/me')->assertInertia(fn (Assert $p) => $p->where('mlsId.current.0.id', 'A100')->where('mlsId.current.0.checked', true));
    $this->actingAs($this->andy)->post('/roster/mls-ids/typed', ['board' => $this->source->id, 'mls_id' => 'A200'])->assertSessionHasNoErrors();
    $this->actingAs($this->andy)->post('/roster/mls-ids/'.DB::table('roster_mls_matches')->where('agent_mls_id', 'A200')->value('ulid').'/decide', ['mine' => true]);

    expect(app(RosterAgentCredentials::class)->mlsIds($this->andy->id))->toBe(['A200'])
        ->and(DB::table('roster_mls_matches')->where('agent_mls_id', 'A100')->value('status'))->toBe('replaced');
});
