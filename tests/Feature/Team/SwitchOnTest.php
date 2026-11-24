<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Support\Connections;

/*
| PG4: a page whose feature is off asks RightAlly to switch it on, once per brokerage while the request is open.
*/

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
});

it('sends one request per feature to RightAlly, and asking again adds nothing', function (): void {
    $this->actingAs($this->agent)->post('/help/switch-on', ['feature' => 'postcards', 'page' => '/postcards'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/help/switch-on', ['feature' => 'postcards']);

    $tickets = DB::connection(Connections::control())->table('support_tickets')->get();
    expect($tickets)->toHaveCount(1)->and($tickets[0]->subject)->toBe('Please switch on Postcards')->and($tickets[0]->topic)->toBe('idea')
        ->and($tickets[0]->tenant_id)->toBe($this->tenant->id);

    // Another feature is its own request; once RightAlly closes one, it can be asked again.
    $this->actingAs($this->owner)->post('/help/switch-on', ['feature' => 'ads']);
    DB::connection(Connections::control())->table('support_tickets')->where('subject', 'Please switch on Postcards')->update(['status' => 'closed']);
    $this->actingAs($this->owner)->post('/help/switch-on', ['feature' => 'postcards']);
    expect(DB::connection(Connections::control())->table('support_tickets')->count())->toBe(3);
});

it('refuses features it does not know', function (): void {
    $this->actingAs($this->agent)->post('/help/switch-on', ['feature' => 'bank_transfers'])->assertSessionHasErrors('feature');
    expect(DB::connection(Connections::control())->table('support_tickets')->count())->toBe(0);
});
