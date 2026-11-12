<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Compensation\Models\Calculation;
use Modules\Compensation\RevenueShare\Sponsorships;
use Modules\Crm\Actions\ManageContacts;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Document;
use Modules\Transactions\Models\Item;

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->other = User::factory()->create();
    $this->sponsor = User::factory()->create(['first_name' => 'Sponsor']);
    app(Sponsorships::class)->set($this->agent, $this->sponsor, CarbonImmutable::parse('2026-01-01'));
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80'], 'revenue_share' => ['basis' => 'net_profit', 'levels' => ['100']]]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->contact = app(ManageContacts::class)->create(['first_name' => 'Jordan', 'last_name' => 'Buyer', 'email' => 'jordan@x.test'], $this->agent);
});

function newDeal(): Deal
{
    test()->actingAs(test()->agent)->post('/deals', ['type' => 'buyer', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'tx', 'price' => '400000', 'contact' => test()->contact->ulid])
        ->assertRedirect();

    return Deal::query()->latest('id')->firstOrFail();
}

function jpeg(): UploadedFile
{
    // Smallest valid JPEG (1×1), so file-type detection is real without the GD extension.
    return UploadedFile::fake()->createWithContent('page.jpg', base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='));
}

function pdf(string $name = 'contract.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

it('creates a deal from a contact with its checklist and the client as a party, visible only to its agent and the brokerage', function (): void {
    $deal = newDeal();
    expect($deal->state_code)->toBe('TX')->and($deal->status)->toBe('pre_contract')
        ->and(Item::query()->where('deal_id', $deal->id)->pluck('label')->all())->toContain('Buyer representation agreement', 'Purchase contract', 'Commission disbursement authorisation (CDA)')
        ->and(DB::table('tx_parties')->where('deal_id', $deal->id)->value('name'))->toBe('Jordan Buyer');

    $this->actingAs($this->other)->get("/deals/{$deal->ulid}")->assertForbidden();
    $this->actingAs(User::factory()->assistantOf($this->agent)->create())->get("/deals/{$deal->ulid}")->assertOk();
    $this->actingAs($this->other)->get('/deals')->assertInertia(fn (Assert $p) => $p->has('deals', 0));
    $this->actingAs($this->owner)->get('/deals')->assertInertia(fn (Assert $p) => $p->where('deals.0.street', '12 Oak St')->where('deals.0.missing', 5));
});

it('calculates the commission when the deal goes under contract', function (): void {
    $deal = newDeal();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '400000'])->assertSessionHasErrors('status');
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '410000', 'gci' => '12300', 'contract_date' => '2026-06-01', 'closing_date' => '2026-07-01'])
        ->assertSessionHasNoErrors();

    $deal->refresh();
    $calc = Calculation::query()->findOrFail($deal->calculation_id);
    expect($deal->status)->toBe('under_contract')->and($deal->price_cents)->toBe(41_000_000)->and($calc->status)->toBe('pending')
        ->and($calc->result['agents'][0]['agent_net_cents'])->toBe(984_000);
});

it('keeps every version of an upload and refuses unsupported files', function (): void {
    $deal = newDeal();
    $item = Item::query()->where('deal_id', $deal->id)->where('label', 'Purchase contract')->firstOrFail();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => pdf(), 'item' => $item->ulid])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => jpeg(), 'item' => $item->ulid, 'via' => 'photo'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'), 'item' => $item->ulid])->assertSessionHasErrors('file');

    $docs = Document::query()->where('item_id', $item->id)->orderBy('version')->get();
    expect($docs->pluck('version')->all())->toBe([1, 2])->and($docs[1]->via)->toBe('photo')->and($item->fresh()?->status)->toBe('submitted');
    Storage::disk('local')->assertExists($docs[0]->path);
    $this->actingAs($this->agent)->get("/deals/documents/{$docs[0]->ulid}")->assertOk();
    $this->actingAs($this->other)->get("/deals/documents/{$docs[0]->ulid}")->assertForbidden();
});

