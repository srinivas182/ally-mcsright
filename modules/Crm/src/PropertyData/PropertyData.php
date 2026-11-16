<?php

declare(strict_types=1);

namespace Modules\Crm\PropertyData;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AI\Exceptions\InsufficientCredits;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Property data (specs/connect/21, C8b), on RightAlly's accounts, paid with credits per lookup:
 *
 * - ATTOM (app `property_data`): owner name, last sale, the automated value range and the home's facts.
 * - BatchData (app `batchdata`): the owners' names and mailing address (for letters and postcards).
 *
 * Phone numbers and emails are not taken from either: people who never asked to hear from an agent can't be texted
 * (TCPA), so prospecting by mail is the only use. Each answer is kept for 30 days per brokerage; looking up the same
 * address again within that time costs nothing.
 */
final class PropertyData
{
    public const KEEP_DAYS = 30;

    public const ATTOM = 'https://api.gateway.attomdata.com/propertyapi/v1.0.0/';

    public const BATCHDATA = 'https://api.batchdata.com/api/v1/';

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array{attom: bool, batchdata: bool} */
    public function available(): array
    {
        return ['attom' => $this->on('property_data'), 'batchdata' => $this->on('batchdata')];
    }

    /**
     * @param  array{street: string, city: string, state: string, zip: string}  $address
     * @return array{data: array<string, mixed>, fetched_at: string, charged: bool}
     */
    public function lookup(User $by, string $vendor, array $address): array
    {
        if (! in_array($vendor, ['attom', 'batchdata'], true) || ! $this->on($vendor === 'attom' ? 'property_data' : 'batchdata')) {
            throw ValidationException::withMessages(['vendor' => 'This lookup isn\'t switched on yet. Please ask RightAlly support.']);
        }
        $a = array_map(fn ($v): string => trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $v))), $address);
        if ($a['street'] === '' || ($a['zip'] === '' && ($a['city'] === '' || $a['state'] === ''))) {
            throw ValidationException::withMessages(['street' => 'Enter the street address and the ZIP code (or the city and state).']);
        }
        $line = mb_substr($a['street'].', '.trim($a['city'].' '.$a['state'].' '.$a['zip']), 0, 200);
        $key = sha1(mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', $line)));
        $db = DB::connection(Connections::cell())->table('crm_property_lookups');
        $kept = $db->where('tenant_id', $this->tenants->id())->where('vendor', $vendor)->where('address_key', $key)
            ->where('fetched_at', '>=', CarbonImmutable::now()->subDays(self::KEEP_DAYS))->first();
        if ($kept !== null) {
            return ['data' => (array) json_decode((string) $kept->data, true), 'fetched_at' => (string) $kept->fetched_at, 'charged' => false];
        }
        $action = $vendor === 'attom' ? 'data.property' : 'data.owner';
        $ref = $action.':'.(string) Str::ulid();
        try {
            app(CreditLedger::class)->charge($action, $by->id, $ref);
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['vendor' => $e->getMessage()]);
        }
        try {
            $data = $vendor === 'attom' ? $this->attom($a) : $this->batchdata($a);
        } catch (Throwable $e) {
            app(CreditLedger::class)->refund($ref, 'Property lookup did not work'); // nothing found or the service failed: the credits go back
            throw ValidationException::withMessages(['street' => $e->getMessage()]);
        }
        $now = CarbonImmutable::now();
        $db->updateOrInsert(['tenant_id' => $this->tenants->id(), 'vendor' => $vendor, 'address_key' => $key],
            ['address' => $line, 'data' => json_encode($data), 'user_id' => $by->id, 'fetched_at' => $now, 'updated_at' => $now, 'created_at' => $now]);
        app(AuditLogger::class)->record('crm.property_lookup', metadata: ['vendor' => $vendor]);

        return ['data' => $data, 'fetched_at' => (string) $now, 'charged' => true];
    }

    /**
     * @param  array<string, string>  $a
     * @return array<string, mixed>
     */
    private function attom(array $a): array
    {
        $http = Http::withHeaders(['apikey' => $this->key('property_data')])->acceptJson()->timeout(20);
        $q = ['address1' => $a['street'], 'address2' => trim($a['city'].($a['city'] !== '' ? ', ' : '').$a['state'].' '.$a['zip'])];
        $p = $http->get(self::ATTOM.'property/expandedprofile', $q);
        if ($p->status() === 400 || (int) $p->json('status.total', 1) === 0) {
            throw new \RuntimeException('ATTOM has no record for that address. Check the street and ZIP code.');
        }
        $p->throw();
        $one = (array) ($p->json('property.0') ?? []);
        $avm = $http->get(self::ATTOM.'attomavm/detail', $q);
        $value = $avm->successful() ? (array) ($avm->json('property.0.avm.amount') ?? []) : [];
        $owner = (array) ($one['owner'] ?? []);
        $names = array_values(array_filter(array_map(fn ($o): string => is_array($o) ? trim((string) ($o['fullName'] ?? trim(($o['firstNameAndMi'] ?? '').' '.($o['lastName'] ?? '')))) : '',
            [$owner['owner1'] ?? null, $owner['owner2'] ?? null])));
        $num = fn (mixed $v): ?int => is_numeric($v) ? (int) $v : null;

        return ['source' => 'ATTOM', 'address' => (string) ($one['address']['oneLine'] ?? ''), 'owners' => $names,
            'owner_occupied' => isset($one['summary']['absenteeInd']) ? ! str_contains(strtoupper((string) $one['summary']['absenteeInd']), 'ABSENTEE') : null,
            'beds' => $num($one['building']['rooms']['beds'] ?? null), 'baths' => is_numeric($one['building']['rooms']['bathstotal'] ?? null) ? (float) $one['building']['rooms']['bathstotal'] : null,
            'sqft' => $num($one['building']['size']['livingsize'] ?? $one['building']['size']['universalsize'] ?? null), 'year_built' => $num($one['summary']['yearbuilt'] ?? null),
            'lot_sqft' => $num($one['lot']['lotsize2'] ?? null),
            'last_sale' => ['price' => $num($one['sale']['amount']['saleamt'] ?? null), 'date' => is_string($one['sale']['amount']['salerecdate'] ?? null) ? $one['sale']['amount']['salerecdate'] : (is_string($one['sale']['saleTransDate'] ?? null) ? $one['sale']['saleTransDate'] : null)],
            'value' => ['mid' => $num($value['value'] ?? null), 'low' => $num($value['low'] ?? null), 'high' => $num($value['high'] ?? null)],
            'tax' => ['assessed' => $num($one['assessment']['assessed']['assdttlvalue'] ?? null), 'year' => $num($one['assessment']['tax']['taxyear'] ?? null)]];
    }

    /**
     * @param  array<string, string>  $a
     * @return array<string, mixed>
     */
    private function batchdata(array $a): array
    {
        $r = Http::withToken($this->key('batchdata'))->acceptJson()->asJson()->timeout(20)->post(self::BATCHDATA.'property/lookup/all-attributes', [
            'requests' => [['address' => ['street' => $a['street'], 'city' => $a['city'], 'state' => $a['state'], 'zip' => $a['zip']]]]]);
        $r->throw();
        $p = (array) ($r->json('results.properties.0') ?? []);
        if ($p === []) {
            throw new \RuntimeException('BatchData has no record for that address. Check the street and ZIP code.');
        }
        $owner = (array) ($p['owner'] ?? []);
        $mail = (array) ($owner['mailingAddress'] ?? []);
        $names = array_values(array_filter(array_map(fn ($n): string => is_array($n) ? trim((string) ($n['full'] ?? trim(($n['first'] ?? '').' '.($n['last'] ?? '')))) : '', (array) ($owner['names'] ?? []))));

        return ['source' => 'BatchData', 'owners' => $names,
            'mailing' => array_filter(['street' => $mail['street'] ?? null, 'city' => $mail['city'] ?? null, 'state' => $mail['state'] ?? null, 'zip' => $mail['zip'] ?? null], 'is_string'),
            'owner_occupied' => isset($p['quickLists']['ownerOccupied']) ? (bool) $p['quickLists']['ownerOccupied'] : null];
    }

    private function on(string $app): bool
    {
        $a = app(AppCatalog::class)->find($app);

        return $a !== null && app(AppRegistry::class)->ready($a);
    }

    private function key(string $app): string
    {
        $a = app(AppCatalog::class)->find($app);
        $keys = $a !== null ? app(AppRegistry::class)->secrets($a) : [];

        return (string) ($keys['api_key'] ?? '');
    }
}
