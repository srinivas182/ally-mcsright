<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Privacy\PrivacyAnswerMail;
use Modules\Core\Privacy\PrivacyCopyMail;
use Modules\Core\Privacy\PrivacyRequestMail;
use Modules\Core\Privacy\PrivacyRequests;
use Modules\Core\Security\BotProtection;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Models\Contact;

/*
| TR1 (specs/core/18): privacy requests (confirmed by email, answered within 45 days, records kept where the law
| requires), data retention, bot protection on public forms, and the SOC 2 evidence page.
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
    $this->agent = User::factory()->create();
});

/** A privacy request that the person has confirmed, ready for the brokerage. */
function confirmedRequest(string $kind, string $email = 'nia@example.test'): string
{
    $token = app(PrivacyRequests::class)->ask(['kind' => $kind, 'name' => 'Nia Lopez', 'email' => $email]);
    app(PrivacyRequests::class)->confirm($token);

    return (string) DB::connection(Connections::cell())->table('privacy_requests')->latest('id')->value('ulid');
}

it('asks the person to confirm by email before the brokerage sees the request', function (): void {
    $requests = app(PrivacyRequests::class);
    $token = $requests->ask(['kind' => 'delete', 'name' => 'Nia Lopez', 'email' => 'NIA@example.test', 'details' => 'Please delete me']);
    expect(DB::connection(Connections::cell())->table('privacy_requests')->value('status'))->toBe('unconfirmed');
    $this->actingAs($this->owner)->get('/settings/privacy')->assertInertia(fn (Assert $p) => $p->component('settings/privacy')->where('requests', []));

    expect($requests->confirm('wrong-token'))->toBeNull();
    $req = $requests->confirm($token);
    expect($req)->not->toBeNull()->and(DB::connection(Connections::cell())->table('privacy_requests')->value('due_on'))->toBe('2026-10-16');
    Notification::assertSentTo($this->owner, PrivacyRequestMail::class);
    expect($requests->confirm($token))->toBeNull(); // single use

    expect(fn () => $requests->ask(['kind' => 'nope', 'name' => '', 'email' => 'bad']))->toThrow(ValidationException::class);
});

it('deletes a person\'s records but keeps those in a transaction file, and emails the answer', function (): void {
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Nia', 'last_name' => 'Lopez', 'email' => 'nia@example.test', 'source' => 'open_house']);
    $nia = Contact::query()->where('primary_email', 'nia@example.test')->sole();
    $this->actingAs($this->agent)->post('/contacts/'.$nia->ulid.'/notes', ['body' => 'Wants a 3-bed near the park']);
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Nia', 'last_name' => 'Buyer', 'email' => 'other@example.test', 'phone' => '512-555-0100', 'source' => 'open_house']);
    $buyer = Contact::query()->where('primary_email', 'other@example.test')->sole();
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '9 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '400000', 'contact' => $buyer->ulid]);
    DB::connection(Connections::cell())->table('tx_deals')->latest('id')->update(['contact_id' => $buyer->id]);

    $ulid = confirmedRequest('delete');
    DB::connection(Connections::cell())->table('privacy_requests')->where('ulid', $ulid)->update(['phone' => '(512) 555-0100']);

    $this->actingAs($this->owner)->get('/settings/privacy')->assertInertia(fn (Assert $p) => $p->where('requests.0.matches', fn ($m): bool => count($m) === 2
        && collect($m)->firstWhere('held', 'part of a transaction file the brokerage must keep') !== null));
    $this->actingAs($this->agent)->get('/settings/privacy')->assertForbidden();

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/privacy/'.$ulid.'/complete')->assertSessionHasNoErrors();

    $gone = DB::connection(Connections::cell())->table('crm_contacts')->where('id', $nia->id)->first();
    expect($gone->primary_email)->toBeNull()->and($gone->first_name)->toBe('Deleted')->and($gone->deleted_at)->not->toBeNull()
        ->and(DB::connection(Connections::cell())->table('crm_activities')->where('contact_id', $nia->id)->count())->toBe(0)
        ->and(Contact::query()->find($buyer->id)?->primary_email)->toBe('other@example.test');
    Notification::assertSentOnDemand(PrivacyAnswerMail::class, fn (PrivacyAnswerMail $m): bool => str_contains($m->answer, 'Deleted 1 record') && str_contains($m->answer, 'transaction file'));
    expect(DB::connection(Connections::cell())->table('privacy_requests')->where('ulid', $ulid)->value('status'))->toBe('done');
});

it('sends a copy by a link that works for 7 days', function (): void {
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Nia', 'last_name' => 'Lopez', 'email' => 'nia@example.test', 'source' => 'open_house']);
    $ulid = confirmedRequest('know');
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/privacy/'.$ulid.'/complete')->assertSessionHasNoErrors();

    $link = null;
    Notification::assertSentOnDemand(PrivacyCopyMail::class, function (PrivacyCopyMail $m) use (&$link): bool {
        $link = $m->link;

        return true;
    });
    auth()->guard('web')->logout();
    $json = $this->get($link)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="my-information.json"')->json();
    expect($json['records']['Contacts'][0]['name'])->toBe('Nia Lopez');
    $this->get(str_replace('signature=', 'signature=x', $link))->assertForbidden();
    $this->travel(8)->days();
    $this->get($link)->assertForbidden();
});

