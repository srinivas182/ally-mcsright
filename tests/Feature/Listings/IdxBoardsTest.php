<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Idx\Mail\IdxUpdateMail;
use Modules\Listings\Idx\Subscriptions;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;

/*
| IDX4: each brokerage's MLS boards on their own track (demo → signatures → sent → MLS review → live, or needs fixing,
| or cancelled), HQ's approval queue, connector override, the brokerage's own key, and signing reminders.
*/

beforeEach(function (): void {
    Mail::fake();
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->acme = Tenant::factory()->withPlatformDomain()->create(['slug' => 'acme']);
    $this->owner = app(TenantContext::class)->runAs($this->acme, fn () => User::factory()->owner()->create(['email' => 'owner@acme.test']));
    $this->db = DB::connection(Connections::control());
    // HQ has made HAR public, on Repliers, with a feed linked.
    $this->feed = MlsSource::query()->create(['key' => 'har', 'name' => 'HAR', 'states' => ['TX'], 'provider' => 'repliers', 'base_url' => 'https://api.repliers.io', 'status' => 'active']);
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['visibility' => 'public', 'main_cost_cents' => 4_000, 'mls_source_id' => $this->feed->id]);
    $this->har = (string) $this->db->table('idx_boards')->where('code', 'HAR')->value('ulid');
});

function acme(string $path): string
{
    return 'http://acme.rightally.app'.$path;
}

function details(array $extra = []): array
{
    return ['member_id' => 'M123', 'office_id' => 'OFF1', 'license_number' => 'TX-0654321', 'managing_broker_name' => 'Dana Brooks',
        'managing_broker_email' => 'dana@acme.test', ...$extra];
}

it('takes a board from demo to live, one step at a time, and turns on the brokerage\'s MLS licence', function (): void {
    $this->actingAs($this->owner)->post(acme('/settings/mls/boards'), ['board' => $this->har])->assertSessionHasNoErrors();
    $sub = $this->db->table('idx_subscriptions')->where('tenant_id', $this->acme->id)->first();
    expect($sub->status)->toBe('demo')->and($sub->connector)->toBe('repliers')->and((int) $sub->fee_cents)->toBe(5_200);

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->put(acme('/settings/mls/boards/'.$sub->ulid), details())->assertSessionHasNoErrors();
    expect($this->db->table('idx_subscriptions')->where('id', $sub->id)->value('status'))->toBe('awaiting_signature');

    $subs = app(Subscriptions::class);
    $subs->move($sub->ulid, 'sent', null, $this->staff);
    $subs->move($sub->ulid, 'mls_review', null, $this->staff);
    expect(fn () => $subs->move($sub->ulid, 'live', null, $this->staff))->toThrow(ValidationException::class);

    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()])->post('http://app.rightally.io/hq/idx/subscriptions/'.$sub->ulid.'/live', ['feed_id' => 'rep-777'])
        ->assertSessionHasNoErrors();
    $sub = $this->db->table('idx_subscriptions')->where('id', $sub->id)->first();
    expect($sub->status)->toBe('live')->and($sub->feed_id)->toBe('rep-777')
        ->and(app(MlsAccess::class)->licensedSources($this->acme->id))->toBe([$this->feed->id]);
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m): bool => $m->heading === 'Your MLS listings are live' && $m->hasTo('owner@acme.test'));

    // Removing the board hides its listings at once.
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->delete(acme('/settings/mls/boards/'.$sub->ulid))->assertRedirect();
    expect(app(MlsAccess::class)->licensedSources($this->acme->id))->toBe([])
        ->and(MlsAuthorization::query()->find($sub->mls_authorization_id)->status)->toBe('revoked');
});

