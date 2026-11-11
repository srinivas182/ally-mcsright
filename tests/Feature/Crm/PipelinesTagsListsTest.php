<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Pipeline;
use Modules\Crm\Models\SmartList;
use Modules\Crm\Models\Stage;
use Modules\Crm\Models\Tag;
use Modules\Crm\SmartLists;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 15:00:00'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create();
    $this->other = User::factory()->create();
    $this->contacts = app(ManageContacts::class);
    $this->pat = $this->contacts->create(['first_name' => 'Pat', 'email' => 'pat@x.test'], $this->agent);
    $this->bea = $this->contacts->create(['first_name' => 'Bea', 'email' => 'bea@x.test'], $this->other);
});

function stageNamed(string $pipeline, string $name): Stage
{
    app(Pipelines::class)->all();

    return Stage::query()->where('name', $name)->where('pipeline_id', Pipeline::query()->where('name', $pipeline)->value('id'))->sole();
}

it('creates buyer and seller pipelines with sensible stages the first time', function (): void {
    $pipelines = app(Pipelines::class)->all();
    app(Pipelines::class)->all();

    expect($pipelines->pluck('name')->all())->toBe(['Buyers', 'Sellers'])
        ->and(Pipeline::query()->count())->toBe(2)
        ->and($pipelines->first()?->stages->pluck('name')->all())->toBe(['New', 'Nurture', 'Actively searching', 'Showing', 'Offer made', 'Under contract', 'Closed', 'Lost']);
});

it('moves contacts between stages, noting it on the timeline and updating their stage', function (): void {
    $showing = stageNamed('Buyers', 'Showing');

    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/stage", ['stage' => $showing->ulid])->assertSessionHasNoErrors();
    $pat = $this->pat->fresh();
    expect($pat?->stage_id)->toBe($showing->id)->and($pat?->lifecycle->value)->toBe('active')
        ->and(Activity::query()->where('body', 'Moved to Buyers: Showing')->exists())->toBeTrue();

    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/stage", ['stage' => stageNamed('Buyers', 'Closed')->ulid]);
    expect($this->pat->fresh()?->lifecycle->value)->toBe('past_client');

    $this->actingAs($this->other)->post("/contacts/{$this->pat->ulid}/stage", ['stage' => $showing->ulid])->assertNotFound();
});

it('shows a board with only the contacts each person may see', function (): void {
    $new = stageNamed('Buyers', 'New');
    app(Pipelines::class)->move($this->pat, $new, $this->agent);
    app(Pipelines::class)->move($this->bea, $new, $this->other);

    $this->actingAs($this->agent)->get('/contacts/board')->assertInertia(fn (Assert $page) => $page->component('crm/contacts/board')
        ->where('stages.0.name', 'New')->where('stages.0.count', 1)->where('stages.0.contacts.0.name', 'Pat'));
});

it('lets owners and staff add, rename, reorder and delete stages; agents cannot', function (): void {
    $buyers = Pipeline::query()->where('name', 'Buyers')->first() ?? app(Pipelines::class)->all()->first();
    $pipelines = app(Pipelines::class);

    $added = $pipelines->addStage($buyers, 'Pre-approved', $this->owner);
    expect(Stage::query()->where('pipeline_id', $buyers->id)->orderBy('sort')->pluck('name')->all())
        ->toBe(['New', 'Nurture', 'Actively searching', 'Showing', 'Offer made', 'Under contract', 'Pre-approved', 'Closed', 'Lost']);

    $pipelines->rename($added, 'Pre-approved buyer', $this->owner);
    $pipelines->move($this->pat, $added->fresh(), $this->agent);
    $pipelines->delete($added->fresh(), stageNamed('Buyers', 'Nurture'), $this->owner);
    expect($this->pat->fresh()?->stage_id)->toBe(stageNamed('Buyers', 'Nurture')->id);

    $ulids = Stage::query()->where('pipeline_id', $buyers->id)->orderByDesc('sort')->pluck('ulid')->all();
    $this->actingAs($this->owner)->post("/pipelines/{$buyers->ulid}/reorder", ['stages' => $ulids])->assertSessionHasNoErrors();
    expect(Stage::query()->where('pipeline_id', $buyers->id)->orderBy('sort')->value('name'))->toBe('Lost');

    expect(fn () => $pipelines->addStage($buyers, 'Agent idea', $this->agent))->toThrow(ValidationException::class)
        ->and(fn () => $pipelines->delete(stageNamed('Buyers', 'Closed'), stageNamed('Buyers', 'New'), $this->owner))->toThrow(ValidationException::class);
});

