<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;

/* Sprint C3b: Google Local Services Ads (one click with Google; leads every 15 minutes) and the other lead vendors'
   tiles (forwarding email or the Lead API; partner notes for direct feeds). */

beforeEach(function (): void {
    Notification::fake();
    config(['services.google.client_id' => 'gid', 'services.google.client_secret' => 'gsecret']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('google_lsa'), true, ['developer_token' => 'dev-tok'], null, User::factory()->make(['id' => 999999]));
});

it('turns Local Services calls and messages into leads, once each, from every account the person can reach', function (): void {
    $search = fn (HttpRequest $r) => str_contains($r->url(), 'customers/1234567890/')
        ? Http::response(['results' => [['localServicesLead' => ['id' => '987', 'leadType' => 'PHONE_CALL', 'contactDetails' => ['consumerName' => 'Rosa Diaz', 'phoneNumber' => '+15125550101']]]]])
        : Http::response(['error' => ['message' => 'not a Local Services account']], 403);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-at', 'refresh_token' => 'g-rt', 'expires_in' => 3600]),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'jane@gmail.test']),
        'googleads.googleapis.com/*/customers:listAccessibleCustomers' => Http::response(['resourceNames' => ['customers/1234567890', 'customers/222']]),
        'googleads.googleapis.com/*/googleAds:search' => $search,
    ]);
    $start = $this->actingAs($this->agent)->post('/apps/google_lsa/oauth', [], ['X-Inertia' => 'true']);
    parse_str((string) parse_url((string) $start->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $q);
    expect($q['scope'])->toContain('https://www.googleapis.com/auth/adwords');
    $home = (string) $this->get('https://auth.rightally.app/connect/oauth/callback?code=abc&state='.urlencode($q['state']))->headers->get('Location');
    $this->actingAs($this->agent)->get($home)->assertRedirect();

    $lead = Lead::query()->sole();
    expect(LeadSource::query()->find($lead->source_id)?->name)->toBe('Google Local Services Ads')
        ->and($lead->external_id)->toBe('lsa-987')->and($lead->assigned_user_id)->toBe($this->agent->id)
        ->and(Contact::query()->sole()->primary_phone)->toBe('+15125550101');
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'googleAds:search') && $r->hasHeader('developer-token', 'dev-tok')
        && str_contains((string) $r['query'], 'FROM local_services_lead WHERE local_services_lead.creation_date_time >'));

    $c = Connection::query()->sole();
    expect($c->settings['customers'])->toBe(['1234567890', '222'])->and($c->settings['interval_minutes'])->toBe(15);
    app(SyncRunner::class)->run($c->fresh()); // the same lead again: still one
    expect(Lead::query()->count())->toBe(1);
});

it('is not ready until HQ adds the developer token as well as the Google sign-in app', function (): void {
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('google_lsa'), true, [], null, User::factory()->make(['id' => 999999]));
    expect(app(AppRegistry::class)->ready(app(AppCatalog::class)->find('google_lsa')))->toBeTrue(); // the saved token is kept
    DB::connection(Connections::control())->table('hq_connect_apps')->where('app_key', 'google_lsa')->update(['credentials' => null]);
    Cache::flush();
    expect(app(AppRegistry::class)->ready(app(AppCatalog::class)->find('google_lsa')))->toBeFalse();
});

it('shows every other lead vendor as ready through the lead address, with partner notes for direct feeds', function (): void {
    foreach (['ylopo', 'redfin_partner', 'market_leader', 'landvoice', 'smartzip', 'redx', 'vulcan7'] as $key) {
        $this->actingAs($this->owner)->get('/apps/'.$key)->assertInertia(fn (Assert $p) => $p->where('app.status', 'available')->where('app.auth', 'email')
            ->where('app.guide.steps.0', 'Copy your RightAlly lead address below (or make one under Leads → Settings).'));
    }
    expect(app(AppCatalog::class)->find('market_leader')?->setup['partner'])->toContain('partner agreement');
});
