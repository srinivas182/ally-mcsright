<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Modules\Core\Services\ConnectionCheck;
use Modules\Core\Services\OutsideServices;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Listings\Feed\ResoWebApi;
use Modules\Listings\Models\MlsSource;

/*
| IDX2: Spark API (Flexmls boards such as MLS United). RightAlly's Spark key lives in HQ › Outside services (Test: demo
| data; Live: approved boards); each board's feed reads Spark's replication address in a closed time window, with
| photos, at most every 10 minutes.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $this->staff = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->spark = MlsSource::query()->create(['key' => 'mlsunited', 'name' => 'MLS United', 'states' => ['MS'], 'provider' => 'spark',
        'base_url' => MlsSource::PRESETS['spark']['base_url'], 'status' => 'active']);
});

it('reads MLS United through Spark with RightAlly\'s key from Outside services', function (): void {
    Http::fake(['replication.sparkapi.com/*' => Http::response(['value' => [['ListingKey' => 'L1', 'ModificationTimestamp' => '2026-11-21T09:00:00Z']]])]);

    expect(fn () => app(ResoWebApi::class)->changes($this->spark, null, null))->toThrow(RuntimeException::class, 'Outside services');

    $services = app(OutsideServices::class);
    $services->save(0, 'idx_spark', 'spark', ['access_token' => 'spark-demo-token'], [], null, $this->staff);
    $services->setMode(0, 'idx_spark', 'test', $this->staff);

    $page = app(ResoWebApi::class)->changes($this->spark, '2026-11-20T00:00:00Z', null, 5000);
    expect($page->records)->toHaveCount(1);
    Http::assertSent(function ($r): bool {
        $q = urldecode($r->url());

        return str_starts_with($r->url(), 'https://replication.sparkapi.com/Reso/OData/Property?') && $r->hasHeader('Authorization', 'Bearer spark-demo-token')
            && str_contains($q, '(ModificationTimestamp gt 2026-11-20T00:00:00Z and ModificationTimestamp lt 2026-11-21T09:59:00Z)')
            && str_contains($q, '$expand=Media') && str_contains($q, '$top=1000');
    });
});

it('uses a board\'s own Spark key when one is saved on the feed', function (): void {
    Http::fake(['replication.sparkapi.com/*' => Http::response(['value' => []])]);
    $this->spark->forceFill(['credentials' => ['token' => 'board-own-token']])->save();

    app(ResoWebApi::class)->changes($this->spark, null, null);
    Http::assertSent(fn ($r): bool => $r->hasHeader('Authorization', 'Bearer board-own-token'));
});

it('checks the Spark key by reading one listing', function (): void {
    Http::fake(fn ($r) => $r->hasHeader('Authorization', 'Bearer bad') ? Http::response(['error' => 'no'], 401) : Http::response(['value' => []]));
    expect(app(ConnectionCheck::class)->run('spark', 'test', ['access_token' => 't', 'endpoint' => 'https://sparkapi.com/Reso/OData'])['ok'])->toBeTrue();
    Http::assertSent(fn ($r): bool => str_starts_with($r->url(), 'https://sparkapi.com/Reso/OData/Property?'));

    expect(app(ConnectionCheck::class)->run('spark', 'live', ['access_token' => 'bad', 'endpoint' => ''])['ok'])->toBeFalse()
        ->and(app(ConnectionCheck::class)->run('spark', 'live', ['access_token' => '', 'endpoint' => ''])['message'])->toContain('Fill in every box');
});

it('never reads Spark more often than every 10 minutes', function (): void {
    $this->actingAs($this->staff)->post('http://app.rightally.io/hq/mls/sources', ['key' => 'spark-two', 'name' => 'Second Spark board', 'provider' => 'spark',
        'base_url' => '', 'states' => ['MS'], 'originating_system' => null, 'timezone' => 'America/Chicago', 'sync_interval_minutes' => 2, 'rate_limit_per_hour' => 1000])
        ->assertSessionHasNoErrors();
    expect(MlsSource::query()->where('key', 'spark-two')->value('sync_interval_minutes'))->toBe(10)
        ->and(MlsSource::query()->where('key', 'spark-two')->value('base_url'))->toBe('https://replication.sparkapi.com/Reso/OData');
});
