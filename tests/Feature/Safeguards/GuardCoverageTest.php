<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Security\ContentSecurityPolicy;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Exceptions\UnguardedTenantQuery;
use Modules\Core\Tenancy\Guard\TenantGuard;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Contact;
use Modules\Sites\SiteSetup;

/*
| SX2 (guard coverage): hand-written SQL, joins and queued jobs stay inside the brokerage, and the content security
| policy (report-only first) with its reports.
*/

beforeEach(function (): void {
    $this->other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->theirs = app(TenantContext::class)->runAs($this->other, fn () => app(ManageContacts::class)->create(['first_name' => 'Zed', 'email' => 'z@x.test'], User::factory()->create()));
    $this->ours = app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'a@x.test'], $this->owner);
});

final class Sx2Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var list<int|null> */
    public static array $ranAs = [];

    public function __construct(public bool $failItself = false) {}

    public function handle(TenantContext $tenants): void
    {
        self::$ranAs[] = $tenants->check() ? $tenants->id() : null;
        if ($this->failItself) {
            $this->fail(new RuntimeException('giving up'));
        }
    }
}

it('refuses hand-written SQL on a brokerage table that does not say which brokerage', function (): void {
    $cell = DB::connection(Connections::cell());

    expect(fn () => $cell->select('select * from crm_contacts where id = ?', [$this->theirs->id]))->toThrow(UnguardedTenantQuery::class)
        ->and(fn () => $cell->statement('update crm_contacts set first_name = ? where id = ?', ['X', $this->theirs->id]))->toThrow(UnguardedTenantQuery::class)
        ->and(fn () => $cell->update('UPDATE `crm_contacts` SET first_name = ?', ['X']))->toThrow(UnguardedTenantQuery::class);

    // Saying which brokerage is fine; the query builder is fine; SQL on other tables is fine.
    expect($cell->select('select id from crm_contacts where tenant_id = ? and id = ?', [$this->tenant->id, $this->ours->id]))->toHaveCount(1)
        ->and($cell->table('crm_contacts')->where('id', $this->theirs->id)->exists())->toBeFalse()
        ->and($cell->select('select 1 as one'))->toHaveCount(1);

    // HQ and console work (no brokerage), and the sanctioned cross-brokerage path, are not limited.
    $caller = new #[CrossTenantAccess('test: count every brokerage\'s contacts')] class {};
    expect(CrossTenant::query(Contact::class, $caller)->count())->toBe(2);
    app(TenantContext::class)->forget();
    expect($cell->select('select id from crm_contacts'))->toHaveCount(2);
});

it('limits a joined brokerage table to the brokerage, also from a table without a brokerage column', function (): void {
    $cell = DB::connection(Connections::cell());
    Schema::connection(Connections::cell())->create('sx2_links', function (Blueprint $t): void {
        $t->id();
        $t->unsignedBigInteger('contact_id');
    });
    TenantGuard::flush();
    $cell->table('sx2_links')->insert([['contact_id' => $this->ours->id], ['contact_id' => $this->theirs->id]]);

    expect($cell->table('sx2_links')->join('crm_contacts as c', 'c.id', '=', 'sx2_links.contact_id')->pluck('c.first_name')->all())->toBe(['Ann'])
        ->and($cell->table('sx2_links')->leftJoin('crm_contacts', 'crm_contacts.id', '=', 'sx2_links.contact_id')->orderBy('sx2_links.id')->pluck('crm_contacts.first_name')->all())->toBe(['Ann', null])
        ->and($cell->table('sx2_links')->join('crm_contacts', fn ($j) => $j->on('crm_contacts.id', '=', 'sx2_links.contact_id')->where('crm_contacts.first_name', 'Zed'))->count())->toBe(0);
    Schema::connection(Connections::cell())->drop('sx2_links');
    TenantGuard::flush();
});

it('runs a queued job as the brokerage that queued it, and leaves the request\'s brokerage in place afterwards', function (): void {
    Sx2Job::$ranAs = [];
    Sx2Job::dispatch();
    try {
        Sx2Job::dispatch(true); // a job that fails itself used to pop the brokerage twice
    } catch (Throwable) {
    }
    app(TenantContext::class)->runAs($this->other, function (): void {
        Sx2Job::dispatch(); // a statement: PendingDispatch sends it here, inside the other brokerage
    });

    expect(Sx2Job::$ranAs)->toBe([$this->tenant->id, $this->tenant->id, $this->other->id])
        ->and(app(TenantContext::class)->id())->toBe($this->tenant->id);
});

