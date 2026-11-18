<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\AiGateway;
use Modules\AI\Brand\BrandVoice;
use Modules\AI\Data\AiTask;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Core\Support\Connections;
use Tests\Fixtures\FakeAiProvider;

/*
| BV1 (specs/core/08): Brand Voice — the brand guide read by AI and approved by the owner; every people-facing piece of
| AI writing follows the voice and is checked against it; the consistency report; colours and fonts into Studio.
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    Notification::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['name' => 'Hill Country Realty'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach (['network.intro', 'network.match', 'ai.brand_guide'] as $f) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => $f === 'ai.brand_guide' ? 'ai.brand_guide' : 'ai.network_intro']);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
});

/** A one-page PDF with real text, so pdftotext can read it. */
function textPdf(string $text): UploadedFile
{
    $stream = 'BT /F1 11 Tf 40 760 Td 14 TL '.implode(' ', array_map(fn (string $l): string => '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l).') Tj T*', explode("\n", $text))).' ET';
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n".implode('', array_map(fn (int $o): string => sprintf("%010d 00000 n \n", $o), $offsets));
    $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    $path = tempnam(sys_get_temp_dir(), 'guide').'.pdf';
    file_put_contents($path, $pdf);

    return new UploadedFile($path, 'brand-guide.pdf', 'application/pdf', null, true);
}

function approveVoice(object $t, array $over = []): void
{
    $t->actingAs($t->owner)->post('/settings/brand-voice', $over + ['tone' => 'warm', 'use_words' => ['neighbours'], 'avoid_words' => ['cheap', 'deal of a lifetime'],
        'emojis' => false, 'sign_off' => 'Your neighbours at Hill Country Realty', 'spanish' => 'usted', 'name_brokerage' => true])->assertSessionHasNoErrors();
}

it('reads the brand guide with AI, and nothing is used until the owner approves it', function (): void {
    $this->ai->respond((string) json_encode(['tone' => 'warm', 'use_words' => ['home', 'neighbours'], 'avoid_words' => ['cheap'], 'emojis' => false,
        'sign_off' => 'Your neighbours at Hill Country Realty', 'colors' => ['#1F4D3A', '#E8DCC4', 'not-a-colour'], 'fonts' => ['Playfair Display', 'Inter'],
        'taglines' => ['Rooted here'], 'disclaimer' => 'Equal Housing Opportunity']));
    $this->actingAs($this->owner)->post('/settings/brand-voice/guide', ['guide' => textPdf("Hill Country Realty brand guide\nOur voice is warm and plain. Never say cheap.\nColours: #1F4D3A and #E8DCC4")])
        ->assertSessionHasNoErrors();
    expect($this->ai->requests[0]->userMessage)->toContain('Never say cheap');
    expect(app(BrandVoice::class)->active())->toBeNull(); // not until approved

    $this->actingAs($this->owner)->get('/settings/brand-voice')->assertInertia(fn (Assert $p) => $p->component('settings/brand-voice')
        ->where('guide.colors', ['#1F4D3A', '#E8DCC4'])->where('guide.avoid_words', ['cheap'])->where('voice.approved_at', null));

    approveVoice($this, ['colors' => ['#1F4D3A', '#E8DCC4'], 'fonts' => ['Playfair Display', 'Inter'], 'disclaimer' => 'Equal Housing Opportunity', 'apply_kit' => true]);
    $kit = DB::connection(Connections::cell())->table('studio_brand_kits')->whereNull('user_id')->sole();
    expect(json_decode((string) $kit->colors, true)['primary'])->toBe('#1F4D3A')->and(json_decode((string) $kit->fonts, true)['body'])->toBe('Inter')
        ->and(json_decode((string) $kit->details, true)['disclaimer'])->toBe('Equal Housing Opportunity');

    $this->actingAs($this->agent)->get('/settings/brand-voice')->assertForbidden();
    $this->actingAs($this->owner)->post('/settings/brand-voice/guide', ['guide' => UploadedFile::fake()->create('guide.docx', 10)])->assertSessionHasErrors('guide');
});

it('gives people-facing AI writing the brand voice, checks it, and removes emojis when the brand says none', function (): void {
    approveVoice($this);
    $this->ai->respond((string) json_encode(['message' => 'Hi Rosa, a cheap question 😊 Your neighbours at Hill Country Realty'], JSON_UNESCAPED_UNICODE));
    app(AiGateway::class)->run(new AiTask('network.intro', 'network.intro', 'bv-1', inputs: ['writer' => 'Ann', 'brokerage' => 'Hill Country Realty', 'client' => 'buying in Austin']));
    expect($this->ai->requests[0]->system)->toContain('<brand_voice>')->toContain('Never use these words: cheap, deal of a lifetime')
        ->toContain('"usted"')->toContain('No emojis');

    // Prompts that are not people-facing writing do not get the voice.
    $this->ai->respond((string) json_encode(['unclear' => []]));
    app(AiGateway::class)->run(new AiTask('network.match', 'network.match', 'bv-2', untrustedContent: ['a buyer in Austin']));
    expect($this->ai->requests[1]->system)->not->toContain('<brand_voice>');

    $check = DB::connection(Connections::cell())->table('ai_brand_checks')->sole();
    expect((bool) $check->passed)->toBeFalse()->and(json_decode((string) $check->issues, true))->toBe(['Used "cheap"', 'Had emojis']);
    expect(app(BrandVoice::class)->tidy('Hello 😊 there'))->toBe('Hello  there');

    $this->actingAs($this->owner)->get('/settings/brand-voice')->assertInertia(fn (Assert $p) => $p->where('report.checked', 1)->where('report.percent', 0)
        ->where('report.features.0.feature', 'network.intro'));
});
