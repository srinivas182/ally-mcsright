<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Core\Support\Connections;
use Modules\Crm\Inbox\SendGuard;
use Modules\Crm\Models\Contact;
use Modules\Crm\Practice\PracticeClients;
use Modules\Team\Help\PageHelp;

/*
| SX5: help everywhere — "Help" on every page (video, cheat sheet, call me back) and practice with made-up clients.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'phone' => '+15125550100']);
    $this->bob = User::factory()->create(['first_name' => 'Bob']);
});

it('gives the how-to and video for the page the person is on, and a printable cheat sheet', function (): void {
    PageHelp::setVideo('/contacts', 'Adding a person', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 1);

    $this->actingAs($this->agent)->getJson('/help/page?path=/contacts/01ABC')->assertOk()
        ->assertJsonPath('article.slug', 'add-a-contact')
        ->assertJsonPath('video.url', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->assertJsonPath('phone', '+15125550100')->assertJsonPath('practice', false);
    $this->actingAs($this->agent)->getJson('/help/page?path=/deals')->assertJsonPath('article.slug', 'deals-and-documents')->assertJsonPath('video', null);

    $this->actingAs($this->agent)->get('/help/add-a-contact/print')->assertOk()->assertSee('Add a contact or bring in your list')->assertSee('Print this page');
    expect(fn () => PageHelp::setVideo('/contacts', 'x', 'javascript:alert(1)', 1))->toThrow(ValidationException::class);
});

it('asks RightAlly to call back, with the number and the best time', function (): void {
    $this->actingAs($this->agent)->post('/help/callback', ['phone' => '(512) 555-0100', 'when' => 'morning', 'page' => '/contacts'])->assertSessionHasNoErrors();
    $t = DB::connection(Connections::control())->table('support_tickets')->sole();
    expect($t->topic)->toBe('callback')->and($t->subject)->toBe('Please call me back in the morning')
        ->and(Crypt::decryptString((string) $t->body))->toContain('(512) 555-0100');
    $this->actingAs($this->agent)->post('/help/callback', ['phone' => 'call me', 'when' => 'morning'])->assertSessionHasErrors('phone');
});

it('adds five made-up practice people that only the person practising sees, and never messages them', function (): void {
    $this->actingAs($this->agent)->post('/practice')->assertRedirect('/contacts?search=Practice');
    $this->actingAs($this->agent)->post('/practice'); // once only
    $mine = Contact::query()->where('practice', true)->get();
    expect($mine)->toHaveCount(5)->and($mine->every(fn (Contact $c): bool => $c->last_name === 'Practice' && str_ends_with((string) $c->primary_email, '@example.com')
        && str_starts_with((string) $c->primary_phone, '+1512555'.'01')))->toBeTrue();

    $rosa = $mine->firstWhere('first_name', 'Rosa');
    $this->actingAs($this->agent)->get('/contacts/'.$rosa->ulid)->assertOk();
    $this->actingAs($this->bob)->get('/contacts/'.$rosa->ulid)->assertNotFound();
    $this->actingAs($this->owner)->get('/contacts/'.$rosa->ulid)->assertNotFound();
    expect(app(SendGuard::class)->check($rosa, 'email', 'Hello'))->toBe(['This is a made-up practice person, so nothing is sent. Everything else works as usual.']);

    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '1 Lake Rd', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '300000', 'contact' => $rosa->ulid])
        ->assertSessionHasErrors('contact');
    $this->actingAs($this->agent)->getJson('/help/page?path=/contacts')->assertJsonPath('practice', true);

    $this->actingAs($this->agent)->post('/practice/clear')->assertRedirect();
    expect(Contact::query()->withTrashed()->where('practice', true)->count())->toBe(0);
});

it('removes practice people by itself after two weeks', function (): void {
    app(PracticeClients::class)->add($this->agent);
    $this->travel(15)->days();
    $this->artisan('crm:practice-cleanup')->assertSuccessful();
    expect(Contact::query()->withTrashed()->where('practice', true)->count())->toBe(0);
});