it('never runs a job without a brokerage when its brokerage is gone', function (): void {
    Sx2Job::$ranAs = [];
    $gone = Tenant::factory()->create(['slug' => 'gone']);
    $id = $gone->id;
    $job = new Sx2Job;
    expect(fn () => app(TenantContext::class)->runAs($gone, function () use ($gone, $job): void {
        $gone->forceDelete();
        dispatch($job);
    }))->toThrow(RuntimeException::class, "brokerage [{$id}]");

    expect(Sx2Job::$ranAs)->toBe([])->and(app(TenantContext::class)->id())->toBe($this->tenant->id);
});

it('sends the content security policy report-only, with a nonce the page\'s own scripts carry', function (): void {
    $response = $this->get('/login');
    $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
    preg_match("/'nonce-([^']+)'/", $policy, $m);

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($policy)->toContain("default-src 'self'")->toContain("object-src 'none'")->toContain("frame-ancestors 'self'")->toContain('report-uri /csp-report?s=public')
        ->not->toContain('unsafe-eval')
        ->and($m[1] ?? '')->not->toBe('')
        ->and($response->getContent())->toContain('nonce="'.($m[1] ?? 'x').'"');

    $this->actingAs($this->owner)->get('/contacts')->assertHeader('Content-Security-Policy-Report-Only');
    expect((string) $this->actingAs($this->owner)->get('/contacts')->headers->get('Content-Security-Policy-Report-Only'))->toContain('?s=back-office');
});

it('enforces the policy when switched to enforce, sends none when off, and leaves a page\'s own policy alone', function (): void {
    config(['core.csp' => 'enforce']);
    $this->get('/login')->assertHeader('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    config(['core.csp' => 'off']);
    $this->get('/login')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');

    config(['core.csp' => 'enforce']);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('modern', ['area' => 'Austin'], $this->owner), true, $this->owner);
    $site = $this->get('http://acme.rightally.site/');
    preg_match("/'nonce-([^']+)'/", (string) $site->headers->get('Content-Security-Policy'), $m);
    expect($m[1] ?? '')->not->toBe('')->and(substr_count((string) $site->getContent(), 'nonce="'.$m[1].'"'))->toBeGreaterThan(0)
        // Every inline script on the website carries the nonce.
        ->and(preg_match_all('/<script(?![^>]*\b(?:src|nonce)=)(?![^>]*type="application\/(?:ld\+)?json")[^>]*>/', (string) $site->getContent()))->toBe(0);
});

it('counts browser reports by origin and path only, on any host, at most 60 a minute', function (): void {
    Cache::flush();
    $report = fn (string $blocked, string $page) => ['csp-report' => ['document-uri' => $page, 'violated-directive' => 'script-src-elem', 'effective-directive' => 'script-src-elem',
        'blocked-uri' => $blocked]];
    $this->call('POST', '/csp-report?s=back-office', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
        (string) json_encode($report('https://evil.test/steal.js?email=jane@x.test', 'https://acme.rightally.app/contacts/01ABC?q=jane@x.test')))->assertNoContent();
    $this->call('POST', '/csp-report?s=back-office', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'],
        (string) json_encode($report('https://evil.test/other.js', 'https://acme.rightally.app/contacts/01ABC')))->assertNoContent();
    $this->call('POST', 'http://acme.rightally.site/csp-report?s=public', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'],
        (string) json_encode([['type' => 'csp-violation', 'body' => ['documentURL' => 'http://acme.rightally.site/', 'effectiveDirective' => 'script-src-elem', 'blockedURL' => 'inline']]]))->assertNoContent();

    $rows = DB::connection(Connections::control())->table('csp_reports')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and([$rows[0]->surface, $rows[0]->directive, $rows[0]->blocked, $rows[0]->page, (int) $rows[0]->count])->toBe(['back-office', 'script-src-elem', 'https://evil.test', '/contacts/01ABC', 2])
        ->and([$rows[1]->surface, $rows[1]->blocked, $rows[1]->page])->toBe(['public', 'inline', '/'])
        ->and($rows->toJson())->not->toContain('jane');

    foreach (range(1, 57) as $n) {
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{}');
    }
    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{}')->assertStatus(429);
    expect(ContentSecurityPolicy::blocked('data:image/png;base64,xx'))->toBe('data')->and(ContentSecurityPolicy::blocked('eval'))->toBe('eval');
});
