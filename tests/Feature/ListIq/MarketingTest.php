<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\PlanEnrollment;
use Modules\ListIq\Marketing;
use Modules\ListIq\Models\OpenHouse;
use Modules\ListIq\OpenHouseSummaryMail;
use Modules\ListIq\Preps;
use Modules\Sites\SiteSetup;

/* LI2: the listing marketing centre — listing page after the MLS, QR codes, open-house kiosk, floor plan, flyer, activity. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC'));
    Storage::fake('public');
    Mail::fake();
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $sites = app(SiteSetup::class);
    $sites->publish($sites->create('aurum', [], $this->owner), true, $this->owner);
    $this->web = 'http://acme.rightally.site';
    $this->prep = app(Preps::class)->create($this->ann, ['street' => '12 Oak St', 'city' => 'Austin', 'zip' => '78704', 'beds' => 3, 'sqft' => 1800, 'price' => 450000]);
    $this->prep->forceFill(['description' => 'Bright home with a new kitchen.'])->save();
});

it('keeps the listing page and QR codes private until it is in the MLS, then counts scans and tags leads', function (): void {
    $code = app(Marketing::class)->addCode($this->prep, 'sign', 'Front yard');
    $this->get($this->web.'/listing/'.$this->prep->ulid)->assertNotFound();
    $this->get($this->web.'/q/'.$code)->assertNotFound();

    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/mls", ['on' => true]);
    $this->get($this->web.'/q/'.$code)->assertRedirect($this->web.'/listing/'.$this->prep->ulid.'?src='.$code);
    $html = (string) $this->get($this->web.'/listing/'.$this->prep->ulid.'?src='.$code)->assertOk()->getContent();
    expect($html)->toContain('12 Oak St')->toContain('$450,000')->toContain('"@type":"RealEstateListing"')->toContain('value="'.$code.'"');

    $this->post($this->web.'/_listing/'.$this->prep->ulid, ['name' => 'Bea Buyer', 'email' => 'bea@x.test', 'src' => $code, 't' => Crypt::encryptString((string) (time() - 30))])
        ->assertRedirect($this->web.'/listing/'.$this->prep->ulid.'?sent=1#ask');
    $lead = Lead::query()->sole();
    expect($lead->assigned_to ?? Contact::query()->find($lead->contact_id)?->owner_user_id)->toBe($this->ann->id)
        ->and(app(Marketing::class)->codes($this->prep)[0])->toMatchArray(['scans' => 1, 'leads' => 1, 'url' => 'https://acme.rightally.site/q/'.$code])
        ->and(app(Marketing::class)->activity($this->prep->fresh() ?? $this->prep))->toMatchArray(['views' => 1, 'scans' => 1, 'code_leads' => 1]);
    $this->actingAs($this->ann)->get("/list-iq/{$this->prep->ulid}/codes/{$code}")->assertOk()->assertHeader('content-type', 'image/png');
});

it('runs an open-house kiosk: consented sign-ins become leads in the follow-up plan, and the agent gets the list', function (): void {
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/open-houses", ['date' => '2026-09-06', 'from' => '13:00', 'to' => '12:00'])->assertSessionHasErrors('ends_at');
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/open-houses", ['date' => '2026-09-06', 'from' => '13:00', 'to' => '16:00'])->assertSessionHasNoErrors();
    $oh = OpenHouse::query()->sole();
    $this->actingAs($this->ann)->get("/list-iq/{$this->prep->ulid}/open-houses/{$oh->ulid}")->assertInertia(fn (Assert $p) => $p->component('listiq/kiosk'));

    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/open-houses/{$oh->ulid}/sign-in", ['name' => 'Cal Visitor', 'phone' => '512-555-0177', 'agent' => 'no', 'sms_consent' => true])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/open-houses/{$oh->ulid}/sign-in", ['name' => 'Dee Agented', 'email' => 'dee@x.test', 'agent' => 'yes']);
    $cal = Contact::query()->where('primary_phone', '+15125550177')->sole();
    $dee = Contact::query()->where('primary_email', 'dee@x.test')->sole();
    expect($cal->owner_user_id)->toBe($this->ann->id)->and($cal->source->value)->toBe('open_house')
        ->and(PlanEnrollment::query()->where('contact_id', $cal->id)->exists())->toBeTrue()
        ->and(PlanEnrollment::query()->where('contact_id', $dee->id)->exists())->toBeFalse(); // has an agent

    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/open-houses/{$oh->ulid}/end")->assertRedirect();
    Mail::assertSent(OpenHouseSummaryMail::class, fn (OpenHouseSummaryMail $m): bool => $m->count === 2 && str_contains($m->rows, 'Dee Agented — has an agent'));
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/open-houses/{$oh->ulid}/sign-in", ['name' => 'Late', 'email' => 'late@x.test'])->assertSessionHasErrors('name');
});

it('adds a floor plan and makes a branded flyer with a QR code', function (): void {
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/floor-plan", ['floor_plan' => UploadedFile::fake()->create('plan.docx', 10)])->assertSessionHasErrors('floor_plan');
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/floor-plan", ['floor_plan' => UploadedFile::fake()->create('plan.pdf', 50, 'application/pdf')])->assertSessionHasNoErrors();
    expect($this->prep->fresh()?->floor_plan_path)->toEndWith('.pdf');
    $this->actingAs($this->ann)->get("/list-iq/{$this->prep->ulid}/flyer")->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(app(Marketing::class)->codes($this->prep)[0]['kind'])->toBe('flyer');
    $this->actingAs($this->ann)->get("/list-iq/{$this->prep->ulid}")->assertInertia(fn (Assert $p) => $p->where('marketing.in_mls', false)->has('marketing.codes', 1));

    // MD1: the tour and the listing video play on the public page.
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/tour", ['url' => 'https://kuula.co/share/7abcD']);
    $this->prep->forceFill(['video_status' => 'ready', 'video_path' => 'listiq/v.mp4', 'video_poster_path' => 'listiq/v.jpg'])->save();
    $this->actingAs($this->ann)->post("/list-iq/{$this->prep->ulid}/mls", ['on' => true]);
    $html = (string) $this->get($this->web.'/listing/'.$this->prep->ulid)->assertOk()->getContent();
    expect($html)->toContain('src="https://kuula.co/share/7abcD?fs=1&amp;vr=0&amp;thumbs=1&amp;info=0&amp;logo=-1"')->toContain('Kuula tour of 12 Oak St')
        ->toContain('listiq/v.mp4')->toContain('listiq/v.vtt');
});
