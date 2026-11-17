<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Billing\Credits\CreditLedger;
use Modules\ListIq\Descriptions;
use Modules\ListIq\Models\Photo;
use Modules\ListIq\Models\Prep;
use Modules\ListIq\Preps;
use Modules\ListIq\Staging\VirtualStager;
use Tests\Fixtures\FakeAiProvider;

/* LI1: List-IQ — checklist, guided shoot, photo order, AI descriptions with Fair Housing checks, labelled staging, MLS package. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    Storage::fake('public');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann']);
    $this->bob = User::factory()->create();
});

function listiqJpeg(int $w = 1200, int $h = 800): UploadedFile
{
    return UploadedFile::fake()->image('p.jpg', $w, $h);
}

final class ListiqFakeStager implements VirtualStager
{
    public int $calls = 0;

    public bool $works = true;

    public function ready(): bool
    {
        return true;
    }

    public function stage(string $publicUrl, string $room, string $style): ?string
    {
        $this->calls++;
        if (! $this->works) {
            return null;
        }
        $img = imagecreatetruecolor(1200, 800);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 230, 230, 230));
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }
}

it('makes a listing ready with a checklist, a guided shoot and photos in the order buyers like', function (): void {
    $this->actingAs($this->ann)->post('/list-iq', ['street' => '12 Oak St', 'city' => 'Austin', 'zip' => '78704', 'beds' => 3, 'sqft' => 1800])->assertRedirect();
    $prep = Prep::query()->sole();
    $this->actingAs($this->bob)->get("/list-iq/{$prep->ulid}")->assertForbidden();

    $this->actingAs($this->ann)->post("/list-iq/{$prep->ulid}/photos", ['room' => 'kitchen', 'photos' => [listiqJpeg(), listiqJpeg()]])->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->post("/list-iq/{$prep->ulid}/photos", ['room' => 'front', 'photos' => [listiqJpeg(3000, 2000)]])->assertSessionHasNoErrors();
    $photos = Photo::query()->orderBy('position')->get();
    expect($photos->pluck('room')->all())->toBe(['front', 'kitchen', 'kitchen'])->and($photos[0]->caption)->toBe('Front of the home')
        ->and(getimagesizefromstring((string) Storage::disk('public')->get((string) $photos[0]->path))[0])->toBe(2400); // resized for the MLS

    $list = collect(app(Preps::class)->checklist($prep->fresh() ?? $prep))->keyBy('key');
    expect($list['front']['done'])->toBeTrue()->and($list['photos']['done'])->toBeFalse()->and($list['photos']['hint'])->toBe('3 so far.');
    $this->actingAs($this->ann)->patch("/list-iq/{$prep->ulid}", ['showing' => 'Lockbox on the front door.', 'manual' => ['disclosures' => true]]);
    $this->actingAs($this->ann)->get("/list-iq/{$prep->ulid}")->assertInertia(fn (Assert $p) => $p->component('listiq/show')
        ->where('checklist', fn ($c): bool => collect($c)->firstWhere('key', 'showing')['done'] && collect($c)->firstWhere('key', 'disclosures')['done'])->has('photos', 3));
});

it('writes three descriptions within the MLS limit, from the notes only, and flags Fair Housing wording', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => Descriptions::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.listing_description']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    $prep = app(Preps::class)->create($this->ann, ['street' => '12 Oak St', 'beds' => 3]);
    $this->actingAs($this->ann)->post("/list-iq/{$prep->ulid}/describe")->assertSessionHasErrors('notes');

    $prep->forceFill(['notes' => 'New quartz kitchen, big shady backyard.', 'remarks_limit' => 250])->save();
    $long = str_repeat('Bright and open with a new quartz kitchen. ', 10);
    $ai->respond(json_encode(['warm' => 'A warm welcome to a home with a new quartz kitchen.  And a big shady backyard.', 'crisp' => $long, 'luxury' => 'Refined living.']));
    $this->actingAs($this->ann)->post("/list-iq/{$prep->ulid}/describe")->assertSessionHasNoErrors();
    $d = collect($prep->fresh()?->descriptions)->keyBy('style');
    expect($d['warm']['text'])->toBe('A warm welcome to a home with a new quartz kitchen. And a big shady backyard.')->and(mb_strlen($d['crisp']['text']))->toBeLessThanOrEqual(250)
        ->and($d['crisp']['text'])->toEndWith('kitchen.')
        ->and($ai->requests[0]->userMessage)->toContain('New quartz kitchen');

    $this->actingAs($this->ann)->patch("/list-iq/{$prep->ulid}", ['description' => str_repeat('x', 251)])->assertSessionHasErrors('description');
    $this->actingAs($this->ann)->patch("/list-iq/{$prep->ulid}", ['description' => 'Family-friendly home in a safe neighborhood.']);
    expect(Descriptions::check('Family-friendly home in a safe neighborhood.'))->toHaveCount(2)
        ->and(collect(app(Preps::class)->checklist($prep->fresh() ?? $prep))->firstWhere('key', 'fair_housing')['done'])->toBeFalse();
});

it('stages a photo for credits, labels it in the image, keeps the original, refunds when it fails, and packages it all', function (): void {
    app()->instance(VirtualStager::class, $stager = new ListiqFakeStager);
    $prep = app(Preps::class)->create($this->ann, ['street' => '12 Oak St']);
    $this->actingAs($this->ann)->post("/list-iq/{$prep->ulid}/photos", ['room' => 'living', 'photos' => [listiqJpeg()]]);
    $original = Photo::query()->sole();
    app(CreditLedger::class)->add($this->ann->id, 100, 'adjust', 'Test credits', $this->owner);
    $start = app(CreditLedger::class)->balanceOf($this->ann->id);
    $this->actingAs($this->ann)->post("/list-iq/{$prep->ulid}/photos/{$original->ulid}/stage", ['style' => 'modern'])->assertSessionHasNoErrors();
    $staged = Photo::query()->where('kind', 'staged')->sole();
    expect($staged->status)->toBe('ready')->and($staged->caption)->toBe('Living room (Virtually staged)')->and($staged->parent_id)->toBe($original->id)
        ->and($original->fresh()?->use)->toBeTrue() // the original stays in the set
        ->and(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe($start - 10);
    $img = imagecreatefromstring((string) Storage::disk('public')->get((string) $staged->path));
    expect(imagecolorat($img, 30, 780) !== imagecolorat($img, 600, 400))->toBeTrue(); // the label band is drawn

    $stager->works = false;
    $this->actingAs($this->ann)->post("/list-iq/{$prep->ulid}/photos/{$original->ulid}/stage", ['style' => 'coastal']);
    expect(Photo::query()->where('kind', 'staged')->where('status', 'failed')->count())->toBe(1)->and(app(CreditLedger::class)->balanceOf($this->ann->id))->toBe($start - 10); // refunded

    $prep->forceFill(['description' => 'Bright home.'])->save();
    $zip = $this->actingAs($this->ann)->get("/list-iq/{$prep->ulid}/package")->assertOk()->assertDownload('listing-12-oak-st.zip');
    $file = $zip->baseResponse->getFile()->getPathname();
    $z = new ZipArchive;
    $z->open($file);
    $names = array_map(fn (int $i): string => (string) $z->getNameIndex($i), range(0, $z->numFiles - 1));
    expect($names)->toContain('photos/01-living.jpg', 'photos/02-living-virtually-staged.jpg', 'description.txt', 'captions.csv')
        ->and((string) $z->getFromName('staging-disclosure.txt'))->toContain('Photo 02 (02-living-virtually-staged.jpg) is virtually staged');
});
