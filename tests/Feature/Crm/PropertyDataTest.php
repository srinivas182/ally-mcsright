<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Crm\ContactImporter;

/* Sprint C8b: property data — ATTOM facts, last sale and value range, BatchData owners and mailing address, paid with
   credits per address and free again for 30 days; no phones or emails; PropStream lists through CSV import. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create();
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    app(CreditLedger::class)->add($this->jane->id, 100, 'adjust', 'Test credits', $this->owner);
});

function dataOn(string $app, string $key): void
{
    app(AppRegistry::class)->save(app(AppCatalog::class)->find($app), true, ['api_key' => $key], null, User::factory()->make(['id' => 999999]));
}

it('looks up an address with ATTOM for credits, and again within 30 days for free', function (): void {
    $this->actingAs($this->jane)->get('/property-lookup')->assertInertia(fn (Assert $p) => $p->where('available.attom', false));
    $this->actingAs($this->jane)->post('/property-lookup', ['vendor' => 'attom', 'street' => '201 Pine Rd', 'zip' => '78704'])->assertSessionHasErrors('vendor');
    dataOn('property_data', 'attom-key');
    Http::fake([
        'api.gateway.attomdata.com/propertyapi/v1.0.0/property/expandedprofile*' => Http::response(['status' => ['total' => 1], 'property' => [[
            'address' => ['oneLine' => '201 PINE RD, AUSTIN, TX 78704'], 'summary' => ['yearbuilt' => 1998, 'absenteeInd' => 'OWNER OCCUPIED'],
            'building' => ['rooms' => ['beds' => 3, 'bathstotal' => 2.5], 'size' => ['livingsize' => 2140]], 'owner' => ['owner1' => ['fullName' => 'MARIA LOPEZ']],
            'sale' => ['amount' => ['saleamt' => 410000, 'salerecdate' => '2019-04-12']]]]]),
        'api.gateway.attomdata.com/propertyapi/v1.0.0/attomavm/detail*' => Http::response(['property' => [['avm' => ['amount' => ['value' => 525000, 'low' => 498000, 'high' => 552000]]]]]),
    ]);
    $this->actingAs($this->jane)->post('/property-lookup', ['vendor' => 'attom', 'street' => '201 Pine Rd', 'city' => 'Austin', 'state' => 'tx', 'zip' => '78704'])->assertRedirect('/property-lookup');
    $this->actingAs($this->jane)->get('/property-lookup')->assertInertia(fn (Assert $p) => $p->where('result.charged', true)->where('result.data.owners', ['MARIA LOPEZ'])
        ->where('result.data.value', ['mid' => 525000, 'low' => 498000, 'high' => 552000])->where('result.data.last_sale', ['price' => 410000, 'date' => '2019-04-12'])
        ->where('result.data.owner_occupied', true)->where('result.data.beds', 3));
    expect(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(60);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'expandedprofile') && $r->header('apikey')[0] === 'attom-key' && $r['address1'] === '201 Pine Rd'
        && $r['address2'] === 'Austin, TX 78704');

    $this->actingAs($this->jane)->post('/property-lookup', ['vendor' => 'attom', 'street' => '201  Pine Rd.', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704']);
    $this->actingAs($this->jane)->get('/property-lookup')->assertInertia(fn (Assert $p) => $p->where('result.charged', false));
    expect(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(60);
    Http::assertSentCount(2);
});

it('gives the credits back when there is no record, and refuses when they run out', function (): void {
    dataOn('property_data', 'attom-key');
    Http::fake(['api.gateway.attomdata.com/*' => Http::response(['status' => ['code' => 1, 'msg' => 'SuccessWithoutResult', 'total' => 0]])]);
    $this->actingAs($this->jane)->post('/property-lookup', ['vendor' => 'attom', 'street' => '1 Nowhere St', 'zip' => '78704'])->assertSessionHasErrors('street');
    expect(app(CreditLedger::class)->balanceOf($this->jane->id))->toBe(100);
    app(CreditLedger::class)->add($this->jane->id, -90, 'adjust', 'Spent', $this->owner);
    $this->actingAs($this->jane)->post('/property-lookup', ['vendor' => 'attom', 'street' => '2 Other St', 'zip' => '78704'])->assertSessionHasErrors('vendor');
});

it('finds owners and their mailing address with BatchData, and never takes phones or emails', function (): void {
    dataOn('batchdata', 'bd-token');
    Http::fake(['api.batchdata.com/api/v1/property/lookup/all-attributes' => Http::response(['results' => ['properties' => [[
        'owner' => ['names' => [['full' => 'Maria Lopez'], ['first' => 'Juan', 'last' => 'Lopez']], 'mailingAddress' => ['street' => '9 Elm St', 'city' => 'Dallas', 'state' => 'TX', 'zip' => '75201'],
            'phoneNumbers' => [['number' => '5125550100']], 'emails' => [['email' => 'maria@x.test']]],
        'quickLists' => ['ownerOccupied' => false]]]]])]);
    $this->actingAs($this->jane)->post('/property-lookup', ['vendor' => 'batchdata', 'street' => '201 Pine Rd', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78704'])->assertSessionHasNoErrors();
    $this->actingAs($this->jane)->get('/property-lookup')->assertInertia(fn (Assert $p) => $p->where('result.data.owners', ['Maria Lopez', 'Juan Lopez'])
        ->where('result.data.mailing', ['street' => '9 Elm St', 'city' => 'Dallas', 'state' => 'TX', 'zip' => '75201'])->where('result.data.owner_occupied', false));
    expect(json_encode(DB::connection(Connections::cell())->table('crm_property_lookups')->value('data')))->not->toContain('5125550100')->not->toContain('maria@x.test');
    Http::assertSent(fn (HttpRequest $r): bool => $r->header('Authorization')[0] === 'Bearer bd-token' && $r['requests'][0]['address']['zip'] === '78704');
});

it('matches PropStream export columns when importing a list', function (): void {
    expect(ContactImporter::guess(['Owner 1 First Name', 'Owner 1 Last Name', 'Mailing Address', 'Mailing City', 'Mailing State', 'Mailing Zip', 'Phone 1']))
        ->toMatchArray(['Owner 1 First Name' => 'first_name', 'Owner 1 Last Name' => 'last_name', 'Mailing Address' => 'address_line1', 'Mailing City' => 'city',
            'Mailing State' => 'state', 'Mailing Zip' => 'zip', 'Phone 1' => 'phone']);
});
