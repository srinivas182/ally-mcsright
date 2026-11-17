<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Connect\Models\Connection;
use Modules\Crm\Leads\LeadSources;
use Modules\Crm\Leads\PortalEmail;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-03 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    app()->instance('connect.dns', fn (string $host): array => match ($host) {
        'hooks.zapier.com' => ['54.86.10.10'],
        'evil.example.com' => ['10.0.0.5'],
        default => [],
    });
    $this->key = app(LeadSources::class)->create(['name' => 'Zapier', 'kind' => 'api', 'source' => 'facebook'], $this->owner)['key'];
});

it('checks a Zapier key and lists the events', function (): void {
    $this->getJson('/api/zapier/me')->assertUnauthorized();
    $this->getJson('/api/zapier/me', ['Authorization' => 'Bearer '.$this->key])->assertOk()
        ->assertJsonPath('brokerage', $this->tenant->name)->assertJsonPath('key', 'Zapier')->assertJsonPath('events.0', 'lead.created');
});

it('subscribes and unsubscribes instant triggers as ordinary signed webhooks the broker can see', function (): void {
    $auth = ['Authorization' => 'Bearer '.$this->key];
    $this->postJson('/api/zapier/hooks', ['target_url' => 'https://evil.example.com/x', 'event' => 'lead.created'], $auth)->assertStatus(422);
    $this->postJson('/api/zapier/hooks', ['target_url' => 'https://hooks.zapier.com/1/abc', 'event' => 'secret.event'], $auth)->assertStatus(422);
    $id = $this->postJson('/api/zapier/hooks', ['target_url' => 'https://hooks.zapier.com/1/abc', 'event' => 'lead.created'], $auth)->assertCreated()->json('id');

    $c = Connection::query()->where('ulid', $id)->sole();
    expect($c->app_key)->toBe('webhooks')->and($c->settings['events'])->toBe(['lead.created'])->and($c->label)->toContain('Zapier')
        ->and($c->credentials['secret'])->toStartWith('whsec_');

    $other = app(LeadSources::class)->create(['name' => 'Other', 'kind' => 'api', 'source' => 'website'], $this->owner)['key'];
    $this->deleteJson("/api/zapier/hooks/{$id}", [], ['Authorization' => 'Bearer '.$other])->assertNotFound(); // only the key that made it
    $this->deleteJson("/api/zapier/hooks/{$id}", [], $auth)->assertNoContent();
    expect(Connection::query()->where('ulid', $id)->exists())->toBeFalse();
});

it('gives made-up sample data, never a real person', function (): void {
    $this->getJson('/api/zapier/samples/lead.created', ['Authorization' => 'Bearer '.$this->key])->assertOk()
        ->assertJsonPath('0.event', 'lead.created')->assertJsonPath('0.data.contact.email', 'alex@example.com');
    $this->getJson('/api/zapier/samples/nope.nope', ['Authorization' => 'Bearer '.$this->key])->assertNotFound();
});

it('recognises Opcity, Homesnap and Facebook lead ad emails', function (): void {
    expect(PortalEmail::portal('leads@opcity.com'))->toBe('realtor_com')
        ->and(PortalEmail::portal('notify@homesnap.com'))->toBe('homes_com')
        ->and(PortalEmail::portal('notification@facebookmail.com'))->toBe('facebook');
});
