<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Ads\Studio\Render\FakeRenderer;
use Modules\Ads\Studio\Render\StudioRenderer;
use Modules\Ads\Studio\Video\FakeVideoMaker;
use Modules\Ads\Studio\Video\VideoMaker;
use Modules\Billing\Credits\CreditLedger;
use Modules\Core\Support\Connections;
use Modules\ListIq\ListingVideo;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Preps;
use Modules\ListIq\Staging\ReplicateStager;
use Modules\ListIq\Staging\ServiceStager;
use Modules\ListIq\Tours;

/*
| MD1 (specs/listings/02, 03): virtual staging through the provider chosen in Outside services (Collov, REimagineHome,
| Virtual Staging AI), 3D and 360-degree tours on the listing, and a listing video from the List-IQ photos.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    Storage::fake('public');
    Sleep::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent', 'phone' => '+15125550111']);
    $this->prep = app(Preps::class)->create($this->ann, ['street' => '12 Oak St', 'city' => 'Austin', 'beds' => 3, 'price' => 525000]);
});

function mdService(string $service, string $provider, array $keys): void
{
    DB::connection(Connections::control())->table('outside_services')->insert(['tenant_id' => 0, 'service' => $service, 'provider' => $provider, 'mode' => 'live',
        'test_keys' => Crypt::encryptString((string) json_encode($keys)), 'live_keys' => Crypt::encryptString((string) json_encode($keys)), 'test_contacts' => '',
        'created_at' => now(), 'updated_at' => now()]);
}

function mdJpeg(): string
{
    $img = imagecreatetruecolor(1200, 800);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 190, 180));
    ob_start();
    imagejpeg($img);

    return (string) ob_get_clean();
}

it('stages with Collov when HQ connects it, and labels the result', function (): void {
    mdService('staging', 'collov', ['api_key' => 'collov-key-1234567890']);
    Http::fake([
        'api.collov.ai/flair/enterpriseApi/vst/generateImgOnCommon' => Http::response(['success' => true, 'data' => ['uuid' => 'u-1']]),
        'api.collov.ai/flair/enterpriseApi/vst/getRecord*' => Http::sequence()->push(['data' => ['status' => 'PENDING']])
            ->push(['data' => ['status' => 'SUCCESS', 'aiGenerateRecord' => ['generateUrl' => 'https://cdn.collov.example/staged.jpg']]]),
        'cdn.collov.example/*' => Http::response(mdJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    app(CreditLedger::class)->add($this->ann->id, 100, 'adjust', 'Test credits', $this->owner);
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/photos", ['room' => 'living', 'photos' => [UploadedFile::fake()->image('p.jpg', 1200, 800)]]);
    $original = Photo::query()->sole();
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/photos/{$original->ulid}/stage", ['style' => 'minimal'])->assertSessionHasNoErrors();

    $staged = Photo::query()->where('kind', 'staged')->sole();
    expect($staged->status)->toBe('ready')->and($staged->caption)->toBe('Living room (Virtually staged)');
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'generateImgOnCommon') && $r->hasHeader('apiKey', 'collov-key-1234567890')
        && collect($r->data())->contains(fn ($part) => ($part['name'] ?? '') === 'roomType' && $part['contents'] === 'living room')
        && collect($r->data())->contains(fn ($part) => ($part['name'] ?? '') === 'style' && $part['contents'] === 'scandinavian'));
});

it('stages with Virtual Staging AI and REimagineHome too, and falls back to the Replicate app when the service is off', function (): void {
    expect((new ServiceStager(new ReplicateStager))->ready())->toBeFalse();

    mdService('staging', 'virtualstagingai', ['api_key' => 'vsai-key-1234567890']);
    Http::fake([
        'api.virtualstagingai.app/v2/renders' => Http::response(['id' => 'r1'], 201),
        'api.virtualstagingai.app/v2/renders/r1*' => Http::response(['variations' => ['items' => [['status' => 'done', 'result' => ['url' => 'https://cdn.vsai.example/1.jpg']]]]]),
        'cdn.vsai.example/*' => Http::response(mdJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $stager = new ServiceStager(new ReplicateStager);
    expect($stager->ready())->toBeTrue()->and($stager->stage('https://x.example/p.jpg', 'primary_bed', 'farmhouse'))->not->toBeNull();
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.virtualstagingai.app/v2/renders' && $r['config']['add_furniture'] === ['room_type' => 'bed', 'style' => 'farmhouse']
        && $r->hasHeader('Authorization', 'Api-Key vsai-key-1234567890'));

    DB::connection(Connections::control())->table('outside_services')->update(['provider' => 'reimaginehome']);
    Http::fake([
        'api.reimaginehome.ai/v1/create_mask' => Http::response(['data' => ['job_id' => 'm1']]),
        'api.reimaginehome.ai/v1/create_mask/m1' => Http::response(['data' => ['job_status' => 'done', 'masks' => [['url' => 'https://m.example/f.png', 'category' => 'furnishing'],
            ['url' => 'https://m.example/w.png', 'category' => 'architectural']]]]),
        'api.reimaginehome.ai/v1/generate_image' => Http::response(['data' => ['job_id' => 'g1']]),
        'api.reimaginehome.ai/v1/generate_image/g1' => Http::response(['data' => ['job_status' => 'done', 'generated_images' => ['https://cdn.rh.example/1.jpg']]]),
        'cdn.rh.example/*' => Http::response(mdJpeg(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    expect((new ServiceStager(new ReplicateStager))->stage('https://x.example/p.jpg', 'kitchen', 'coastal'))->not->toBeNull();
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.reimaginehome.ai/v1/generate_image' && $r['mask_urls'] === ['https://m.example/f.png']
        && $r['space_type'] === 'ST-INT-009' && $r['design_theme'] === 'DT-INT-002');
});

it('reads tour links from the accepted sites only, shows the tour on the listing page and puts it in the MLS package', function (): void {
    expect(Tours::read('https://my.matterport.com/show/?m=SxQL3iGyoDo')['embed'])->toBe('https://my.matterport.com/show/?m=SxQL3iGyoDo&play=1&brand=0')
        ->and(Tours::read('https://youtu.be/dQw4w9WgXcQ')['embed'])->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and(Tours::read('https://youriguide.com/12_oak_st_austin_tx/')['link'])->toBe('https://unbranded.youriguide.com/12_oak_st_austin_tx/')
        ->and(Tours::read('https://www.cubi.casa/plans/abc')['embed'])->toBeNull()
        ->and(Tours::read('https://evil.example/show/?m=SxQL3iGyoDo'))->toBeNull()
        ->and(Tours::read('http://my.matterport.com/show/?m=SxQL3iGyoDo'))->toBeNull();

    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/tour", ['url' => 'https://example.com/tour'])->assertSessionHasErrors('tour');
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/tour", ['url' => 'https://my.matterport.com/show/?m=SxQL3iGyoDo'])->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->get("/list-iq/{$this->prep->ulid}")->assertInertia(fn (Assert $p) => $p->where('marketing.tour.provider', 'Matterport')
        ->where('marketing.tour.embed', 'https://my.matterport.com/show/?m=SxQL3iGyoDo&play=1&brand=0')->where('marketing.matterport', null));

    $file = app(Preps::class)->package($this->prep->fresh());
    $zip = new ZipArchive;
    $zip->open($file);
    expect((string) $zip->getFromName('virtual-tour.txt'))->toBe("https://my.matterport.com/show/?m=SxQL3iGyoDo&brand=0\n");
});

it('lists the Matterport spaces of the connected account to pick from', function (): void {
    mdService('floor_plans', 'matterport', ['token_id' => 'tid', 'token_secret' => 'tsecret']);
    Http::fake(['api.matterport.com/api/models/graph' => Http::response(['data' => ['models' => ['results' => [['id' => 'AbCdEfGhIjK', 'name' => '12 Oak St']]]]])]);
    $this->actingAs($this->ann)->get("/list-iq/{$this->prep->ulid}")->assertInertia(fn (Assert $p) => $p->where('marketing.matterport.0', ['id' => 'AbCdEfGhIjK', 'name' => '12 Oak St']));
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/tour", ['matterport' => 'AbCdEfGhIjK'])->assertSessionHasNoErrors();
    expect($this->prep->fresh()->tour_provider)->toBe('matterport');
    Http::assertSent(fn (HttpRequest $r): bool => $r->hasHeader('Authorization', 'Basic '.base64_encode('tid:tsecret')));
});

it('makes a vertical listing video from the photos for credits, and refunds when it fails', function (): void {
    app()->instance(StudioRenderer::class, $renderer = new FakeRenderer);
    app()->instance(VideoMaker::class, $maker = new FakeVideoMaker);
    app(CreditLedger::class)->add($this->ann->id, 100, 'adjust', 'Test credits', $this->owner);
    $start = app(CreditLedger::class)->balanceOf($this->ann->id);
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/video")->assertSessionHasErrors('video'); // not enough photos

    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/photos", ['room' => 'kitchen', 'photos' => array_map(fn () => UploadedFile::fake()->image('p.jpg', 1200, 800), range(1, 6))]);
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/video")->assertSessionHasNoErrors();
    $prep = $this->prep->fresh();
    expect($prep->video_status)->toBe('ready')->and(Storage::disk('public')->exists((string) $prep->video_path))->toBeTrue()
        ->and($maker->made[0]['frames'])->toBe(8)->and($maker->made[0]['size'])->toBe('1080x1920') // cover, 6 photos, ending
        ->and($renderer->pages[0])->toContain('Coming soon')->toContain('$525,000')->and(end($renderer->pages))->toContain('Ann Agent')
        ->and(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe($start - 5);
    $vtt = (string) Storage::disk('public')->get(ListingVideo::captionsPath((string) $prep->video_path));
    expect($vtt)->toStartWith('WEBVTT')->toContain("1\n00:00:00.000 --> 00:00:03.400\nComing soon: 12 Oak St, Austin. \$525,000")->toContain('Ask me about this home: Ann Agent');

    $maker->fail = true;
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/video")->assertSessionHasNoErrors();
    expect($this->prep->fresh()->video_status)->toBe('failed')->and(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe($start - 5); // refunded
});
