<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\ContactBriefs;
use Modules\Crm\DatabaseIntelligence;
use Modules\Crm\LeadPond;
use Modules\Crm\Models\Memory;
use Modules\Crm\Models\Referral;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Task;
use Modules\Crm\PersonalMemory;
use Modules\Crm\Referrals;
use Modules\Crm\TodaysTen;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 15:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    $this->other = User::factory()->create();
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    foreach ([DatabaseIntelligence::FEATURE, ContactBriefs::FEATURE] as $f) {
        AiFeatureRoute::query()->create(['feature_key' => $f, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    }
    $this->ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $this->ai);
    $this->contacts = app(ManageContacts::class);
    $this->pat = $this->contacts->create(['first_name' => 'Pat', 'email' => 'pat@x.test'], $this->agent);
});

it('remembers small personal details from notes, never sensitive ones, encrypted', function (): void {
    app(Timeline::class)->log($this->pat, ['type' => 'note', 'body' => 'Dog Luna; daughter Mia starts college; going through a divorce'], $this->agent);
    $this->ai->respond(json_encode(['intents' => [], 'memories' => [
        ['category' => 'pet', 'detail' => 'Dog called Luna'],
        ['category' => 'family', 'detail' => 'Daughter Mia starts college in the fall'],
        ['category' => 'other', 'detail' => 'Going through a divorce'],
        ['category' => 'secret', 'detail' => 'Unknown category'],
    ]]));

    app(DatabaseIntelligence::class)->analyseNow($this->pat, $this->agent);

    expect(Memory::query()->pluck('category')->all())->toBe(['pet', 'family'])
        ->and(DB::table('crm_memories')->value('detail'))->not->toContain('Luna');

    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/memories", ['category' => 'hobby', 'detail' => 'Loves fly fishing'])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/memories", ['category' => 'other', 'detail' => 'Recovering from surgery'])->assertSessionHasErrors('detail');
    $this->actingAs($this->other)->post("/contacts/{$this->pat->ulid}/memories", ['category' => 'pet', 'detail' => 'x'])->assertNotFound();
    expect(Memory::query()->count())->toBe(3);
});

it('suggests one personal touch in the brief, and never a sensitive one', function (): void {
    app(PersonalMemory::class)->add($this->pat, 'pet', 'Dog called Luna');
    $this->ai->respond(json_encode(['who' => 'Buyer', 'wants' => '', 'status' => '', 'unknowns' => [], 'next_step' => 'Call', 'next_step_reason' => 'x',
        'draft_channel' => 'email', 'draft' => 'Hi Pat', 'sources' => [], 'personal_touch' => 'Ask how Luna the dog is doing']));
    $brief = app(ContactBriefs::class)->generate($this->pat, $this->agent);
    expect($brief->content['personal_touch'])->toBe('Ask how Luna the dog is doing')
        ->and($this->ai->requests[0]->userMessage)->toContain('Dog called Luna');

    $this->ai->respond(json_encode(['who' => 'Buyer', 'wants' => '', 'status' => '', 'unknowns' => [], 'next_step' => 'Call', 'next_step_reason' => 'x',
        'draft_channel' => 'email', 'draft' => 'Hi', 'sources' => [], 'personal_touch' => 'Ask about their health']));
    expect(app(ContactBriefs::class)->generate($this->pat, $this->agent)->content['personal_touch'])->toBe('');
});

it('records referrals, thanks the referrer straight away, and celebrates when the deal closes', function (): void {
    $ana = $this->contacts->create(['first_name' => 'Ana', 'email' => 'ana@x.test'], $this->agent);
    $ana->forceFill(['lifecycle' => 'past_client', 'closed_on' => '2025-12-05'])->save();

    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/referred-by", ['referrer' => $ana->ulid])->assertSessionHasNoErrors();
    expect(Task::query()->where('contact_id', $ana->id)->value('title'))->toBe('Thank Ana for referring Pat')
        ->and(fn () => app(Referrals::class)->record($this->pat, $ana, $this->agent))->toThrow(ValidationException::class);

    app(Pipelines::class)->all();
    app(Pipelines::class)->move($this->pat, Stage::query()->where('name', 'Closed')->firstOrFail(), $this->agent);

    expect(Referral::query()->sole()->status)->toBe('closed')
        ->and(Task::query()->where('contact_id', $ana->id)->where('priority', 'high')->value('title'))->toBe("Send Ana a thank-you gift: Pat's deal closed");

    $this->actingAs($this->agent)->get("/contacts/{$ana->ulid}")->assertInertia(fn (Assert $page) => $page->where('referral.stats', ['referred' => 1, 'closed' => 1]));
});

