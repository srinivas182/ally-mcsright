<?php

declare(strict_types=1);

namespace Modules\Transactions\Closing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * The brokerage's preferred vendors (specs/ai/02, §21): the address book for booking appointments, and the
 * recommendations clients see in their moving checklist. Kept by the brokerage; no paid placement.
 */
final class Vendors
{
    public const CATEGORIES = ['inspector' => 'Home inspector', 'appraiser' => 'Appraiser', 'surveyor' => 'Surveyor', 'mover' => 'Movers', 'utilities' => 'Utilities',
        'internet' => 'Internet and TV', 'insurance' => 'Home insurance', 'cleaner' => 'Cleaning', 'locksmith' => 'Locksmith', 'other' => 'Other'];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array{category: string, name: string, email?: string|null, phone?: string|null, website?: string|null, note?: string|null, show_clients?: bool}  $v
     */
    public function save(?string $ulid, array $v, User $by): void
    {
        if (! array_key_exists($v['category'], self::CATEGORIES) || trim($v['name']) === '') {
            throw ValidationException::withMessages(['name' => 'Give the vendor\'s name and what they do.']);
        }
        $website = trim((string) ($v['website'] ?? ''));
        if ($website !== '' && ! preg_match('#^https://#i', $website)) {
            throw ValidationException::withMessages(['website' => 'Use the vendor\'s https:// web address.']);
        }
        $row = ['category' => $v['category'], 'name' => mb_substr(trim($v['name']), 0, 160), 'email' => ($v['email'] ?? '') !== '' ? mb_strtolower(trim((string) $v['email'])) : null,
            'phone' => ($v['phone'] ?? '') !== '' ? mb_substr(trim((string) $v['phone']), 0, 30) : null, 'website' => $website !== '' ? mb_substr($website, 0, 255) : null,
            'note' => ($v['note'] ?? '') !== '' ? mb_substr(trim((string) $v['note']), 0, 300) : null, 'show_clients' => (bool) ($v['show_clients'] ?? true), 'updated_at' => now()];
        $db = DB::connection(Connections::cell());
        $ulid === null
            ? $db->table('tx_vendors')->insert($row + ['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'created_at' => now()])
            : $db->table('tx_vendors')->where('ulid', $ulid)->update($row);
        app(AuditLogger::class)->record('transactions.vendors.saved', metadata: ['name' => $row['name'], 'category' => $row['category']], severity: 'info');
    }

    public function remove(string $ulid, User $by): void
    {
        DB::connection(Connections::cell())->table('tx_vendors')->where('ulid', $ulid)->delete();
        app(AuditLogger::class)->record('transactions.vendors.removed', metadata: ['vendor' => $ulid], severity: 'info');
    }

    /**
     * @return list<array{ulid: string, category: string, name: string, email: string|null, phone: string|null, website: string|null, note: string|null, show_clients: bool}>
     */
    public static function all(bool $clientsOnly = false): array
    {
        return array_values(DB::connection(Connections::cell())->table('tx_vendors')->when($clientsOnly, fn ($q) => $q->where('show_clients', true))->orderBy('category')->orderBy('name')->get()
            ->map(fn ($v): array => ['ulid' => (string) $v->ulid, 'category' => (string) $v->category, 'name' => (string) $v->name, 'email' => $v->email, 'phone' => $v->phone,
                'website' => $v->website, 'note' => $v->note, 'show_clients' => (bool) $v->show_clients])->all());
    }
}
