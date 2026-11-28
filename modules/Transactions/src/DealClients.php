<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Support\Connections;
use Modules\Transactions\Models\Deal;

/**
 * FX5: the person a deal is for, by name, for the pipeline and listing boards (agents think in people, not
 * addresses): the seller on a listing, the buyer on a buyer deal (both on a dual), the tenant or landlord on a lease.
 * From the deal's parties, else the CRM contact the deal was made from.
 */
final class DealClients
{
    private const ROLES = [
        'listing' => ['seller'], 'buyer' => ['buyer'], 'dual' => ['seller', 'buyer'], 'lease' => ['tenant', 'landlord'],
        'new_construction' => ['buyer'], 'referral' => ['buyer', 'seller', 'tenant', 'landlord'],
    ];

    /**
     * @param  Collection<int, Deal>  $deals
     * @return array<int, array{name: string, role: string}> deal id => the client's name and their role
     */
    public static function for(Collection $deals): array
    {
        if ($deals->isEmpty()) {
            return [];
        }
        $db = DB::connection(Connections::cell());
        $parties = $db->table('tx_parties')->whereIn('deal_id', $deals->pluck('id')->all())->whereIn('role', ['buyer', 'seller', 'tenant', 'landlord'])
            ->orderBy('id')->get(['deal_id', 'role', 'name'])->groupBy('deal_id');
        $contactIds = $deals->pluck('contact_id')->filter()->unique()->all();
        $contacts = $contactIds !== [] && Schema::connection(Connections::cell())->hasTable('crm_contacts')
            ? $db->table('crm_contacts')->whereIn('id', $contactIds)->get(['id', 'first_name', 'last_name', 'company_name'])->keyBy('id') : collect();

        $out = [];
        foreach ($deals as $d) {
            $wanted = self::ROLES[$d->type] ?? ['buyer', 'seller'];
            $rows = $parties->get($d->id, collect());
            $names = [];
            foreach ($wanted as $role) {
                $name = trim((string) ($rows->firstWhere('role', $role)->name ?? ''));
                if ($name !== '') {
                    $names[$role] = $name;
                }
            }
            if ($names === [] && $d->contact_id !== null && ($c = $contacts->get($d->contact_id)) !== null) {
                $name = trim(((string) $c->first_name).' '.((string) $c->last_name)) ?: (string) $c->company_name;
                if ($name !== '') {
                    $names[$wanted[0]] = $name;
                }
            }
            if ($names !== []) {
                $out[$d->id] = ['name' => implode(' and ', array_unique(array_values($names))), 'role' => self::label(array_keys($names), $d->type)];
            }
        }

        return $out;
    }

    /** @param  list<string>  $roles */
    private static function label(array $roles, string $type): string
    {
        if ($type === 'dual' && count($roles) === 2) {
            return 'Seller and buyer';
        }

        return ucfirst($roles[0]);
    }
}