it('knows good moments to ask for a referral and puts them in Today\'s 10', function (): void {
    $ana = $this->contacts->create(['first_name' => 'Ana', 'email' => 'ana@x.test'], $this->agent);
    $ana->forceFill(['lifecycle' => 'past_client', 'closed_on' => '2024-12-03', 'last_activity_at' => now()->subDays(20)])->save();

    $picks = collect(app(TodaysTen::class)->for($this->agent))->keyBy('name');
    expect($picks['Ana']['reason'])->toBe('Good moment to ask for a referral');

    $hidden = $this->contacts->create(['first_name' => 'Hid', 'email' => 'hid@x.test'], $this->other);
    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/referred-by", ['referrer' => $hidden->ulid])->assertNotFound();
    $this->actingAs($this->agent)->getJson('/contacts/lookup?q=hid')->assertJsonCount(0, 'contacts');
    $this->actingAs($this->agent)->getJson('/contacts/lookup?q=an')->assertJsonPath('contacts.0.name', 'Ana');
});

it('returns uncontacted company leads to the pond for agents to claim, first come first served, within a daily limit', function (): void {
    $pond = app(LeadPond::class);
    $lead = $this->contacts->create(['first_name' => 'Lee', 'email' => 'lee@x.test'], $this->owner);
    $this->contacts->assignOwner($lead, $this->agent, $this->owner);
    $worked = $this->contacts->create(['first_name' => 'Wes', 'email' => 'wes@x.test'], $this->owner);
    $this->contacts->assignOwner($worked, $this->agent, $this->owner);
    $personal = $this->contacts->create(['first_name' => 'Pia', 'email' => 'pia@x.test'], $this->agent);

    $this->travel(3)->days();
    app(Timeline::class)->log($worked->fresh(), ['type' => 'call', 'outcome' => 'no_answer'], $this->agent);
    expect($pond->sweep())->toBe(0); // the pond is off by default

    $this->actingAs($this->agent)->post('/contacts/pond/settings', ['enabled' => true, 'after_days' => 2, 'daily_claims' => 1])->assertSessionHasErrors('pond');
    $this->actingAs($this->owner)->post('/contacts/pond/settings', ['enabled' => true, 'after_days' => 2, 'daily_claims' => 1])->assertSessionHasNoErrors();
    $this->artisan('crm:ponds')->assertSuccessful();

    expect($lead->fresh()?->owner_user_id)->toBeNull()->and($lead->fresh()?->in_pond_since)->not->toBeNull()
        ->and($worked->fresh()?->owner_user_id)->toBe($this->agent->id)
        ->and($personal->fresh()?->owner_user_id)->toBe($this->agent->id);

    $this->actingAs($this->other)->get('/contacts/pond')->assertInertia(fn (Assert $page) => $page->where('leads.0.name', 'Lee')->where('claimsLeft', 1));
    $this->actingAs($this->other)->post("/contacts/{$lead->ulid}/claim")->assertRedirect("/contacts/{$lead->ulid}");
    expect($lead->fresh()?->owner_user_id)->toBe($this->other->id);
    $this->actingAs($this->agent)->post("/contacts/{$lead->ulid}/claim")->assertSessionHasErrors('pond');

    $second = $this->contacts->create(['first_name' => 'Sol', 'email' => 'sol@x.test'], $this->owner);
    $second->forceFill(['owner_user_id' => null, 'in_pond_since' => now()])->save();
    $this->actingAs($this->other)->post("/contacts/{$second->ulid}/claim")->assertSessionHasErrors('pond');
});
