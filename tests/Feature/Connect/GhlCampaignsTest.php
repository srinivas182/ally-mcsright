<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Connect\SyncRunner;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tags;
use Modules\Crm\Consent;
use Modules\Crm\Models\ConsentEvent;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\GhlEnrolment;

/* Sprint C7a-2: GoHighLevel campaigns — RightAlly contacts (by tag or smart list) into a GoHighLevel workflow,
   campaign or trigger tag, consent checked, once each, topped up hourly; "do not disturb" comes back as opt-outs. */

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('gohighlevel'), true, [], null, User::factory()->make(['id' => 999999]));
    $contacts = app(ManageContacts::class);
    $tags = app(Tags::class);
    foreach ([['Amy', 'amy@x.test', null, true], ['Ben', 'ben@x.test', '5125550101', false], ['Oli', 'oli@x.test', null, false]] as [$name, $email, $phone, $_]) {
        $c = $contacts->create(array_filter(['first_name' => $name, 'last_name' => 'Seller', 'email' => $email, 'phone' => $phone, 'origin' => 'company']), $this->owner);
        $tags->attach($c, 'Spring sellers');
    }
    app(Consent::class)->setEmail(Contact::query()->where('first_name', 'Oli')->sole(), false, 'agent');
    $this->contacts = Contact::query()->pluck('id', 'first_name');
});

function ghlFake(array $extra = []): void
{
    Http::fake($extra + [
        'services.leadconnectorhq.com/contacts/search' => Http::response(['contacts' => []]),
        'services.leadconnectorhq.com/users/*' => Http::response(['users' => []]),
        'services.leadconnectorhq.com/workflows/*' => Http::response(['workflows' => [['id' => 'wf-1', 'name' => 'Spring seller nurture', 'status' => 'published']]]),
        'services.leadconnectorhq.com/campaigns/*' => Http::response(['campaigns' => [['id' => 'cp-1', 'name' => 'Old drip', 'status' => 'published']]]),
        'services.leadconnectorhq.com/contacts/upsert' => fn (HttpRequest $r) => Http::response(['contact' => ['id' => 'g-'.strtolower((string) $r['firstName'])]]),
        'services.leadconnectorhq.com/contacts/*/workflow/*' => Http::response(['succeeded' => true]),
        'services.leadconnectorhq.com/contacts/*/campaigns/*' => Http::response(['succeeded' => true]),
        'services.leadconnectorhq.com/contacts/*/tags' => Http::response(['tags' => ['x']]),
    ]);
}

function ghlConnection(object $t): Connection
{
    $t->actingAs($t->owner)->withSession(['auth.password_confirmed_at' => time()])->post('/apps/gohighlevel/connect', ['api_key' => 'pit-1', 'location_id' => 've9EPM428h8vShlRW1KT'])
        ->assertSessionHasNoErrors();

    return Connection::query()->sole();
}

it('puts tagged contacts who may get email into a GoHighLevel workflow, once each', function (): void {
    ghlFake();
    $c = ghlConnection($this);
    $this->actingAs($this->owner)->get('/settings/ghl-campaigns')->assertInertia(fn (Assert $p) => $p->component('settings/ghl-campaigns')
        ->where('connections.0.targets.workflows.0.name', 'Spring seller nurture')->where('tags', ['Spring sellers']));
    $this->actingAs($this->owner)->post('/settings/ghl-campaigns/'.$c->ulid, ['target_type' => 'workflow', 'target_id' => 'nope', 'source_type' => 'tag', 'source_value' => 'Spring sellers'])
        ->assertSessionHasErrors('target_id');
    $this->actingAs($this->owner)->post('/settings/ghl-campaigns/'.$c->ulid, ['target_type' => 'workflow', 'target_id' => 'wf-1', 'source_type' => 'tag',
        'source_value' => 'Spring sellers', 'channel' => 'email'])->assertSessionHasNoErrors();

    $e = GhlEnrolment::query()->sole();
    expect($e->only(['name', 'target_name', 'enrolled', 'left_out']))->toBe(['name' => 'Spring seller nurture', 'target_name' => 'Spring seller nurture', 'enrolled' => 2, 'left_out' => 1]);
    Http::assertSent(fn (HttpRequest $r): bool => str_ends_with($r->url(), 'contacts/upsert') && $r['email'] === 'amy@x.test' && $r['locationId'] === 've9EPM428h8vShlRW1KT'
        && $r->hasHeader('Version', '2021-07-28') && $r->hasHeader('Authorization', 'Bearer pit-1'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://services.leadconnectorhq.com/contacts/g-amy/workflow/wf-1' && is_string($r['eventStartTime']));
    Http::assertNotSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'g-oli') || ($r['email'] ?? null) === 'oli@x.test'); // opted out of email

    app(SyncRunner::class)->run($c->fresh()); // hourly: nobody twice
    expect(count(Http::recorded(fn (HttpRequest $r): bool => str_contains($r->url(), '/workflow/'))))->toBe(2)
        ->and(DB::connection(Connections::cell())->table('crm_ghl_enrolled')->count())->toBe(2);

    $this->actingAs($this->owner)->post('/settings/ghl-campaigns/enrolments/'.$e->ulid.'/toggle')->assertRedirect();
    expect($e->fresh()?->active)->toBeFalse();
});

it('needs written texting consent for a text campaign, and can start an automation by tag', function (): void {
    ghlFake();
    $c = ghlConnection($this);
    $ben = Contact::query()->where('first_name', 'Ben')->sole();
    app(Consent::class)->optInSms($ben, 'web_form', Consent::DEFAULT_SMS_WORDING);
    $this->actingAs($this->owner)->post('/settings/ghl-campaigns/'.$c->ulid, ['target_type' => 'tag', 'target_id' => 'RA: Spring sellers', 'source_type' => 'tag',
        'source_value' => 'Spring sellers', 'channel' => 'sms'])->assertSessionHasNoErrors();
    expect(GhlEnrolment::query()->sole()->only(['enrolled', 'left_out']))->toBe(['enrolled' => 1, 'left_out' => 2]);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://services.leadconnectorhq.com/contacts/g-ben/tags' && $r['tags'] === ['RA: Spring sellers']);
});

it('turns GoHighLevel "do not disturb" into opt-outs here', function (): void {
    ghlFake(['services.leadconnectorhq.com/contacts/search' => Http::response(['contacts' => [
        ['id' => 'g-amy', 'firstName' => 'Amy', 'lastName' => 'Seller', 'email' => 'amy@x.test', 'dnd' => false, 'dndSettings' => ['Email' => ['status' => 'active']], 'searchAfter' => [1, 'g-amy']],
    ]])]);
    ghlConnection($this);
    expect(ConsentEvent::query()->where('contact_id', $this->contacts['Amy'])->where('channel', 'email')->latest('id')->first()?->only(['action', 'source_ref']))
        ->toBe(['action' => 'opt_out', 'source_ref' => 'GoHighLevel'])
        ->and(ConsentEvent::query()->where('contact_id', $this->contacts['Amy'])->where('channel', 'call')->count())->toBe(0);
});
