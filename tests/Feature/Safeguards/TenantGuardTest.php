<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Attributes\CrossTenantAccess;
use Modules\Core\Tenancy\CrossTenant;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Contact;

/*
| Sprint 58 (pen-test preparation): the database-level brokerage guard (Tenancy\Guard\TenantGuard). Found by the
| integration sweep: a brokerage owner could download another brokerage's roster document by its id, because that
| query (DB::table, not a model) forgot "where tenant_id". Now every query on a brokerage table is limited to the
| current brokerage, whatever the code forgets.
*/

beforeEach(function (): void {
    $this->other = Tenant::factory()->withPlatformDomain()->create(['slug' => 'other']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
});

function tgDocument(Tenant $tenant): string
{
    return app(TenantContext::class)->runAs($tenant, function (): string {
        $u = User::factory()->create();
        $ulid = (string) Str::ulid();
        DB::connection(Connections::cell())->table('roster_documents')->insert(['ulid' => $ulid, 'tenant_id' => app(TenantContext::class)->id(), 'user_id' => $u->id,
            'kind' => 'other', 'filename' => 'licence.pdf', 'path' => 'roster/'.$ulid.'.pdf', 'mime' => 'application/pdf', 'sha256' => str_repeat('a', 64),
            'uploaded_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put('roster/'.$ulid.'.pdf', '%PDF private');

        return $ulid;
    });
}

it('never hands one brokerage another brokerage\'s roster document', function (): void {
    Storage::fake('local');
    $theirs = tgDocument($this->other);
    $ours = tgDocument($this->tenant);

    $this->actingAs($this->owner)->get('/roster/documents/'.$theirs)->assertNotFound();
    expect($this->actingAs($this->owner)->get('/roster/documents/'.$ours)->baseResponse->getStatusCode())->toBe(200);
});

it('limits raw reads, updates and deletes to the brokerage, and keeps "or" inside it', function (): void {
    $db = fn () => DB::connection(Connections::cell())->table('roster_documents');
    $theirs = tgDocument($this->other);
    $ours = tgDocument($this->tenant);

    expect($db()->where('ulid', $theirs)->exists())->toBeFalse()
        ->and($db()->count())->toBe(1)
        ->and($db()->where('ulid', 'nothing')->orWhere('ulid', $theirs)->count())->toBe(0) // "brokerage and (a or b)"
        ->and($db()->where('ulid', 'nothing')->orWhere('ulid', $ours)->count())->toBe(1)
        ->and($db()->where('ulid', $theirs)->update(['filename' => 'changed.pdf']))->toBe(0)
        ->and($db()->where('ulid', $theirs)->delete())->toBe(0);
    // Used inside another query, the condition keeps its value in the right place.
    $sub = DB::connection(Connections::cell())->table('roster_documents')->select('user_id')->where('ulid', $theirs);
    expect(User::query()->whereIn('id', $sub)->count())->toBe(0);

    // Their own rows are untouched; HQ and console work (no brokerage) still see everything.
    app(TenantContext::class)->runAs($this->other, fn () => expect($db()->where('ulid', $theirs)->value('filename'))->toBe('licence.pdf'));
    app(TenantContext::class)->forget();
    expect($db()->count())->toBe(2);
});

it('keeps an Eloquent "(email or phone)" duplicate check inside the brokerage', function (): void {
    app(TenantContext::class)->runAs($this->other, fn () => app(ManageContacts::class)->create(['first_name' => 'Zed', 'email' => 'same@x.test'], User::factory()->create()));
    $contact = app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'same@x.test'], $this->owner); // not a duplicate here

    expect(Contact::query()->where(fn ($q) => $q->where('primary_email', 'same@x.test')->orWhere('primary_email', 'none'))->pluck('id')->all())->toBe([$contact->id]);
});

it('steps aside only for the sanctioned cross-brokerage path', function (): void {
    app(TenantContext::class)->runAs($this->other, fn () => app(ManageContacts::class)->create(['first_name' => 'Zed', 'email' => 'z@x.test'], User::factory()->create()));
    app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'a@x.test'], $this->owner);
    $caller = new #[CrossTenantAccess('test: count every brokerage\'s contacts')] class {};

    expect(Contact::query()->count())->toBe(1)->and(CrossTenant::query(Contact::class, $caller)->count())->toBe(2);
});
