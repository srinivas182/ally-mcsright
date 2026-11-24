<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Modules\Team\Help\PageHelp;

/*
| MN3: each main menu has a help panel; it opens by itself once per person per menu, and each lesson can play a
| 60-second video that HQ adds under Help videos with the address guide:<menu>:<lesson>.
*/

beforeEach(function (): void {
    $this->onTenantHost('acme');
    $this->agent = User::factory()->create();
});

it('remembers which menu guides a person has seen, so each opens by itself only once', function (): void {
    $this->actingAs($this->agent)->post('/help/guides/seen', ['key' => 'CRM'])->assertRedirect();
    $this->actingAs($this->agent)->post('/help/guides/seen', ['key' => 'CRM'])->assertRedirect();
    $this->actingAs($this->agent)->post('/help/guides/seen', ['key' => 'Transactions'])->assertRedirect();

    expect($this->agent->fresh()->ui_guides_seen)->toBe(['CRM', 'Transactions']);
    $this->post('/logout');
    $this->post('/help/guides/seen', ['key' => 'CRM'])->assertRedirect('/login');
});

it('plays the videos HQ adds for help-panel lessons, and only from safe places', function (): void {
    PageHelp::setVideo('guide:crm:new-leads', 'Answer every new lead', 'https://youtu.be/dQw4w9WgXcQ', 1);
    PageHelp::setVideo('/contacts', 'Adding a person', 'https://vimeo.com/123456789', 1);

    $this->actingAs($this->agent)->getJson('/help/guides/videos')->assertOk()
        ->assertExactJson(['guide:crm:new-leads' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ']);
    // A lesson's video never shows as a page's video.
    $this->actingAs($this->agent)->getJson('/help/page?path=/leads')->assertJsonPath('video', null);

    expect(fn () => PageHelp::setVideo('guide:crm:new-leads', 'x', 'javascript:alert(1)', 1))->toThrow(ValidationException::class)
        ->and(fn () => PageHelp::setVideo('guide:<script>:x', 'x', 'https://youtu.be/dQw4w9WgXcQ', 1))->toThrow(ValidationException::class);
});

it('has a detailed guide behind every main menu', function (): void {
    $guides = (string) file_get_contents(base_path('resources/js/components/menu/guides.ts'));
    foreach (['Dashboard', 'CRM', 'Listings', 'Transactions', 'Marketing', "'Website & IDX'", 'Agents', 'Commissions', "'My commissions'", 'Accounting', 'Reports', 'Training', 'Settings'] as $menu) {
        expect($guides)->toMatch('/\n    '.preg_quote($menu, '/').': \{/');
    }
    foreach (['Plan a winning day', 'Close more deals', 'Win more listings', 'Close error-free files', 'Get seen everywhere', 'Turn visitors into leads', 'Grow and keep your team',
        'Pay agents right, on time', 'Keep books audit-ready', 'Know your numbers', 'Set up in minutes', 'Close without stress', 'Grow your earnings', 'Learn faster'] as $title) {
        expect($guides)->toContain("title: '{$title}'");
    }
});
