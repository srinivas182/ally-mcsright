<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Audit\AuditStream;
use Modules\Core\Audit\AuditStreamer;
use Modules\Core\Audit\AuditStreamFailing;
use Modules\Core\Audit\FlushAuditOutbox;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    Notification::fake();
});

function addWebhook(User $owner): AuditStream
{
    test()->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->post('/audit/streams', ['type' => 'webhook', 'name' => 'Our SIEM', 'config' => ['url' => 'https://siem.test/in', 'secret' => str_repeat('s', 32)]])
        ->assertSessionHasNoErrors();

    return AuditStream::query()->sole();
}

it('needs a fresh password confirmation and keeps credentials encrypted', function (): void {
    $this->actingAs($this->owner)->post('/audit/streams', ['type' => 'webhook', 'config' => ['url' => 'https://siem.test/in', 'secret' => str_repeat('s', 32)]])
        ->assertRedirect('/user/confirm-password');

    $stream = addWebhook($this->owner);
    expect(DB::table('audit_streams')->value('config'))->not->toContain('siem.test')
        ->and($stream->toArray())->not->toHaveKey('config');

    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->post('/audit/streams', ['type' => 'webhook', 'config' => ['url' => 'http://insecure.test', 'secret' => 'short']])->assertSessionHasErrors(['config.url']);
    $agent = User::factory()->create();
    $this->actingAs($agent)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->post('/audit/streams', ['type' => 'datadog', 'config' => ['api_key' => 'k']])->assertForbidden();
});

it('delivers new events in order with a valid signature, from where it stopped', function (): void {
    Http::fake(['https://siem.test/*' => Http::response(['ok' => true])]);
    $stream = addWebhook($this->owner);
    app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 's@x.test'], $this->owner);
    app(ManageContacts::class)->create(['first_name' => 'Kim', 'email' => 'k@x.test'], $this->owner);
    (new FlushAuditOutbox)->handle();

    $this->artisan('audit:maintain', ['task' => 'stream'])->assertSuccessful();

    Http::assertSent(function (Request $r): bool {
        $events = $r->data()['events'];
        $seqs = array_column($events, 'seq');
        [$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $r->header('X-RightAlly-Signature')[0]));

        return $seqs === array_values(array_filter($seqs, fn ($s) => $s > 0)) && $seqs === range(min($seqs), max($seqs))
            && hash_equals(hash_hmac('sha256', $t.'.'.$r->body(), str_repeat('s', 32)), $v1)
            && collect($events)->pluck('subject.label')->contains('Kim');
    });
    expect($stream->fresh()?->cursor_seq)->toBe((int) DB::table('audit_events')->max('chain_seq'))
        ->and($stream->fresh()?->last_success_at)->not->toBeNull();
});

it('retries from the same event, alerts once, pauses after 24 hours, and loses nothing on resume', function (): void {
    $up = false;
    Http::fake(function () use (&$up) {
        return $up ? Http::response(['ok' => true]) : Http::response('down', 503);
    });
    $stream = addWebhook($this->owner);
    $start = $stream->cursor_seq;
    app(ManageContacts::class)->create(['first_name' => 'Sam', 'email' => 's@x.test'], $this->owner);
    (new FlushAuditOutbox)->handle();

    app(TenantContext::class)->runAs($this->tenant, fn () => app(AuditStreamer::class)->deliverAll());
    app(TenantContext::class)->runAs($this->tenant, fn () => app(AuditStreamer::class)->deliverAll());
    Notification::assertSentToTimes($this->owner, AuditStreamFailing::class, 1);
    expect($stream->fresh()?->cursor_seq)->toBe($start)->and($stream->fresh()?->status)->toBe('active');

    $this->travel(25)->hours();
    app(TenantContext::class)->runAs($this->tenant, fn () => app(AuditStreamer::class)->deliverAll());
    expect($stream->fresh()?->status)->toBe('paused');
    Notification::assertSentTo($this->owner, AuditStreamFailing::class, fn (AuditStreamFailing $n): bool => $n->paused);

    $up = true;
    $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post("/audit/streams/{$stream->ulid}/resume");
    app(TenantContext::class)->runAs($this->tenant, fn () => app(AuditStreamer::class)->deliverAll());
    expect($stream->fresh()?->status)->toBe('active')->and($stream->fresh()?->cursor_seq)->toBeGreaterThan($start);
    Http::assertSent(fn (Request $r): bool => collect($r->data()['events'] ?? [])->pluck('seq')->contains($start + 1));
});

