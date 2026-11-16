<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Studio\Canva\CanvaSession;
use Modules\Ads\Studio\Design;
use Modules\Ads\Studio\StudioPlans;
use Modules\Billing\Credits\CreditLedger;
use Modules\Connect\AppRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Connect\AppCatalog;
use Modules\Core\Support\Connections;

/* Sprint C7d-3: Canva — open a Studio design or a listing in Canva with the brand kit and photos, come back through
   Canva's one signed return address, and get the finished pages as a Studio design to post, advertise or download. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    Storage::fake('public');
    Sleep::fake();
    config(['listings.cdn_url' => 'https://cdn.acme.test']);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->jane = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Agent']);
    $hq = User::factory()->make(['id' => 999999]);
    DB::connection(Connections::control())->table('credit_settings')->insert(['credit_value_microcents' => 1_000_000, 'enforce' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(CreditLedger::class)->savePolicy(false, null, $this->owner);
    app(CreditLedger::class)->add($this->jane->id, 10_000, 'adjust', 'Test credits', $this->owner);
    $app = app(AppCatalog::class)->find('studio_essentials');
    app(AppRegistry::class)->save($app, true, [], null, $hq);
    app(AppRegistry::class)->savePrice($app, 3900, [], $hq);
    app(StudioPlans::class)->savePackage('studio_essentials', ['posts' => 30], $hq);
    $this->actingAs($this->jane)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post('/apps/studio_essentials/addon')->assertSessionHasNoErrors();
    $this->flushSession();
    app(AppRegistry::class)->save(app(AppCatalog::class)->find('canva'), true, ['client_id' => 'canva-client', 'client_secret' => 'cs'], null, $hq);
    Connection::query()->create(['app_key' => 'canva', 'owner_user_id' => $this->jane->id, 'label' => 'Canva', 'status' => 'connected', 'connected_by' => $this->jane->id,
        'credentials' => ['access_token' => 'canva-tok', 'refresh_token' => 'r', 'expires_at' => (string) (time() + 3000)], 'settings' => []]);
    $png = function (int $w, int $h): string {
        ob_start();
        imagepng(imagecreatetruecolor($w, $h));

        return (string) ob_get_clean();
    };
    $this->png = $png;
    Storage::disk('public')->put('studio/t/d-portrait.png', $png(1080, 1350));
    $this->design = Design::query()->create(['user_id' => $this->jane->id, 'batch' => 'b1', 'moment' => 'tip', 'listing_ulid' => null, 'collection' => 'noir',
        'variant' => 'noir', 'content' => ['headline' => 'Price it right'], 'status' => 'ready']);
    $this->design->forceFill(['files' => ['portrait' => 'studio/t/d-portrait.png', 'square' => 'studio/t/d-square.png', 'story' => 'studio/t/d-story.png']])->save();

    // Canva's signing key, published at its keys address.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $this->private);
    $d = openssl_pkey_get_details($key)['rsa'];
    $b64 = fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $this->jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($d['n']), 'e' => $b64($d['e'])]]];
    Cache::forget('canva:jwks');
});

function canvaFakes(object $t, int $pages = 2): void
{
    Http::fake([
        'api.canva.com/rest/v1/connect/keys' => Http::response($t->jwks),
        'api.canva.com/rest/v1/asset-uploads' => Http::response(['job' => ['id' => 'up1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/asset-uploads/up1' => Http::response(['job' => ['id' => 'up1', 'status' => 'success', 'asset' => ['id' => 'asset-1']]]),
        'api.canva.com/rest/v1/designs' => Http::response(['design' => ['id' => 'DAF1', 'urls' => ['edit_url' => 'https://www.canva.com/api/design/tok/edit', 'view_url' => 'https://x']]]),
        'api.canva.com/rest/v1/exports' => Http::response(['job' => ['id' => 'ex1', 'status' => 'in_progress']]),
        'api.canva.com/rest/v1/exports/ex1' => Http::response(['job' => ['id' => 'ex1', 'status' => 'success',
            'urls' => array_map(fn (int $i): string => 'https://export.canva.test/p'.$i.'.png', range(1, $pages))]]),
        'export.canva.test/*' => Http::response(($t->png)(1080, 1350)),
    ]);
}

function canvaJwt(object $t, string $state, string $aud = 'canva-client'): string
{
    return JWT::encode(['aud' => $aud, 'exp' => time() + 3600, 'sub' => 'u1', 'team_id' => 't1', 'type' => 'rti', 'jti' => 'j1', 'design_id' => 'DAF1',
        'correlation_state' => $state], $t->private, 'RS256', 'k1');
}

it('opens a Studio design in Canva with the brand and photos, and brings the finished pages back as a carousel design', function (): void {
    canvaFakes($this);
    $this->actingAs($this->jane)->get('/studio/create')->assertInertia(fn (Assert $p) => $p->where('canva', true));
    $res = $this->actingAs($this->jane)->post('/studio/canva', ['design' => $this->design->ulid]);
    $s = CanvaSession::query()->sole();
    $res->assertRedirect('/studio/canva/'.$s->ulid);
    expect($s->only(['status', 'design_ref', 'source_design_id']))->toBe(['status' => 'editing', 'design_ref' => 'DAF1', 'source_design_id' => $this->design->id])
        ->and($s->edit_url)->toBe('https://www.canva.com/api/design/tok/edit?correlation_state='.strtoupper($s->ulid));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.canva.com/rest/v1/asset-uploads' && $r->header('Authorization')[0] === 'Bearer canva-tok'
        && json_decode($r->header('Asset-Upload-Metadata')[0], true)['name_base64'] === base64_encode('Studio design'));
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.canva.com/rest/v1/designs' && $r['design_type'] === ['type' => 'custom', 'width' => 1080, 'height' => 1350]
        && $r['asset_id'] === 'asset-1');
    $this->actingAs($this->jane)->get('/studio/canva/'.$s->ulid)->assertInertia(fn (Assert $p) => $p->component('studio/canva')
        ->where('session.status', 'editing')->where('session.edit_url', $s->edit_url));
    $this->actingAs($this->owner)->get('/studio/canva/'.$s->ulid)->assertNotFound(); // only hers

    // Canva sends her to the one return address with a signed token; she's sent back to her brokerage's website.
    $this->get('https://auth.rightally.app/connect/canva/return?correlation_jwt='.canvaJwt($this, 'NOTASESSION'))->assertStatus(400);
    $this->get('https://auth.rightally.app/connect/canva/return?correlation_jwt='.canvaJwt($this, strtoupper($s->ulid), 'someone-else'))->assertStatus(400);
    $back = (string) $this->get('https://auth.rightally.app/connect/canva/return?correlation_jwt='.canvaJwt($this, strtoupper($s->ulid)))->assertRedirect()->headers->get('Location');
    expect($back)->toStartWith('https://')->toEndWith('/studio/canva/'.strtoupper($s->ulid).'/back');

    $this->actingAs($this->jane)->get('/studio/canva/'.$s->ulid.'/back')->assertRedirect('/studio/canva/'.$s->ulid);
    $s->refresh();
    $d = Design::query()->whereKey($s->result_design_id)->sole();
    expect($s->status)->toBe('ready')->and($d->only(['variant', 'status', 'user_id']))->toBe(['variant' => 'canva', 'status' => 'ready', 'user_id' => $this->jane->id])
        ->and($d->files['slides'])->toHaveCount(1);
    foreach (['portrait', 'square', 'story'] as $size) {
        Storage::disk('public')->assertExists((string) $d->files[$size]);
    }
    expect(getimagesizefromstring((string) Storage::disk('public')->get((string) $d->files['story']))[1])->toBe(1920);
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.canva.com/rest/v1/exports' && $r['design_id'] === 'DAF1' && $r['format']['type'] === 'png');
    // A Canva design is posted and advertised like any other, but not redrawn as a Reel.
    $this->actingAs($this->jane)->get('/studio/create')->assertInertia(fn (Assert $p) => $p->where('recent.0.canva', true)->where('recent.0.reelable', false));
});

it('needs Canva connected and the person\'s own design or listing, and shows what went wrong', function (): void {
    Connection::query()->where('app_key', 'canva')->delete();
    $this->actingAs($this->jane)->post('/studio/canva', ['design' => $this->design->ulid])->assertSessionHasErrors('canva');
    Connection::query()->create(['app_key' => 'canva', 'owner_user_id' => $this->jane->id, 'label' => 'Canva', 'status' => 'connected', 'connected_by' => $this->jane->id,
        'credentials' => ['access_token' => 'canva-tok', 'expires_at' => (string) (time() + 3000)], 'settings' => []]);
    $this->actingAs($this->jane)->post('/studio/canva', ['listing' => '01JAAAAAAAAAAAAAAAAAAAAAAA'])->assertSessionHasErrors('canva');
    $bobs = Design::query()->create(['user_id' => $this->owner->id, 'batch' => 'b2', 'moment' => 'tip', 'collection' => 'noir', 'variant' => 'noir', 'content' => [], 'status' => 'ready']);
    $this->actingAs($this->jane)->post('/studio/canva', ['design' => $bobs->ulid])->assertSessionHasErrors('canva');

    Http::fake(['api.canva.com/rest/v1/asset-uploads' => Http::response(['message' => 'Quota exceeded'], 429)]);
    $this->actingAs($this->jane)->post('/studio/canva', ['design' => $this->design->ulid]);
    expect(CanvaSession::query()->sole()->only(['status', 'error']))->toBe(['status' => 'failed', 'error' => 'Canva: Quota exceeded']);
});
