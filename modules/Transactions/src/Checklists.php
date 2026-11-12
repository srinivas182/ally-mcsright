<?php

declare(strict_types=1);

namespace Modules\Transactions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;

/**
 * Checklist templates by deal type and state (a state-specific template wins over an any-state one).
 * Ready-made templates are created for each brokerage on first use; brokerages edit their own.
 */
final class Checklists
{
    /** @var array<string, list<array{0: string, 1: bool, 2: string}>> label, required, stage */
    private const DEFAULTS = [
        'listing' => [['Listing agreement', true, 'listing'], ['Seller property disclosure', true, 'listing'], ['Lead-based paint disclosure (homes built before 1978)', false, 'listing'],
            ['Purchase contract', true, 'contract'], ['Earnest money receipt', true, 'contract'], ['Inspection report or waiver', false, 'contract'],
            ['Closing disclosure or settlement statement', true, 'closing'], ['Commission disbursement authorisation (CDA)', true, 'closing']],
        'buyer' => [['Buyer representation agreement', true, 'contract'], ['Purchase contract', true, 'contract'], ['Earnest money receipt', true, 'contract'],
            ['Inspection report or waiver', false, 'contract'], ['Loan approval or proof of funds', false, 'contract'],
            ['Closing disclosure or settlement statement', true, 'closing'], ['Commission disbursement authorisation (CDA)', true, 'closing']],
        'dual' => [['Listing agreement', true, 'listing'], ['Buyer representation agreement', true, 'contract'], ['Dual agency consent', true, 'contract'],
            ['Purchase contract', true, 'contract'], ['Earnest money receipt', true, 'contract'], ['Closing disclosure or settlement statement', true, 'closing'],
            ['Commission disbursement authorisation (CDA)', true, 'closing']],
        'lease' => [['Lease agreement', true, 'contract'], ['Rental application', false, 'contract'], ['Commission agreement', true, 'closing']],
        'referral' => [['Referral agreement', true, 'contract'], ['Closing statement from the receiving brokerage', true, 'closing']],
        'new_construction' => [['Builder purchase agreement', true, 'contract'], ['Builder registration or commission agreement', true, 'contract'],
            ['Final walkthrough', false, 'closing'], ['Closing disclosure or settlement statement', true, 'closing']],
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    public function apply(Deal $deal): void
    {
        $this->ensureDefaults();
        $db = DB::connection(Connections::cell());
        $template = $db->table('tx_templates')->where('deal_type', $deal->type)->where('active', true)
            ->where(fn ($q) => $q->where('state_code', $deal->state_code)->orWhereNull('state_code'))
            ->orderByRaw('state_code is null')->first();
        if ($template === null) {
            return;
        }
        foreach ($db->table('tx_template_items')->where('template_id', $template->id)->orderBy('sort')->get() as $i) {
            Item::query()->create(['deal_id' => $deal->id, 'label' => $i->label, 'required' => (bool) $i->required, 'stage' => $i->stage, 'sort' => $i->sort, 'status' => 'missing']);
        }
    }

    public function ensureDefaults(): void
    {
        $db = DB::connection(Connections::cell());
        if ($db->table('tx_templates')->exists()) {
            return;
        }
        foreach (self::DEFAULTS as $type => $items) {
            $id = $db->table('tx_templates')->insertGetId(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenants->id(), 'name' => ucfirst(str_replace('_', ' ', $type)).' checklist',
                'deal_type' => $type, 'state_code' => null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($items as $n => [$label, $required, $stage]) {
                $db->table('tx_template_items')->insert(['tenant_id' => $this->tenants->id(), 'template_id' => $id, 'label' => $label, 'required' => $required, 'stage' => $stage, 'sort' => $n]);
            }
        }
    }
}
