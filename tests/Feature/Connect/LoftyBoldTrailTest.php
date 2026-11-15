<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;

/* Sprint C2b: Lofty and BoldTrail (kvCORE) — paste your own API key, and your people come over with the same rules
   as every CRM import. */

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['email' => 'jane@acme.test']);
    foreach (['lofty', 'boldtrail'] as $key) {
        app(AppRegistry::class)->save(app(AppCatalog::class)->find($key), true, [], null, User::factory()->make(['id' => 999999]));
    }
});

function crmKeyConnect(User $as, string $app, string $key = 'key-123'): TestResponse
{
    return test()->actingAs($as)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/'.$app.'/connect', ['api_key' => $key]);
}

it('shows plain steps for finding the key', function (): void {
    $this->actingAs($this->agent)->get('/apps/lofty')->assertInertia(fn (Assert $p) => $p->where('app.guide.steps.0', 'In Lofty, open Settings → Integrations → API.')
        ->where('app.status', 'available'));
});

it('brings Lofty leads over with their agent, tags, stage and notes, then only new ones', function (): void {
    Http::fake([
        'api.lofty.com/v1.0/members*' => Http::response(['members' => [['memberUserId' => 77, 'email' => 'JANE@acme.test']]]),
        'api.lofty.com/v1.0/leads*' => Http::response(['leads' => [
            ['leadId' => 651095960136641, 'firstName' => 'Ana', 'lastName' => 'Diaz', 'emails' => ['ana@x.test'], 'phones' => ['(512) 555-0101'],
                'tags' => [['tagName' => 'Buyer']], 'stage' => 'Nurture', 'source' => 'Zillow', 'assignedUserId' => 77],
            ['leadId' => 2, 'firstName' => '', 'lastName' => '', 'emails' => [], 'phones' => []],
        ], 'get_metadata' => ['total' => 2]]),
        'api.lofty.com/v1.0/notes*' => Http::response(['notes' => [
            ['noteId' => 9, 'leadId' => 651095960136641, 'content' => 'Pre-approved', 'createTime' => '2026-03-01 14:00:00'],
            ['noteId' => 10, 'leadId' => 651095960136641, 'content' => 'System stuff', 'systemNote' => true],
        ]]),
    ]);
    crmKeyConnect($this->owner, 'lofty')->assertSessionHasNoErrors();

    $ana = Contact::query()->where('primary_email', 'ana@x.test')->sole();
    expect(Contact::query()->count())->toBe(1)
        ->and($ana->owner_user_id)->toBe($this->agent->id)->and($ana->origin->value)->toBe('company')->and($ana->source_detail)->toBe('Lofty: Zillow')
        ->and($ana->tags()->pluck('name')->sort()->values()->all())->toBe(['Buyer', 'Lofty stage: Nurture'])
        ->and(Activity::query()->where('contact_id', $ana->id)->where('type', 'note')->pluck('body')->all())->toBe(['From Lofty: Pre-approved']);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/v1.0/leads') && $r->header('Authorization')[0] === 'token key-123');

    // The next run starts after the last lead read, so only new leads come over.
    Http::swap(new Factory);
    Http::fake(['api.lofty.com/v1.0/leads*' => Http::response(['leads' => []])]);
    app(SyncRunner::class)->run(Connection::query()->sole());
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'offset=2'));
    expect(Contact::query()->count())->toBe(1);
});

it('refuses a Lofty key that does not work', function (): void {
    Http::fake(['api.lofty.com/*' => Http::response(['message' => 'unauthorized'], 401)]);
    crmKeyConnect($this->agent, 'lofty', 'bad')->assertSessionHasErrors(['api_key' => 'Lofty didn\'t accept that API key. Copy it again from Lofty → Settings → Integrations → API.']);
    expect(Connection::query()->count())->toBe(0);
});

it('brings BoldTrail contacts over page by page, as the agent\'s own contacts', function (): void {
    Http::fake([
        'api.kvcore.com/v2/public/users*' => Http::response([], 403),
        'api.kvcore.com/v2/public/contacts*' => function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return match ((int) ($q['page'] ?? 1)) {
                1 => Http::response(['data' => [['id' => 11, 'first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'sam@x.test', 'cell_phone_1' => '512-555-0199',
                    'hashtags' => [['name' => '#Seller']], 'status_name' => 'Hot', 'source' => 'Website']], 'current_page' => 1, 'last_page' => 2]),
                default => Http::response(['data' => [['id' => 12, 'first_name' => 'Kim', 'email' => 'kim@x.test', 'tags' => ['investor'], 'status' => '3']],
                    'current_page' => 2, 'last_page' => 2]),
            };
        },
    ]);
    crmKeyConnect($this->agent, 'boldtrail')->assertSessionHasNoErrors();

    $sam = Contact::query()->where('primary_email', 'sam@x.test')->sole();
    expect(Contact::query()->count())->toBe(2)
        ->and($sam->origin->value)->toBe('personal')->and($sam->owner_user_id)->toBe($this->agent->id)->and($sam->primary_phone)->toBe('+15125550199')
        ->and($sam->tags()->pluck('name')->sort()->values()->all())->toBe(['BoldTrail status: Hot', 'Seller'])
        ->and(Contact::query()->where('primary_email', 'kim@x.test')->sole()->tags()->pluck('name')->all())->toBe(['investor']); // a numeric status is not a stage
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'page=2') && $r->header('Authorization')[0] === 'Bearer key-123');

    // Within a day, runs do nothing; the next day, everything is read again (safe to repeat).
    Http::swap(new Factory);
    Http::fake();
    app(SyncRunner::class)->run(Connection::query()->sole());
    Http::assertNothingSent();
});
