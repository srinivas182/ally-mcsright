<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Sites\SiteSetup;
use Modules\Transactions\Models\Deal;

/*
| RF1 (specs/transactions/32): "Just sold" and "Just closed" win cards, only with the client's agreement.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->other = User::factory()->create();
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    $this->web = 'http://acme.rightally.site';
    $this->actingAs($this->ann)->post('/deals', ['type' => 'listing', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '450000']);
    $this->deal = Deal::query()->latest('id')->firstOrFail();
});

it('makes a win card for a closed deal only after the client agreed, without the client\'s name', function (): void {
    $this->actingAs($this->ann)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('winCard', null));
    $this->actingAs($this->ann)->post("/deals/{$this->deal->ulid}/win-card", ['client_agreed' => true])->assertSessionHasErrors('win');

    $this->deal->update(['status' => 'closed', 'closed_on' => '2026-08-28', 'price_cents' => 45_000_000]);
    $this->actingAs($this->ann)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('winCard.website', true)->where('winCard.card', null));
    $this->actingAs($this->ann)->post("/deals/{$this->deal->ulid}/win-card", ['message' => 'Sold!'])->assertSessionHasErrors('client_agreed');
    $this->actingAs($this->ann)->post("/deals/{$this->deal->ulid}/win-card", ['client_agreed' => true, 'message' => 'Sold! Perfect for families.'])->assertSessionHasErrors('message');
    $this->actingAs($this->other)->post("/deals/{$this->deal->ulid}/win-card", ['client_agreed' => true])->assertNotFound();

    $this->actingAs($this->ann)->post("/deals/{$this->deal->ulid}/win-card", ['client_agreed' => true, 'show_price' => true])->assertRedirect();
    $card = DB::connection(Connections::cell())->table('tx_win_cards')->sole();
    expect($card->headline)->toBe('Just sold: 12 Oak St, Austin')->and((int) $card->consent_by)->toBe($this->ann->id);

    $html = (string) $this->get($this->web.'/wins/'.$card->ulid)->assertOk()->getContent();
    expect($html)->toContain('Just sold: 12 Oak St, Austin')->toContain('Sold for $450,000')->toContain('Ann Agent')->toContain('"@type":"Article"')
        ->toContain('rel="canonical"')->toContain('facebook.com/sharer');
    expect((string) $this->get($this->web.'/sitemap.xml')->getContent())->toContain('/wins/'.$card->ulid);

    $this->actingAs($this->ann)->get("/deals/{$this->deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('winCard.card.status', 'live')
        ->where('winCard.card.views', 1)->where('winCard.card.url', 'https://acme.rightally.site/wins/'.$card->ulid));
    $this->actingAs($this->ann)->delete("/deals/{$this->deal->ulid}/win-card")->assertRedirect();
    $this->get($this->web.'/wins/'.$card->ulid)->assertNotFound();
});
