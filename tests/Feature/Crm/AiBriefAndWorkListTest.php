<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Consent;
use Modules\Crm\ContactBriefs;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactBrief;
use Modules\Crm\Models\ContactImport;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->agent = User::factory()->create(['first_name' => 'Maria']);
    $this->other = User::factory()->create();
    $this->model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => ContactBriefs::FEATURE, 'primary_model_id' => $this->model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.crm_brief']);
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($this->model->provider->key, $this->ai);
    $this->pat = app(ManageContacts::class)->create(['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@x.test', 'phone' => '6015550100'], $this->agent);
});

function briefJson(array $overrides = []): string
{
    return json_encode($overrides + [
        'who' => 'First-time buyer relocating for a hospital job.', 'wants' => '3-bed in Fondren under $350k.',
        'status' => 'Actively searching; last contact 9 days ago.', 'unknowns' => ['Pre-approval'],
        'next_step' => 'Call to confirm pre-approval.', 'next_step_reason' => 'Timeline is tight.',
        'draft_channel' => 'text', 'draft' => 'Hi Pat, two new homes in Fondren under $350k. Saturday?', 'sources' => [],
    ]);
}

it('writes the brief from this contact\'s records only, and stores it encrypted', function (): void {
    app(Timeline::class)->log($this->pat, ['type' => 'note', 'body' => 'Relocating for the hospital, wants Fondren'], $this->agent);
    $bea = app(ManageContacts::class)->create(['first_name' => 'Bea', 'email' => 'bea@x.test'], $this->other);
    app(Timeline::class)->log($bea, ['type' => 'note', 'body' => 'SECRET other agent note'], $this->other);
    app(Consent::class)->optInSms($this->pat, 'signed', Consent::DEFAULT_SMS_WORDING, $this->agent);
    $this->ai->respond(briefJson());

    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/brief")->assertSessionHasNoErrors();

    $prompt = $this->ai->requests[0]->userMessage;
    expect($prompt)->toContain('Relocating for the hospital')->not->toContain('SECRET other agent note');

    $brief = ContactBrief::query()->sole();
    expect($brief->content['draft_channel'])->toBe('text')->and($brief->stale)->toBeFalse()
        ->and(DB::table('crm_contact_briefs')->value('content'))->not->toContain('hospital');

    $this->actingAs($this->agent)->get("/contacts/{$this->pat->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('brief.content.wants', '3-bed in Fondren under $350k.'));
});

it('only drafts in channels consent allows, and removes Fair Housing risk wording', function (): void {
    // No texting consent: a text draft becomes an email.
    $this->ai->respond(briefJson());
    $brief = app(ContactBriefs::class)->generate($this->pat, $this->agent);
    expect($brief->content['draft_channel'])->toBe('email')->and($brief->content['notes'][0])->toContain('Texting is not allowed');

    // Do Not Call and no email: only a note is allowed.
    $dnc = app(ManageContacts::class)->create(['first_name' => 'Dee', 'phone' => '6015550199'], $this->agent);
    app(Consent::class)->setDoNotCall($dnc, true, $this->agent);
    $this->ai->respond(briefJson(['draft_channel' => 'call', 'draft' => 'Hi Dee']));
    expect(app(ContactBriefs::class)->generate($dnc->fresh(), $this->agent)->content['draft_channel'])->toBe('note');

    $this->ai->respond(briefJson(['draft_channel' => 'email', 'draft' => 'This safe neighborhood is great for kids!']));
    $risky = app(ContactBriefs::class)->generate($this->pat, $this->agent);
    expect($risky->content['draft'])->toBe('')->and(implode(' ', $risky->content['notes']))->toContain('Fair Housing');
});

it('goes stale when anything new happens, so it refreshes itself', function (): void {
    $this->ai->respond(briefJson());
    app(ContactBriefs::class)->generate($this->pat, $this->agent);

    foreach ([
        fn () => app(Timeline::class)->log($this->pat, ['type' => 'call', 'outcome' => 'no_answer'], $this->agent),
        fn () => app(Tasks::class)->create($this->pat, ['title' => 'Follow up', 'due' => 'tomorrow'], $this->agent),
        fn () => app(Consent::class)->setDoNotCall($this->pat, true, $this->agent),
    ] as $change) {
        ContactBrief::query()->update(['stale' => false]);
        $change();
        expect(ContactBrief::query()->sole()->stale)->toBeTrue();
    }
});

it('keeps briefs private, records feedback, and fails gracefully', function (): void {
    $this->actingAs($this->other)->post("/contacts/{$this->pat->ulid}/brief")->assertNotFound();

    $this->ai->respond(briefJson());
    app(ContactBriefs::class)->generate($this->pat, $this->agent);
    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/brief/feedback", ['helpful' => true]);
    expect(ContactBrief::query()->sole()->helpful)->toBe(1);

    AiFeatureRoute::query()->delete();
    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/brief")->assertSessionHasNoErrors()->assertRedirect();
});

it('creates routes for declared AI features on the cheapest active model, without overwriting', function (): void {
    AiFeatureRoute::query()->delete();
    $cheap = AiModel::factory()->create(['capabilities' => ['chat'], 'input_cost_per_mtok_cents' => 1, 'output_cost_per_mtok_cents' => 1]);

    $this->artisan('ai:sync-features')->assertSuccessful();
    $route = AiFeatureRoute::query()->where('feature_key', ContactBriefs::FEATURE)->sole();
    expect($route->primary_model_id)->toBe($cheap->id)->and($route->credit_action_key)->toBe('ai.crm_brief');

    $route->update(['primary_model_id' => $this->model->id]);
    $this->artisan('ai:sync-features')->assertSuccessful();
    expect($route->fresh()?->primary_model_id)->toBe($this->model->id);
});

it('works through a list one contact at a time without skipping anyone', function (): void {
    $b = app(ManageContacts::class)->create(['first_name' => 'Bo', 'email' => 'bo@x.test'], $this->agent);
    $c = app(ManageContacts::class)->create(['first_name' => 'Cy', 'email' => 'cy@x.test'], $this->agent);

    $this->actingAs($this->agent)->get('/contacts/work?list=no-contact-7')->assertInertia(fn (Assert $page) => $page
        ->component('crm/contacts/work')->where('contact.name', 'Pat Lee')->where('position.total', 3)->where('position.done', 0));

    // Logging a call takes Pat off the "no contact" list; moving on by ID still lands on Bo, not Cy.
    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/activities", ['type' => 'call', 'outcome' => 'connected']);
    $this->actingAs($this->agent)->get("/contacts/work?list=no-contact-7&after={$this->pat->id}")->assertInertia(fn (Assert $page) => $page
        ->where('contact.name', 'Bo'));

    $this->actingAs($this->agent)->get("/contacts/work?list=no-contact-7&after={$c->id}")->assertInertia(fn (Assert $page) => $page->where('contact', null));
    $this->actingAs($this->other)->get('/contacts/work')->assertInertia(fn (Assert $page) => $page->where('position.total', 0));
});

it('imports notes and last-contact dates from the old CRM onto the timeline', function (): void {
    Storage::fake('local');
    $csv = "Name,Email,Notes,Last Contacted\nSam Ortiz,sam@x.test,Met at the Elm St open house,2025-10-04\nKim Lu,kim@x.test,,2025-09-01\n";
    $this->actingAs($this->agent)->post('/contacts/imports', ['file' => UploadedFile::fake()->createWithContent('old.csv', $csv)]);
    $import = ContactImport::query()->sole();

    $this->actingAs($this->agent)->get("/contact-imports/{$import->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('guess.Notes', 'notes')->where('guess.Last Contacted', 'last_contact'));
    $this->actingAs($this->agent)->post("/contact-imports/{$import->ulid}/run", ['mapping' => ['Name' => 'full_name', 'Email' => 'email', 'Notes' => 'notes', 'Last Contacted' => 'last_contact']]);

    $sam = Contact::query()->where('first_name', 'Sam')->sole();
    $note = Activity::query()->where('contact_id', $sam->id)->where('type', 'note')->sole();
    expect($note->body)->toBe('Imported note: Met at the Elm St open house')->and($note->occurred_at->toDateString())->toBe('2025-10-04')
        ->and($sam->last_activity_at?->toDateString())->toBe('2025-10-04')
        ->and(Contact::query()->where('first_name', 'Kim')->sole()->last_activity_at?->toDateString())->toBe('2025-09-01');
});
