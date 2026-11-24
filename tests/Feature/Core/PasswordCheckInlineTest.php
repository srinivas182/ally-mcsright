<?php

declare(strict_types=1);

use App\Models\User;

/*
| UX1: a change that needs a fresh password keeps the person on their page (the password box opens over it) instead of
| sending them to another page and losing what they typed. Links and downloads still use the full page.
*/

beforeEach(function (): void {
    $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
});

it('sends an app form back to its page with a password request instead of leaving it', function (): void {
    $this->actingAs($this->owner)->from('http://acme.rightally.app/settings/mls')
        ->withHeaders(['X-Inertia' => 'true'])
        ->put('http://acme.rightally.app/settings/mls/boards/01JQ7ZZ8W2B4C6D8E0F1G2H3J4', ['member_id' => 'M1'])
        ->assertRedirect('http://acme.rightally.app/settings/mls')
        ->assertSessionHasErrors('confirm_password');
});

it('still uses the full password page for ordinary requests', function (): void {
    $this->actingAs($this->owner)
        ->put('http://acme.rightally.app/settings/mls/boards/01JQ7ZZ8W2B4C6D8E0F1G2H3J4', ['member_id' => 'M1'])
        ->assertRedirect(route('password.confirm'));
});