it('asks the brokerage to fix something, in plain words', function (): void {
    $subs = app(Subscriptions::class);
    $ulid = $subs->add($this->acme->id, $this->har, $this->owner);
    $subs->submit($this->acme->id, $ulid, details(), $this->owner);

    expect(fn () => $subs->move($ulid, 'needs_fixing', '', $this->staff))->toThrow(ValidationException::class);
    $subs->move($ulid, 'needs_fixing', 'Your MLS member ID was not found at HAR.', $this->staff);
    expect($subs->forTenant($this->acme->id)[0])->toMatchArray(['status' => 'needs_fixing', 'note' => 'Your MLS member ID was not found at HAR.'])
        ->and(json_encode($subs->forTenant($this->acme->id)))->not->toContain('repliers');
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m): bool => $m->heading === 'Your MLS board needs a fix');

    // The brokerage fixes it and sends again.
    $subs->submit($this->acme->id, $ulid, details(['member_id' => 'M999']), $this->owner);
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->first())->status->toBe('awaiting_signature')->member_id->toBe('M999');
});

it('offers only boards that are open and public, and one of each', function (): void {
    $subs = app(Subscriptions::class);
    $mlsunited = (string) $this->db->table('idx_boards')->where('code', 'MLSUNITED')->value('ulid'); // still "coming soon"
    expect(fn () => $subs->add($this->acme->id, $mlsunited, $this->owner))->toThrow(ValidationException::class);
    $subs->add($this->acme->id, $this->har, $this->owner);
    expect(fn () => $subs->add($this->acme->id, $this->har, $this->owner))->toThrow(ValidationException::class);

    $page = $this->actingAs($this->owner)->get(acme('/settings/mls'))->assertOk();
    $page->assertInertia(fn ($p) => $p->has('boards', 1)->where('available', []));
});

it('lets HQ move one brokerage to another connector, but not while live', function (): void {
    $subs = app(Subscriptions::class);
    $ulid = $subs->add($this->acme->id, $this->har, $this->owner);
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/idx/subscriptions/'.$ulid.'/connector', ['connector' => 'trestle'])->assertSessionHasNoErrors();
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('connector'))->toBe('trestle')
        ->and($this->db->table('idx_boards')->where('code', 'HAR')->value('main_connector'))->toBe('repliers'); // the board's default is unchanged
});

it('uses the brokerage\'s own key for a feed only it can use', function (): void {
    $subs = app(Subscriptions::class);
    $ulid = $subs->add($this->acme->id, $this->har, $this->owner);
    $subs->submit($this->acme->id, $ulid, details(['own_key' => 'acme-own-feed-key-123']), $this->owner);
    expect($this->db->table('idx_subscriptions')->where('ulid', $ulid)->value('own_key'))->not->toContain('acme-own-feed-key');
    $subs->move($ulid, 'sent', null, $this->staff);
    // The board has no linked feed of its own here: going live makes the brokerage's own.
    $this->db->table('idx_boards')->where('code', 'HAR')->update(['mls_source_id' => null]);
    $subs->goLive($ulid, null, null, $this->staff);

    $own = MlsSource::query()->where('locked_tenant_id', $this->acme->id)->sole();
    expect($own->credentials)->toBe(['token' => 'acme-own-feed-key-123'])->and($own->provider)->toBe('repliers')
        ->and(app(MlsAccess::class)->licensedSources($this->acme->id))->toBe([$own->id]);

    // Another brokerage can never be given that feed.
    $other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $auth = MlsAuthorization::query()->create(['tenant_id' => $other->id, 'mls_source_id' => $own->id, 'broker_mls_id' => 'X', 'scopes' => ['idx'], 'status' => 'pending']);
    expect(fn () => app(MlsAccess::class)->approve($auth, ['idx'], null, null, $this->staff))->toThrow(ValidationException::class);
});

it('reminds the brokerage and managing broker to sign after 1 and 3 days, then stops', function (): void {
    $subs = app(Subscriptions::class);
    $ulid = $subs->add($this->acme->id, $this->har, $this->owner);
    $subs->submit($this->acme->id, $ulid, details(), $this->owner);

    expect($subs->remind())->toBe(0);
    $this->travel(25)->hours();
    expect($subs->remind())->toBe(1);
    Mail::assertQueued(IdxUpdateMail::class, fn (IdxUpdateMail $m): bool => $m->hasTo('dana@acme.test') && $m->hasTo('owner@acme.test'));
    expect($subs->remind())->toBe(0);
    $this->travel(2)->days();
    expect($subs->remind())->toBe(1);
    $this->travel(5)->days();
    expect($subs->remind())->toBe(0);
});
