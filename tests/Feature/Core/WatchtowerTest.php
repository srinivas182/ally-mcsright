<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Watchtower\Analyst;
use Modules\Core\Watchtower\Incident;
use Modules\Core\Watchtower\Mail\IncidentMail;
use Modules\Core\Watchtower\Mail\WatchtowerReportMail;
use Modules\Core\Watchtower\Recorder;
use Modules\Core\Watchtower\Settings;
use Modules\Core\Watchtower\Signal;
use Tests\Fixtures\FakeAiProvider;

/*
| OB1 (specs/core/20): Watchtower — problems grouped and masked, emailed once with a fix request, explained by the AI,
| downtime from the uptime checker, browser errors, Sentry when HQ switches it on, and every part switchable by HQ.
*/

const WT = 'http://app.rightally.io';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 15:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io', 'core.release' => 'v1.4.0']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    Settings::forget();
    Settings::save(['recipients' => ['dev@rightally.test'], 'alerts' => true, 'digest' => true, 'ai' => false, 'ai_daily_cap' => 30, 'browser_errors' => true, 'slow_pages' => true], null);
    Notification::fake();
});

function wtHigh(string $message = 'Payout failed for jane@acme.test', string $where = 'POST payouts/{payout}/send'): ?Incident
{
    return app(Recorder::class)->record(Signal::fromThrowable(new RuntimeException($message), 'exception', $where, 'workspace'));
}

it('groups the same error into one problem, removes personal data and counts the brokerages hit', function (): void {
    $tenant = $this->onTenantHost('acme');
    app(TenantContext::class)->set($tenant);
    $boom = fn (string $m): RuntimeException => new RuntimeException($m); // the same place in the code
    report($boom('Deal 1001 not found for jane@acme.test at 555-201-7788'));
    report($boom('Deal 1002 not found for bob@acme.test at 555-201-9999'));

    $i = Incident::query()->sole();
    expect($i->occurrences)->toBe(2)->and($i->brokerages)->toBe(1)->and($i->first_release)->toBe('v1.4.0')
        ->and((string) $i->message)->toContain('[email]')->toContain('[number]')->not->toContain('jane')->not->toContain('555')
        ->and($i->file)->toStartWith('tests/');
});

it('emails a serious problem once, with the fix request attached, and again only when it comes back after a fix', function (): void {
    $i = wtHigh();
    expect($i?->severity)->toBe('high'); // payouts are where money moves
    $this->artisan('watchtower:check')->assertSuccessful();
    $this->artisan('watchtower:check')->assertSuccessful();
    Notification::assertSentOnDemandTimes(IncidentMail::class, 1);
    Notification::assertSentOnDemand(IncidentMail::class, function (IncidentMail $mail, array $channels, object $notifiable): bool {
        $message = $mail->toMail($notifiable);

        return $notifiable->routes['mail'] === ['dev@rightally.test'] && str_contains($mail->fixRequest, 'RightAlly fix request') && ! str_contains($mail->fixRequest, 'jane')
            && $message->rawAttachments[0]['name'] === 'fix-request-'.$mail->incident->reference().'.md';
    });

    $this->actingAs($this->staff)->post(WT.'/hq/watchtower/'.$i?->id, ['action' => 'fixed', 'fixed_in' => 'v1.4.1'])->assertSessionHasNoErrors();
    $this->travel(10)->minutes();
    wtHigh();
    expect($i?->fresh()?->status)->toBe('new')->and($i?->fresh()?->regressed_at)->not->toBeNull();
    $this->artisan('watchtower:check');
    Notification::assertSentOnDemandTimes(IncidentMail::class, 2);
});

it('lets HQ switch the emails and the daily report off, and keeps recording', function (): void {
    $this->actingAs($this->staff)->post(WT.'/hq/watchtower/settings', ['recipients' => ['dev@rightally.test', 'not-an-email'], 'alerts' => false, 'digest' => true, 'ai' => false,
        'ai_daily_cap' => 30, 'browser_errors' => true, 'slow_pages' => true])->assertSessionHasErrors('recipients.1');
    $this->actingAs($this->staff)->post(WT.'/hq/watchtower/settings', ['recipients' => ['dev@rightally.test'], 'alerts' => false, 'digest' => false, 'ai' => false,
        'ai_daily_cap' => 30, 'browser_errors' => true, 'slow_pages' => true])->assertSessionHasNoErrors();
    wtHigh();
    $this->artisan('watchtower:check');
    $this->artisan('watchtower:report')->expectsOutputToContain('Not sent');
    Notification::assertNothingSent();
    expect(Incident::query()->count())->toBe(1);

    Settings::save([...Settings::all(), 'digest' => true], null);
    $this->artisan('watchtower:report')->expectsOutputToContain('Report sent');
    Notification::assertSentOnDemand(WatchtowerReportMail::class);
});