it('tags contacts in one tap, reusing tags regardless of case, and filters by tag', function (): void {
    $this->actingAs($this->agent)->post("/contacts/{$this->pat->ulid}/tags", ['tag' => 'First-time buyer']);
    $this->actingAs($this->other)->post("/contacts/{$this->bea->ulid}/tags", ['tag' => 'first-time BUYER']);

    expect(Tag::query()->count())->toBe(1);
    $this->actingAs($this->agent)->get('/contacts?tag=First-time+buyer')->assertInertia(fn (Assert $page) => $page
        ->has('contacts.data', 1)->where('contacts.data.0.name', 'Pat')->where('tags.0', 'First-time buyer'));

    $tag = Tag::query()->sole();
    $this->actingAs($this->agent)->delete("/contacts/{$this->pat->ulid}/tags/{$tag->id}");
    expect($this->pat->tags()->count())->toBe(0);
    $this->actingAs($this->agent)->post("/contacts/{$this->bea->ulid}/tags", ['tag' => 'x'])->assertNotFound();
});

it('counts the ready-made daily lists for each person\'s own contacts', function (): void {
    $old = $this->contacts->create(['first_name' => 'Old', 'email' => 'old@x.test'], $this->agent);
    $old->forceFill(['created_at' => now()->subDays(30), 'lifecycle' => 'active'])->save();
    app(Timeline::class)->log($this->pat, ['type' => 'note', 'body' => 'Spoke today'], $this->agent);
    app(Tasks::class)->create($old, ['title' => 'Overdue call', 'due_at' => '2026-11-18 10:00'], $this->agent);

    $lists = collect(app(SmartLists::class)->listsFor($this->agent))->keyBy('key');

    expect($lists['new-leads']['count'])->toBe(1)       // Pat (Old was created 30 days ago)
        ->and($lists['no-contact-7']['count'])->toBe(1)  // Old (Pat was contacted today)
        ->and($lists['overdue-tasks']['count'])->toBe(1);

    $this->actingAs($this->agent)->get('/contacts?list=no-contact-7')->assertInertia(fn (Assert $page) => $page
        ->has('contacts.data', 1)->where('contacts.data.0.name', 'Old'));
});

it('saves personal lists; only owners and staff share lists, which still respect privacy', function (): void {
    $lists = app(SmartLists::class);
    $mine = $lists->save($this->agent, 'My leads', ['lifecycle' => ['lead']], false);
    $shared = $lists->save($this->owner, 'All leads', ['lifecycle' => ['lead']], true);

    expect(fn () => $lists->save($this->agent, 'Shared?', ['lifecycle' => ['lead']], true))->toThrow(ValidationException::class)
        ->and(fn () => $lists->save($this->agent, 'Bad', ['salary' => 1], false))->toThrow(ValidationException::class);

    $this->actingAs($this->other)->get("/contacts?list={$shared->ulid}")->assertInertia(fn (Assert $page) => $page
        ->has('contacts.data', 1)->where('contacts.data.0.name', 'Bea'));
    $this->actingAs($this->other)->get("/contacts?list={$mine->ulid}")->assertNotFound();

    expect(fn () => $lists->delete($shared, $this->agent))->toThrow(ValidationException::class);
    $this->actingAs($this->agent)->delete("/smart-lists/{$mine->ulid}")->assertRedirect('/contacts');
    expect(SmartList::query()->count())->toBe(1);
});
