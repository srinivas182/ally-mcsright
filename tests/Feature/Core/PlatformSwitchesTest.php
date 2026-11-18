<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Access\Roles;
use Modules\Core\Approvals\ApprovalNeededMail;
use Modules\Core\Approvals\Approvals;
use Modules\Core\Identity\Notifications\SupportAccessRequested;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Switches\FeatureSwitches;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Network\NetworkServiceProvider;

/*
| FL1 (specs/core/16): areas switched on or off by HQ (everyone, a share, one brokerage) and by the brokerage itself;
| seats; outside services (Off/Test/Live, encrypted write-only keys, check connection, Live only after a test passes);
| approval rules; support access (ask first, allow for 24 hours, every visit listed).
*/

beforeEach(function (): void {
    Notification::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->confirmed = ['auth.password_confirmed_at' => time()];
});

function hq(string $path): string
{
    return 'http://app.rightally.io'.$path;
}

it('hides a switched-off area from the menu and its pages, for everyone or one brokerage', function (): void {
    $this->actingAs($this->agent)->get('/network')->assertOk();

    $this->actingAs($this->staff)->withSession($this->confirmed)->post(hq('/hq/switches'), ['area' => 'network', 'state' => 'off'])->assertSessionHasNoErrors();
    app(FeatureSwitches::class)->forget();
    $this->actingAs($this->agent)->get('/network')->assertNotFound();
    $this->actingAs($this->agent)->get('/agent')->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($groups): bool => ! collect($groups)->flatMap(fn ($g) => $g['items'])->contains('href', '/network')));

    // HQ turns it on for this brokerage only.
    $this->actingAs($this->staff)->post(hq('/hq/switches/tenants/'.$this->tenant->ulid), ['area' => 'network', 'state' => 'on'])->assertSessionHasNoErrors();
    app(FeatureSwitches::class)->forget();
    $this->actingAs($this->agent)->get('/network')->assertOk();

    // The brokerage switches it off for itself, and can switch it back on.
    $this->actingAs($this->owner)->post('/settings/features', ['area' => 'network', 'on' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/network')->assertNotFound();
    $this->actingAs($this->owner)->post('/settings/features', ['area' => 'network', 'on' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/network')->assertOk();
})->skip(fn (): bool => ! class_exists(NetworkServiceProvider::class), 'network module');

it('does not let a brokerage switch on what HQ switched off, and rolls out to a stable share', function (): void {
    $switches = app(FeatureSwitches::class);
    $switches->setByHq(0, 'prospector', 'off', null, null, $this->staff);
    $this->actingAs($this->owner)->post('/settings/features', ['area' => 'prospector', 'on' => true])->assertSessionHasErrors('area');

    $switches->setByHq(0, 'prospector', null, 0, null, $this->staff);
    expect($switches->on('prospector'))->toBeFalse();
    $switches->setByHq(0, 'prospector', null, 100, null, $this->staff);
    expect($switches->on('prospector'))->toBeTrue();
    $bucket = FeatureSwitches::bucket($this->tenant->id, 'prospector');
    $switches->setByHq(0, 'prospector', null, $bucket + 1, null, $this->staff);
    expect($switches->on('prospector'))->toBeTrue();
    $switches->setByHq(0, 'prospector', null, $bucket, null, $this->staff);
    expect($switches->on('prospector'))->toBeFalse();
    expect(fn () => $switches->setByHq($this->tenant->id, 'prospector', 'on', 50, null, $this->staff))->toThrow(ValidationException::class);
});

it('limits a seat-sold area to owners and the people given a seat', function (): void {
    $bob = User::factory()->create(['first_name' => 'Bob']);
    $this->actingAs($this->agent)->get('/prospector')->assertOk(); // no limit yet: everyone

    $this->actingAs($this->staff)->post(hq('/hq/switches/tenants/'.$this->tenant->ulid.'/seats'), ['area' => 'prospector', 'seats' => 1])->assertSessionHasNoErrors();
    app(FeatureSwitches::class)->forget($this->tenant->id);
    $this->actingAs($this->agent)->get('/prospector')->assertForbidden();
    $this->actingAs($this->owner)->get('/prospector')->assertOk();

    $this->actingAs($this->owner)->post('/settings/features/seats', ['area' => 'prospector', 'user' => $this->agent->ulid, 'give' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/settings/features/seats', ['area' => 'prospector', 'user' => $bob->ulid, 'give' => true])->assertSessionHasErrors('area'); // all taken
    $this->actingAs($this->agent)->get('/prospector')->assertOk();
    $this->actingAs($bob)->get('/prospector')->assertForbidden();

    $this->actingAs($this->owner)->get('/settings/features')->assertInertia(fn (Assert $page) => $page->component('settings/features')
        ->where('areas', fn ($areas): bool => collect($areas)->firstWhere('key', 'prospector')['holders'][0]['name'] === $this->agent->name));
    $this->actingAs($this->agent)->get('/settings/features')->assertForbidden();
});

it('keeps keys encrypted and write-only, checks the connection, and unlocks Live only after a test passes', function (): void {
    Http::fake(['api.zerobounce.net/*' => Http::sequence()->push(['Credits' => '2500'])->push(['Credits' => '-1'])]);
    $this->actingAs($this->staff)->withSession($this->confirmed)->post(hq('/hq/services/email_check'), [
        'provider' => 'zerobounce', 'test' => ['api_key' => 'test-key-1234567890abcd'], 'live' => ['api_key' => 'live-key-0987654321wxyz'],
    ])->assertSessionHasNoErrors();

    $raw = DB::connection(Connections::control())->table('outside_services')->where('tenant_id', 0)->where('service', 'email_check')->sole();
    expect($raw->test_keys)->not->toContain('test-key')->and($raw->live_keys)->not->toContain('live-key');

    $this->actingAs($this->staff)->get(hq('/hq/services'))->assertInertia(fn (Assert $page) => $page->component('settings/services')
        ->where('services', fn ($services): bool => ($svc = collect($services)->firstWhere('key', 'email_check'))['providers'][0]['fields'][0]['test'] === 'Saved ••••abcd'
            && $svc['live_blocker'] !== null && ! str_contains(json_encode($services), 'test-key-1234')));

    $this->actingAs($this->staff)->withSession($this->confirmed)->post(hq('/hq/services/email_check/mode'), ['mode' => 'live'])->assertSessionHasErrors('mode');
    $this->actingAs($this->staff)->post(hq('/hq/services/email_check/check'), ['mode' => 'test'])->assertSessionHasNoErrors();
    $this->actingAs($this->staff)->withSession($this->confirmed)->post(hq('/hq/services/email_check/mode'), ['mode' => 'live'])->assertSessionHasNoErrors();

    $active = app(OutsideServices::class)->active('email_check', $this->tenant->id);
    expect($active)->toMatchArray(['provider' => 'zerobounce', 'mode' => 'live', 'level' => 'hq'])->and($active['keys']['api_key'])->toBe('live-key-0987654321wxyz');

    $this->actingAs($this->staff)->post(hq('/hq/services/email_check/check'), ['mode' => 'live']);
    expect(DB::connection(Connections::control())->table('outside_services')->where('service', 'email_check')->value('check_ok'))->toBeFalsy();
    Http::assertSent(fn ($r): bool => ! str_contains((string) $r->body(), '@') && ! str_contains($r->url(), '@'));

    // A brokerage cannot set up services RightAlly runs for everyone.
    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/settings/services/email_check', ['provider' => 'zerobounce'])->assertSessionHasErrors('service');
});

it('reaches only the listed test contacts in Test mode, and prefers the brokerage\'s own account', function (): void {
    Http::fake(['api.retellai.com/*' => Http::response([])]);
    $services = app(OutsideServices::class);
    $services->save(0, 'voice_calls', 'retell', ['api_key' => 'hq-test-key-1234567890'], [], null, $this->staff);
    $services->check(0, 'voice_calls', 'test', $this->staff);
    $services->setMode(0, 'voice_calls', 'test', $this->staff);
    expect($services->active('voice_calls', $this->tenant->id)['level'])->toBe('hq');

    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/settings/services/voice_calls', [
        'provider' => 'retell', 'test' => ['api_key' => 'own-test-key-1234567890'], 'test_contacts' => '+1 (512) 555-0100, me@acme.test',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/settings/services/voice_calls/check', ['mode' => 'test'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/settings/services/voice_calls/mode', ['mode' => 'test'])->assertSessionHasNoErrors();

    $active = $services->active('voice_calls', $this->tenant->id);
    expect($active['level'])->toBe('brokerage')->and($active['keys']['api_key'])->toBe('own-test-key-1234567890')
        ->and($services->mayReach('voice_calls', '+15125550100', $this->tenant->id))->toBeTrue()
        ->and($services->mayReach('voice_calls', '+15125550199', $this->tenant->id))->toBeFalse();

    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/settings/services/voice_calls', ['provider' => 'nope'])->assertSessionHasErrors('provider');
    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/settings/services/voice_calls', ['provider' => 'retell', 'test_contacts' => 'not a phone'])->assertSessionHasErrors('test_contacts');
    $this->actingAs($this->agent)->get('/settings/services')->assertForbidden();
});

it('waits for approval before a brokerage service goes Live when the owner asks for it', function (): void {
    Http::fake(['api.retellai.com/*' => Http::response([])]);
    $admin = User::factory()->staff()->create(['first_name' => 'Ada']);
    $approver = User::factory()->staff()->create(['first_name' => 'Pat']);
    $roles = app(Roles::class);
    $role = $roles->save(null, 'Admins', null, ['core.platform.manage' => null, 'core.approvals.give' => null], $this->owner);
    $roles->assign($admin, $role, $this->owner);
    $roles->assign($approver, $role, $this->owner);

    $this->actingAs($this->owner)->withSession($this->confirmed)->post('/settings/approvals/rules', ['action' => 'services.go_live', 'needed' => 1])->assertSessionHasNoErrors();
    $services = app(OutsideServices::class);
    $services->save($this->tenant->id, 'voice_calls', 'retell', ['api_key' => 'own-test-key-1234567890'], ['api_key' => 'own-live-key-1234567890'], null, $admin);
    $services->check($this->tenant->id, 'voice_calls', 'test', $admin);

    $this->actingAs($admin)->withSession($this->confirmed)->post('/settings/services/voice_calls/mode', ['mode' => 'live'])->assertSessionHasNoErrors();
    expect(DB::connection(Connections::control())->table('outside_services')->where('tenant_id', $this->tenant->id)->value('mode'))->toBe('off');
    Notification::assertSentTo([$this->owner, $approver], ApprovalNeededMail::class);
    Notification::assertNotSentTo($admin, ApprovalNeededMail::class);

    $ulid = (string) DB::connection(Connections::cell())->table('approval_requests')->value('ulid');
    $this->actingAs($admin)->post('/settings/approvals/'.$ulid, ['approve' => true])->assertSessionHasErrors('request'); // not their own
    $this->actingAs($this->agent)->post('/settings/approvals/'.$ulid, ['approve' => true])->assertSessionHasErrors('request');
    $this->actingAs($approver)->post('/settings/approvals/'.$ulid, ['approve' => true])->assertSessionHasNoErrors();

    expect(DB::connection(Connections::control())->table('outside_services')->where('tenant_id', $this->tenant->id)->value('mode'))->toBe('live')
        ->and(DB::connection(Connections::cell())->table('approval_requests')->value('status'))->toBe('approved');
    $this->actingAs($this->owner)->get('/settings/approvals')->assertInertia(fn (Assert $page) => $page->component('settings/approvals')
        ->where('closed.0.status', 'approved')->where('rules.0.needed', 1));

    // Unanswered requests close after 7 days.
    app(Approvals::class)->doOrAsk('services.go_live', 'Switch AI voice calls to Live', ['service' => 'floor_plans'], $admin);
    $this->travel(8)->days();
    $this->artisan('core:expire-approvals')->assertSuccessful();
    expect(DB::connection(Connections::cell())->table('approval_requests')->where('status', 'expired')->count())->toBe(1);
});

it('makes support ask first when the brokerage wants it, and lists every visit', function (): void {
    $this->actingAs($this->owner)->post('/settings/support-access', ['policy' => 'ask'])->assertSessionHasNoErrors();

    $this->actingAs($this->staff)->post(hq('/hq/tenants/'.$this->tenant->getRouteKey().'/impersonate'), ['email' => $this->agent->email, 'reason' => 'Help with a stuck deal file'])
        ->assertSessionHasErrors('email');
    Notification::assertSentTo($this->owner, SupportAccessRequested::class, fn (SupportAccessRequested $n): bool => str_contains($n->link, 'acme.') && str_contains($n->reason, 'stuck deal'));

    $ulid = (string) DB::connection(Connections::control())->table('support_access_requests')->value('ulid');
    $this->actingAs($this->agent)->post('/settings/support-access/'.$ulid, ['allow' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/settings/support-access/'.$ulid, ['allow' => true])->assertSessionHasNoErrors();

    $this->actingAs($this->staff)->post(hq('/hq/tenants/'.$this->tenant->getRouteKey().'/impersonate'), ['email' => $this->agent->email, 'reason' => 'Help with a stuck deal file'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/settings/support-access')->assertInertia(fn (Assert $page) => $page->component('settings/support-access')
        ->where('policy', 'ask')->where('visits.0.viewed', $this->agent->name)->where('visits.0.reason', 'Help with a stuck deal file')->where('waiting', []));

    $this->travel(25)->hours();
    $this->actingAs($this->staff)->post(hq('/hq/tenants/'.$this->tenant->getRouteKey().'/impersonate'), ['email' => $this->agent->email, 'reason' => 'Another question here'])
        ->assertSessionHasErrors('email');
    $this->actingAs($this->agent)->get('/settings/support-access')->assertForbidden();
});