it('speaks Datadog, Splunk and Amazon S3', function (): void {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $disk = Storage::fake('s3audit');
    Storage::shouldReceive('build')->andReturn($disk);
    $streamer = app(AuditStreamer::class);
    $this->actingAs($this->owner);
    $dd = $streamer->add('datadog', 'DD', ['api_key' => 'dd-key', 'site' => 'datadoghq.eu'], $this->owner);
    $sp = $streamer->add('splunk', 'Splunk', ['url' => 'https://splunk.test:8088', 'token' => 'hec'], $this->owner);
    $s3 = $streamer->add('s3', 'Archive', ['bucket' => 'b', 'region' => 'us-east-1', 'key' => 'k', 'secret' => 's', 'prefix' => 'audit'], $this->owner);
    foreach ([$dd, $sp, $s3] as $s) {
        $s->update(['cursor_seq' => 0]);
    }
    (new FlushAuditOutbox)->handle();
    $streamer->deliverAll();

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://http-intake.logs.datadoghq.eu/api/v2/logs' && $r->header('DD-API-KEY')[0] === 'dd-key' && $r->data()[0]['ddsource'] === 'rightally');
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://splunk.test:8088/services/collector/event' && $r->header('Authorization')[0] === 'Splunk hec');
    $files = $disk->allFiles();
    expect($files)->not->toBeEmpty()->and($files[0])->toStartWith('audit/')->and($files[0])->toEndWith('.jsonl');
});

it('records HQ actions in the brokerage\'s own log and in HQ\'s', function (): void {
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq');
    $hq = Tenant::query()->where('type', 'hq')->sole();
    $support = app(TenantContext::class)->runAs($hq, fn () => User::factory()->hqStaff()->create());

    $this->actingAs($support)->post("http://app.rightally.io/hq/tenants/{$this->tenant->ulid}/suspend", ['reason' => 'Unpaid invoice for March'])->assertRedirect();
    (new FlushAuditOutbox)->handle();

    $mine = DB::table('audit_events')->where('tenant_id', $this->tenant->id)->where('action', 'core.tenant.suspended')->sole();
    expect($mine->actor_type)->toBe('hq_support')->and(json_decode((string) $mine->metadata, true)['reason'])->toBe('Unpaid invoice for March')
        ->and(DB::table('audit_events')->where('tenant_id', $hq->id)->where('action', 'core.tenant.suspended')->exists())->toBeTrue();
});

it('cannot change or delete events with the restricted audit database user', function (): void {
    // User management runs on its own connection: MySQL commits implicitly on CREATE USER/GRANT, and
    // that must never touch the test's transaction.
    $db = config('database.connections.mysql.database');
    config(['database.connections.mysql_admin' => array_merge(config('database.connections.mysql'), [
        'username' => env('DB_ADMIN_USERNAME'), 'password' => env('DB_ADMIN_PASSWORD', ''),
    ])]);
    $admin = DB::connection('mysql_admin');
    $admin->statement("CREATE USER IF NOT EXISTS 'audit_ro'@'%' IDENTIFIED BY 'audit-ro-pass-1'");
    $admin->statement("GRANT INSERT, SELECT ON `{$db}`.`audit_events` TO 'audit_ro'@'%'");
    config(['database.connections.audit_ro' => array_merge(config('database.connections.mysql'), ['username' => 'audit_ro', 'password' => 'audit-ro-pass-1'])]);

    $ro = DB::connection('audit_ro');
    try {
        expect($ro->table('audit_events')->count())->toBeInt()
            ->and(fn () => $ro->table('audit_events')->where('id', '>', 0)->update(['action' => 'x']))->toThrow(QueryException::class, 'denied')
            ->and(fn () => $ro->table('audit_events')->where('id', '>', 0)->delete())->toThrow(QueryException::class, 'denied');
    } finally {
        DB::purge('audit_ro');
        $admin->statement("DROP USER IF EXISTS 'audit_ro'@'%'");
        DB::purge('mysql_admin');
    }
})->skip(fn () => config('database.default') !== 'mysql' || env('DB_ADMIN_USERNAME') === null, 'MySQL with DB_ADMIN_USERNAME only: proves database privileges');