it('has the AI find the root cause, raise the severity, and put it in the email, within the daily limit', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Analyst::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $ai->respond((string) json_encode(['summary' => 'Payouts stop for every agent of the brokerage.', 'cause' => 'The bank token expired in PayoutSender.php line 40.',
        'fix' => 'Refresh the token before sending; add a test with an expired token.', 'severity' => 'critical', 'confidence' => 'medium']));
    Settings::save([...Settings::all(), 'ai' => true, 'ai_daily_cap' => 1], null);

    $i = wtHigh();
    $this->artisan('watchtower:check')->assertSuccessful();
    $i = $i?->fresh();
    expect($i?->analysis['status'] ?? null)->toBe('done')->and($i?->severity)->toBe('critical')
        ->and(serialize($ai->requests[0]))->not->toContain('jane'); // personal data never reaches the AI
    Notification::assertSentOnDemand(IncidentMail::class, fn (IncidentMail $m): bool => str_contains($m->fixRequest, 'The bank token expired'));

    wtHigh('Commission split missing for deal', 'POST deals/{deal}/close');
    $this->artisan('watchtower:check');
    expect(Incident::query()->where('title', 'like', '%Commission split%')->sole()->analysis)->toBeNull(); // the daily limit of 1 is used
});

it('records downtime from the uptime checker and emails what it saw when it is back', function (): void {
    $this->post(WT.'/webhooks/uptime/'.str_repeat('a', 40), ['monitor' => 'app', 'status' => 'down'])->assertNotFound(); // no address made yet
    $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post(WT.'/hq/watchtower/uptime-address')->assertRedirect();
    $token = Settings::uptimeToken();
    expect($token)->toHaveLength(40);

    $this->post(WT.'/webhooks/uptime/'.$token, ['data' => ['attributes' => ['name' => 'RightAlly app', 'cause' => 'Status 502', 'started_at' => now()->toIso8601String()]]])->assertNoContent();
    wtHigh('Database went away', 'GET dashboard');
    $this->travel(12)->minutes();
    $this->post(WT.'/webhooks/uptime/'.$token, ['monitorFriendlyName' => 'RightAlly app', 'alertType' => '2', 'alertDateTime' => now()->getTimestamp()])->assertNoContent();

    expect(Incident::query()->where('kind', 'downtime')->sole()->status)->toBe('resolved');
    Notification::assertSentOnDemand(IncidentMail::class, fn (IncidentMail $m): bool => $m->reason === 'recovered' && $m->downMinutes === 12 && count($m->during) === 1);
});

it('reports the scheduler stopping, and takes browser errors only while switched on', function (): void {
    $this->get(WT.'/up/scheduler')->assertStatus(503)->assertJson(['scheduler' => 'never ran']);
    Settings::db()->table('watchtower_heartbeats')->insert(['task' => 'watchtower:check', 'expression' => '* * * * *', 'last_started_at' => now()]);
    $this->get(WT.'/up/scheduler')->assertOk()->assertJson(['scheduler' => 'running']);

    $this->postJson(WT.'/watchtower/browser-error', ['message' => 'Cannot read name of undefined (sam@x.test)', 'page' => '/contacts/{id}',
        'stack' => 'at render (https://app.rightally.io/build/assets/app-3f9a1c2b.js:10:5)'])->assertNoContent();
    $i = Incident::query()->where('kind', 'browser')->sole();
    expect($i->title)->toContain('[email]')->and($i->file)->toBe('/build/assets/app.js:10');

    Settings::save([...Settings::all(), 'browser_errors' => false], null);
    $this->postJson(WT.'/watchtower/browser-error', ['message' => 'Another crash'])->assertNoContent();
    expect(Incident::query()->where('kind', 'browser')->count())->toBe(1);
});

it('sends problems to Sentry only while HQ has it switched on, masked, as "test" in Test mode', function (): void {
    Http::fake(['o1.ingest.sentry.io/*' => Http::response('{}', 200)]);
    wtHigh('Off: nothing goes out');
    Http::assertNothingSent();

    $services = app(OutsideServices::class);
    $services->save(0, 'error_tracking', 'sentry', ['dsn' => 'https://abc123@o1.ingest.sentry.io/42'], [], null, $this->staff);
    $services->setMode(0, 'error_tracking', 'test', $this->staff);
    wtHigh();
    Http::assertSent(fn ($r): bool => $r->url() === 'https://o1.ingest.sentry.io/api/42/envelope/' && str_contains($r->body(), '"environment":"test"')
        && str_contains($r->body(), '[email]') && ! str_contains($r->body(), 'jane') && str_contains($r->header('X-Sentry-Auth')[0] ?? '', 'sentry_key=abc123'));
});

it('is HQ only, and shows the switches and the fix request', function (): void {
    $i = wtHigh();
    $this->actingAs($this->staff)->get(WT.'/hq/watchtower')->assertInertia(fn (Assert $p) => $p->component('hq/watchtower')
        ->where('settings.recipients', ['dev@rightally.test'])->where('summary.high', 1)->where('tracking', null)->has('rows.data', 1));
    $this->actingAs($this->staff)->get(WT.'/hq/watchtower/'.$i?->id)->assertInertia(fn (Assert $p) => $p->component('hq/watchtower-problem')
        ->where('fixRequest', fn (string $f): bool => str_contains($f, (string) $i?->reference())));

    $this->onTenantHost('acme');
    $this->actingAs(User::factory()->owner()->create())->get('/hq/watchtower')->assertNotFound();
});

it('resolves problems that have gone quiet for a week', function (): void {
    wtHigh();
    $this->travel(8)->days();
    $this->artisan('watchtower:sweep')->assertSuccessful();
    expect(Incident::query()->sole()->status)->toBe('resolved');
});