it('lets the brokerage approve, request fixes from a learning library, and waive — not the agent', function (): void {
    $deal = newDeal();
    $item = Item::query()->where('deal_id', $deal->id)->where('label', 'Purchase contract')->firstOrFail();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => pdf(), 'item' => $item->ulid]);

    $this->actingAs($this->agent)->post("/deals/items/{$item->ulid}/review", ['action' => 'approve'])->assertForbidden();
    $this->actingAs($this->owner)->post("/deals/items/{$item->ulid}/review", ['action' => 'fix', 'reason' => 'Buyer initials missing on page 4'])->assertSessionHasNoErrors();
    expect($item->fresh()?->status)->toBe('needs_fix')->and($item->fresh()?->fix_reason)->toBe('Buyer initials missing on page 4');

    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => pdf('contract-v2.pdf'), 'item' => $item->ulid]);
    $this->actingAs($this->owner)->post("/deals/items/{$item->ulid}/review", ['action' => 'approve'])->assertSessionHasNoErrors();
    $lender = Item::query()->where('deal_id', $deal->id)->where('label', 'Loan approval or proof of funds')->firstOrFail();
    $this->actingAs($this->owner)->post("/deals/items/{$lender->ulid}/review", ['action' => 'waive', 'reason' => 'Cash purchase'])->assertSessionHasNoErrors();

    expect($item->fresh()?->status)->toBe('approved')->and($lender->fresh()?->status)->toBe('waived')
        ->and(DB::table('tx_fix_reasons')->pluck('text')->all())->toBe(['Buyer initials missing on page 4']);
});

it('closes only when every required document is approved, then finalises commission, pays revenue share and locks the file', function (): void {
    $deal = newDeal();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'under_contract', 'price' => '400000', 'gci' => '12000', 'contract_date' => '2026-06-01', 'closing_date' => '2026-06-20']);
    $this->actingAs($this->owner)->post("/deals/{$deal->ulid}/status", ['status' => 'closed'])->assertSessionHasErrors('status');
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'closed'])->assertForbidden();

    foreach (Item::query()->where('deal_id', $deal->id)->where('required', true)->get() as $item) {
        $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => pdf(), 'item' => $item->ulid]);
        $this->actingAs($this->owner)->post("/deals/items/{$item->ulid}/review", ['action' => 'approve']);
    }
    $this->travelTo(CarbonImmutable::parse('2026-06-20 15:00'));
    $this->actingAs($this->owner)->post("/deals/{$deal->ulid}/status", ['status' => 'closed'])->assertSessionHasNoErrors();

    $deal->refresh();
    expect($deal->status)->toBe('closed')->and($deal->locked_at)->not->toBeNull()->and($deal->closed_on?->toDateString())->toBe('2026-06-20')
        ->and(Calculation::query()->findOrFail($deal->calculation_id)->status)->toBe('final')
        ->and((int) DB::table('rs_entries')->where('recipient_user_id', $this->sponsor->id)->sum('amount_cents'))->toBe(240_000);
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => pdf()])->assertSessionHasErrors('file');
});

it('needs a reason when a deal falls through, and shows the brokerage an SLA review queue', function (): void {
    $deal = newDeal();
    $item = Item::query()->where('deal_id', $deal->id)->where('label', 'Purchase contract')->firstOrFail();
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/documents", ['file' => pdf(), 'item' => $item->ulid]);

    $this->actingAs($this->owner)->get('/deals/review')->assertInertia(fn (Assert $p) => $p->where('items.0.label', 'Purchase contract')->where('items.0.overdue', false));
    $this->travel(25)->hours();
    $this->actingAs($this->owner)->get('/deals/review')->assertInertia(fn (Assert $p) => $p->where('items.0.overdue', true));
    $this->actingAs($this->agent)->get('/deals/review')->assertForbidden();

    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'fell_through'])->assertSessionHasErrors('reason');
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/status", ['status' => 'fell_through', 'reason' => 'Buyer financing denied'])->assertSessionHasNoErrors();
    expect($deal->fresh()?->status)->toBe('fell_through');
});
