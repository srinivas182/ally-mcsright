<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;

/* HQ: the Twilio account for deadline texts and automated calls, and which brokerages may use them (Sprint 15D). */

beforeEach(function (): void {
    config(['core.hq_host' => 'app.rightally.io', 'services.twilio.sid' => null]);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $this->admin = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->hq = 'http://app.rightally.io';
    $this->brokerage = Tenant::factory()->withPlatformDomain()->create(['slug' => 'jones', 'name' => 'Jones Realty']);
});

it('stores the Twilio account encrypted, never shows the token, and asks for the password first', function (): void {
    $sid = 'AC'.str_repeat('a1', 16);
    $save = ['sid' => $sid, 'token' => 'secret-token-0123456789abcdef', 'sms_from' => '+15125550100', 'voice_from' => '', 'sms_enabled' => true, 'voice_enabled' => false];

    $this->actingAs($this->admin)->post($this->hq.'/hq/messaging', $save)->assertRedirect(); // password confirmation first
    expect(DB::connection(Connections::control())->table('hq_messaging')->count())->toBe(0);

    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])->post($this->hq.'/hq/messaging', $save)->assertSessionHasNoErrors();
    $row = DB::connection(Connections::control())->table('hq_messaging')->sole();
    expect($row->credentials)->not->toContain('secret-token')
        ->and(app(MessagingSettings::class)->credentials())->toBe(['sid' => $sid, 'token' => 'secret-token-0123456789abcdef']);

    $this->actingAs($this->admin)->get($this->hq.'/hq/messaging')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('hq/messaging')
        ->where('settings.configured', true)->where('settings.sid_last4', 'a1a1')->where('settings.sms_enabled', true)->missing('settings.token'));
    expect(json_encode($this->actingAs($this->admin)->get($this->hq.'/hq/messaging')->viewData('page')))->not->toContain('secret-token');

    // Saving again without the token keeps the account.
    $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])->post($this->hq.'/hq/messaging', ['sms_from' => '+15125550100', 'voice_from' => '+15125550101', 'sms_enabled' => true, 'voice_enabled' => true])
        ->assertSessionHasNoErrors();
    expect(app(MessagingSettings::class)->credentials()['sid'] ?? null)->toBe($sid)
        ->and(DB::table('audit_events')->where('action', 'hq.messaging.saved')->count())->toBe(2);
});

it('checks the numbers and the account before texts or calls go on', function (): void {
    $confirmed = $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
    $confirmed->post($this->hq.'/hq/messaging', ['sms_from' => '512-555-0100', 'sms_enabled' => false, 'voice_enabled' => false])->assertSessionHasErrors('sms_from');
    $confirmed->post($this->hq.'/hq/messaging', ['sms_from' => '+15125550100', 'sms_enabled' => true, 'voice_enabled' => false])->assertSessionHasErrors('sid');
    $confirmed->post($this->hq.'/hq/messaging', ['sid' => 'nope', 'token' => 'x', 'sms_enabled' => false, 'voice_enabled' => false])->assertSessionHasErrors('sid');
});

it('switches texts and calls on for each brokerage', function (): void {
    DB::connection(Connections::control())->table('hq_messaging')->insert(['sms_from' => '+15125550100', 'sms_enabled' => true, 'voice_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $settings = app(MessagingSettings::class);
    expect($settings->smsFor($this->brokerage->id))->toBeFalse();

    $this->actingAs($this->admin)->post($this->hq.'/hq/messaging/tenants/'.$this->brokerage->ulid, ['sms' => true, 'voice' => false])->assertRedirect();
    expect($settings->smsFor($this->brokerage->id))->toBeTrue()->and($settings->voiceFor($this->brokerage->id))->toBeFalse();

    $this->actingAs($this->admin)->get($this->hq.'/hq/messaging')->assertInertia(fn (Assert $page) => $page
        ->where('tenants', fn ($t) => collect($t)->contains(fn ($r) => $r['name'] === 'Jones Realty' && $r['sms'] === true && $r['voice'] === false)));

    // A brokerage cannot reach the HQ settings.
    $owner = app(TenantContext::class)->runAs($this->brokerage, fn () => User::factory()->owner()->create());
    $this->actingAs($owner)->get('http://jones.rightally.app/hq/messaging')->assertNotFound();
});
