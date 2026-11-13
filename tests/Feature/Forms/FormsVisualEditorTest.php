<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Modules\Forms\Sealer;
use Modules\Forms\Templates;

beforeEach(function (): void {
    Storage::fake('local');
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
});

function letterPdf(int $pages = 1): UploadedFile
{
    $pdf = new FPDF('P', 'pt', 'Letter');
    for ($i = 1; $i <= $pages; $i++) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Text(72, 60, "PAGE {$i}");
    }

    return UploadedFile::fake()->createWithContent('form.pdf', $pdf->Output('S'));
}

it('renders each page as an image where one pixel is one PDF point, cached, for the brokerage only', function (): void {
    $ulid = app(Templates::class)->upload('Purchase agreement', letterPdf(2), null, $this->owner);

    $png = (string) $this->actingAs($this->owner)->get("/forms/templates/{$ulid}/pages/2")->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
    [$width, $height] = (array) getimagesizefromstring($png);
    expect([$width, $height])->toBe([612, 792])
        ->and(Storage::disk('local')->allFiles('forms/'.$this->tenant->id.'/pages'))->toHaveCount(1);

    $this->actingAs($this->owner)->get("/forms/templates/{$ulid}/pages/3")->assertNotFound();
    $this->actingAs($this->agent)->get("/forms/templates/{$ulid}/pages/1")->assertForbidden();
});

it('falls back to the coordinate table when the page renderer is unavailable', function (): void {
    config(['forms.pdftoppm' => '/nonexistent/pdftoppm']);
    $ulid = app(Templates::class)->upload('Addendum', letterPdf(), null, $this->owner);

    $this->actingAs($this->owner)->get("/forms/templates/{$ulid}/pages/1")->assertNotFound();
});

it('keeps past seals verifiable after the sealing key is rotated', function (): void {
    $old = Sealer::generate();
    config(['forms.seal_seed' => $old['seed']]);
    $sha = hash('sha256', 'signed document');
    $seal = app(Sealer::class)->sign($sha);
    expect(app(Sealer::class)->dedicated())->toBeTrue()->and(app(Sealer::class)->valid($sha, $seal))->toBeTrue();

    config(['forms.seal_seed' => Sealer::generate()['seed']]);
    expect(app(Sealer::class)->valid($sha, $seal))->toBeFalse();

    config(['forms.seal_previous_public_keys' => 'not-a-key,'.$old['public_key']]);
    expect(app(Sealer::class)->valid($sha, $seal))->toBeTrue()
        ->and(app(Sealer::class)->valid(hash('sha256', 'altered document'), $seal))->toBeFalse();
});

it('generates a dedicated sealing key and warns before replacing one', function (): void {
    config(['forms.seal_seed' => '']);
    expect(app(Sealer::class)->dedicated())->toBeFalse();
    Artisan::call('forms:seal-key');
    expect(Artisan::output())->toContain('FORMS_SEAL_SEED=')->not->toContain('already set');

    config(['forms.seal_seed' => Sealer::generate()['seed']]);
    Artisan::call('forms:seal-key');
    expect(Artisan::output())->toContain('FORMS_SEAL_PREVIOUS_PUBLIC_KEYS');
});