it('stops marketing on request, and declines only with a reason', function (): void {
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Nia', 'email' => 'nia@example.test', 'source' => 'open_house']);
    $nia = Contact::query()->where('primary_email', 'nia@example.test')->sole();
    DB::connection(Connections::cell())->table('crm_contacts')->where('id', $nia->id)->update(['email_opt_in' => true]);

    $ulid = confirmedRequest('opt_out');
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/privacy/'.$ulid.'/complete')->assertSessionHasNoErrors();
    expect((bool) $nia->fresh()->email_opt_in)->toBeFalse();

    $other = confirmedRequest('correct', 'pat@example.test');
    $this->actingAs($this->owner)->post('/settings/privacy/'.$other.'/decline', ['note' => 'short'])->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post('/settings/privacy/'.$other.'/decline', ['note' => 'We hold no information about this email address.'])->assertSessionHasNoErrors();
});

it('removes data past the time the owner chose, never below the minimum', function (): void {
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/privacy/retention', ['kind' => 'cold_leads', 'months' => 6])
        ->assertSessionHasErrors('months');
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/settings/privacy/retention', ['kind' => 'cold_leads', 'months' => 24])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Old', 'email' => 'old@example.test', 'source' => 'open_house']);
    $this->actingAs($this->agent)->post('/contacts', ['first_name' => 'Fresh', 'email' => 'fresh@example.test', 'source' => 'open_house']);
    DB::connection(Connections::cell())->table('crm_contacts')->where('primary_email', 'old@example.test')->update(['last_activity_at' => now()->subMonths(30), 'created_at' => now()->subMonths(30)]);

    $this->artisan('core:privacy-daily')->assertSuccessful();
    expect(Contact::query()->where('primary_email', 'old@example.test')->exists())->toBeFalse()
        ->and(Contact::query()->where('primary_email', 'fresh@example.test')->exists())->toBeTrue();
    $this->actingAs($this->owner)->get('/settings/privacy')->assertInertia(fn (Assert $p) => $p->where('retention', fn ($rules): bool => collect($rules)->firstWhere('kind', 'cold_leads')['last_removed'] === 1
        && collect($rules)->firstWhere('kind', 'website_activity')['months'] === 13));
});

it('checks public forms for bots when bot protection is on, and lets forms through when the provider is down', function (): void {
    $bot = app(BotProtection::class);
    $request = Request::create('/start', 'POST', ['cf-turnstile-response' => 'token-from-widget']);
    expect($bot->widget())->toBeNull()->and($bot->passes(Request::create('/start', 'POST')))->toBeTrue(); // off: as before

    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
        ->push(['success' => false, 'error-codes' => ['invalid-input-response']]) // check connection: the secret works
        ->push(['success' => true])->push(['success' => false])->push([], 500)]);
    $services = app(OutsideServices::class);
    $services->save(0, 'bot_protection', 'turnstile', ['site_key' => '1x00000000000000000000AA', 'secret_key' => '1x0000000000000000000000000000000AA'], [], null, $this->staff);
    expect($services->check(0, 'bot_protection', 'test', $this->staff)['ok'])->toBeTrue();
    $services->setMode(0, 'bot_protection', 'test', $this->staff);

    expect($bot->widget())->toMatchArray(['provider' => 'turnstile', 'class' => 'cf-turnstile'])
        ->and($bot->passes($request))->toBeTrue()
        ->and($bot->passes($request))->toBeFalse()
        ->and($bot->passes(Request::create('/start', 'POST')))->toBeFalse() // no answer at all
        ->and($bot->passes($request))->toBeTrue(); // provider error: let it through

    $this->get('http://app.rightally.io/health-check')->assertOk()->assertSee('class="cf-turnstile"', false);
    $this->post('http://app.rightally.io/start', ['brokerage' => 'Acme'])->assertSessionHasErrors('bot');
});

it('shows HQ the SOC 2 controls with live evidence and a download', function (): void {
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/controls')->assertInertia(fn (Assert $p) => $p->component('hq/controls')
        ->where('controls', fn ($c): bool => collect($c)->firstWhere('id', 'CC6.1')['status'] === 'met' && count($c) === 10));
    app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create(['two_factor_confirmed_at' => null]));
    $this->actingAs($this->staff)->get('http://app.rightally.io/hq/controls')->assertInertia(fn (Assert $p) => $p
        ->where('controls', fn ($c): bool => collect($c)->firstWhere('id', 'CC6.1')['evidence'] === '1 of 2 active HQ staff have two-step sign-in.'
            && collect($c)->firstWhere('id', 'CC6.1')['status'] === 'attention'));
    $csv = $this->actingAs($this->staff)->get('http://app.rightally.io/hq/controls/download')->assertOk()->streamedContent();
    expect($csv)->toContain('Control,Area')->toContain('CC6.7');
    $this->actingAs($this->owner)->get('/hq/controls')->assertNotFound();
});
