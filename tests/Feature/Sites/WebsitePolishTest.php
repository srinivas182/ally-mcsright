<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Support\Connections;
use Modules\Sites\SiteSetup;

/*
| UX1: one logo for the brokerage (Brand kit → website), a full contact page, photo tiles for the areas served
| (including every MLS board's state), "Agent login" with the footer resources, and "Powered by RightAlly" in the
| website assistant.
*/

beforeEach(function (): void {
    Notification::fake();
    Storage::fake('public');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->site = app(SiteSetup::class)->create('modern', ['name' => 'Acme Realty', 'area' => 'Houston', 'state' => 'TX', 'phone' => '(713) 555-0100',
        'email' => 'hi@acme.test', 'street' => '1 Main St', 'city' => 'Houston', 'zip' => '77002'], $this->owner);
    $this->site->update(['status' => 'live']);
});

it('uses the logo uploaded in the brand kit on the website too, trimmed of empty margins', function (): void {
    $img = imagecreatetruecolor(900, 400);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    imagefilledrectangle($img, 150, 100, 749, 299, imagecolorallocate($img, 20, 60, 200));
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($img, $path);

    $this->actingAs($this->owner)->post('/studio/brand', ['for' => 'brokerage', 'logo' => new UploadedFile($path, 'logo.png', 'image/png', null, true)])->assertRedirect();

    $logo = (string) $this->site->fresh()->brand['logo_url'];
    expect($logo)->toContain('/logo-');
    $stored = Storage::disk('public')->get((string) DB::connection(Connections::cell())->table('studio_brand_kits')->whereNull('user_id')->value('logo_path'));
    [$w, $h] = getimagesizefromstring((string) $stored) ?: [0, 0];
    expect([$w, $h])->toBe([600, 200]);
});

it('turns away a logo that is too small to stay sharp', function (): void {
    $this->actingAs($this->owner)->post('/studio/brand', ['for' => 'brokerage', 'logo' => UploadedFile::fake()->image('tiny.png', 120, 40)])
        ->assertSessionHasErrors('logo');
});

it('shows a full contact page: call, email, visit with directions, office hours and the form', function (): void {
    $html = (string) $this->get('http://acme.rightally.site/contact')->assertOk()->getContent();
    expect($html)->toContain('Call or text')->toContain('href="tel:7135550100"')->toContain('Visit the office')->toContain('Get directions')
        ->toContain('Office hours')->toContain('Send us a message');
});

it('shows the areas served as photo tiles, adding the cities of every MLS board state', function (): void {
    $board = DB::connection(Connections::control())->table('idx_boards')->where('state', 'FL')->value('id');
    DB::connection(Connections::control())->table('idx_subscriptions')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $this->tenant->id, 'board_id' => $board,
        'status' => 'demo', 'reminders' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $html = (string) $this->get('http://acme.rightally.site/')->assertOk()->getContent();
    expect($html)->toContain('class="area-tiles"')->toContain('Houston, TX')->toContain('Miami, FL')->toContain('Areas we serve');
});

it('lists "Agent login" with the footer resources and credits RightAlly in the assistant', function (): void {
    $html = (string) $this->get('http://acme.rightally.site/')->assertOk()->getContent();
    expect($html)->toContain('<li><a href="/backoffice" rel="nofollow">Agent login</a></li>')
        ->toContain('class="ailly-powered"')->toContain('>RightAlly</a>');
});
