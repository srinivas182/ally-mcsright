<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\AI\Workflows\AiWorkflowDrafter;
use Modules\Core\Access\Permissions;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Workflows\WorkflowAlertMail;
use Modules\Core\Workflows\WorkflowApprovalMail;
use Modules\Core\Workflows\Workflows;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Models\Task;
use Modules\Transactions\Deals;
use Modules\Transactions\Events\DealStatusChanged;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Models\Item;
use Tests\Fixtures\FakeAiProvider;

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive']);
    $this->broker = User::factory()->staff()->create(['first_name' => 'Bea']);
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
    $this->wf = app(Workflows::class);
    Notification::fake();
});

function startDeal(User $agent, User $by, int $price = 450000)
{
    $deals = app(Deals::class);
    $deal = $deals->create(['type' => 'buyer', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX'], $agent, $by);
    $deal->forceFill(['price_cents' => $price * 100])->save();

    return $deal;
}

it('runs steps when a deal goes under contract, only when its conditions hold', function (): void {
    $contact = app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'ann@x.test'], $this->agent);
    $ulid = $this->wf->save(null, 'Big deals', null, 'deal.created', [['fact' => 'state', 'op' => 'is', 'value' => 'tx'], ['fact' => 'city', 'op' => 'contains', 'value' => 'aus']], [
        ['type' => 'create_task', 'params' => ['title' => 'Order the inspection', 'due_days' => 2, 'assign' => 'owner']],
        ['type' => 'add_checklist_item', 'params' => ['label' => 'Lead-based paint disclosure', 'required' => 'yes']],
        ['type' => 'add_tag', 'params' => ['tag' => 'Under contract']],
        ['type' => 'notify', 'params' => ['to' => 'owners', 'message' => 'New deal in Austin']],
    ], $this->owner);

    // Off: nothing happens.
    startDeal($this->agent, $this->owner);
    expect(DB::table('workflow_runs')->count())->toBe(0);

    $this->wf->switch($ulid, true, $this->owner);
    $deal = app(Deals::class)->create(['type' => 'buyer', 'street' => '9 Elm St', 'city' => 'Austin', 'state_code' => 'TX'], $this->agent, $this->owner, $contact);
    $run = DB::table('workflow_runs')->sole();
    expect($run->status)->toBe('done')
        ->and(Task::query()->where('title', 'Order the inspection')->where('assigned_to', $this->agent->id)->exists())->toBeTrue()
        ->and(Item::query()->where('deal_id', $deal->id)->where('label', 'Lead-based paint disclosure')->where('required', true)->exists())->toBeTrue()
        ->and($contact->tags()->pluck('name')->all())->toContain('Under contract');
    Notification::assertSentTo($this->owner, WorkflowAlertMail::class, fn (WorkflowAlertMail $m) => $m->title === 'New deal in Austin' && $m->path === '/deals/'.$deal->ulid);
    expect(DB::table('workflow_run_steps')->where('run_id', $run->id)->pluck('status')->all())->toBe(['done', 'done', 'done', 'done']);

    // A deal elsewhere doesn't match.
    app(Deals::class)->create(['type' => 'buyer', 'street' => '1 Main', 'city' => 'Jackson', 'state_code' => 'MS'], $this->agent, $this->owner);
    expect(DB::table('workflow_runs')->count())->toBe(1);

    // The run history page.
    $this->actingAs($this->owner)->get("/automations/{$ulid}/runs")->assertInertia(fn (Assert $page) => $page
        ->component('automations/runs')->where('runs.0.subject', '9 Elm St')->where('runs.0.status', 'done')->where('runs.0.steps.1.note', 'Added to the checklist: Lead-based paint disclosure'));
});

it('waits, keeps runs on their version, and carries on later', function (): void {
    $ulid = $this->wf->save(null, 'Follow up', null, 'contact.created', [], [
        ['type' => 'wait', 'params' => ['days' => 2]],
        ['type' => 'create_task', 'params' => ['title' => 'Check in', 'due_days' => 0, 'assign' => 'owner']],
    ], $this->owner);
    $this->wf->switch($ulid, true, $this->owner);
    app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'ann@x.test'], $this->agent);
    expect(DB::table('workflow_runs')->value('status'))->toBe('waiting');

    // A new version changes the task, but the waiting run finishes on version 1.
    $this->wf->save($ulid, 'Follow up', null, 'contact.created', [], [['type' => 'create_task', 'params' => ['title' => 'Changed', 'due_days' => 0, 'assign' => 'owner']]], $this->owner);
    $this->travel(3)->days();
    $this->artisan('core:workflows')->assertSuccessful();
    expect(DB::table('workflow_runs')->value('status'))->toBe('done')
        ->and(Task::query()->where('title', 'Check in')->exists())->toBeTrue()
        ->and(Task::query()->where('title', 'Changed')->exists())->toBeFalse();
});

it('waits for approval; the person who set it off can never approve', function (): void {
    app(Roles::class)->all();
    $approver = app(Roles::class)->save(null, 'Approver', null, ['transactions.cda.approve' => null], $this->owner);
    app(Roles::class)->assign($this->broker, $approver, $this->owner);
    $ulid = $this->wf->save(null, 'Over $1M', null, 'deal.created', [['fact' => 'price', 'op' => 'over', 'value' => 1000000]], [
        ['type' => 'approval', 'params' => ['question' => 'Approve this big deal', 'needed' => 2, 'permission' => 'transactions.cda.approve']],
        ['type' => 'notify', 'params' => ['to' => 'owner', 'message' => 'Approved']],
    ], $this->owner);
    $this->wf->switch($ulid, true, $this->owner);

    $this->actingAs($this->owner);
    $deal = new Deal(['type' => 'buyer', 'street' => '1 Big Rd', 'state_code' => 'TX', 'agent_user_id' => $this->agent->id, 'status' => 'pre_contract', 'price_cents' => 150000000]);
    $deal->save();
    DealStatusChanged::dispatch($deal->tenant_id, $deal->id, null, 'pre_contract');

    expect(DB::table('workflow_runs')->value('status'))->toBe('approval');
    Notification::assertSentTo($this->broker, WorkflowApprovalMail::class);
    Notification::assertNotSentTo($this->owner, WorkflowApprovalMail::class); // the owner set it off
    $approval = DB::table('workflow_approvals')->sole();

    expect(fn () => $this->wf->decide($approval->ulid, $this->owner, true))->toThrow(ValidationException::class, 'someone else')
        ->and(fn () => $this->wf->decide($approval->ulid, $this->agent, true))->toThrow(ValidationException::class, 'not one of the approvers');

    $this->actingAs($this->broker)->get('/automations/approvals')->assertInertia(fn (Assert $page) => $page->where('approvals.0.question', 'Approve this big deal'));
    $this->actingAs($this->broker)->post("/automations/approvals/{$approval->ulid}", ['approve' => true])->assertSessionHasNoErrors();
    expect(DB::table('workflow_runs')->value('status'))->toBe('approval'); // needs two

    $second = User::factory()->owner()->create();
    $this->wf->decide($approval->ulid, $second, true);
    expect(DB::table('workflow_runs')->value('status'))->toBe('done');
    Notification::assertSentTo($this->agent, WorkflowAlertMail::class, fn ($m) => $m->title === 'Approved');
});

it('records failures with a retry, and refuses steps that do not fit', function (): void {
    expect(fn () => $this->wf->save(null, 'Bad', null, 'contact.created', [], [['type' => 'add_checklist_item', 'params' => ['label' => 'X', 'required' => 'yes']]], $this->owner))
        ->toThrow(ValidationException::class, "doesn't work on contacts")
        ->and(fn () => $this->wf->save(null, 'Bad', null, 'nope', [], [['type' => 'notify']], $this->owner))->toThrow(ValidationException::class)
        ->and(fn () => $this->wf->save(null, 'Bad', null, 'contact.created', [['fact' => 'salary', 'op' => 'is', 'value' => 1]], [['type' => 'notify', 'params' => ['to' => 'owners', 'message' => 'x']]], $this->owner))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->wf->save(null, 'Mine', null, 'contact.created', [], [['type' => 'notify', 'params' => ['to' => 'owners', 'message' => 'x']]], $this->agent))
        ->toThrow(ValidationException::class, 'Only owners');

    $ulid = $this->wf->save(null, 'Set a field', null, 'contact.created', [], [['type' => 'set_field', 'params' => ['field' => 'lender', 'value' => 'Chase']]], $this->owner);
    $this->wf->switch($ulid, true, $this->owner);
    app(CustomFields::class)->define('contact', ['label' => 'Lender', 'type' => 'choice', 'options' => ['Wells', 'Citi']], $this->owner);
    app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'ann@x.test'], $this->agent);
    $run = DB::table('workflow_runs')->sole();
    expect($run->status)->toBe('failed')->and($run->error)->toContain('choose one of the choices');

    DB::table('custom_fields')->update(['options' => json_encode(['Wells', 'Chase'])]);
    app(CustomFields::class)->define('contact', ['label' => 'Other', 'type' => 'text'], $this->owner); // clears the cached definitions
    $this->actingAs($this->owner)->post("/automations/runs/{$run->ulid}/retry")->assertSessionHasNoErrors();
    expect(DB::table('workflow_runs')->value('status'))->toBe('done');
});

it('starts automations when a custom field changes, without looping', function (): void {
    app(CustomFields::class)->define('contact', ['label' => 'Status', 'type' => 'text'], $this->owner);
    $ulid = $this->wf->save(null, 'Echo', null, 'contact.field_changed', [['fact' => 'field', 'op' => 'is', 'value' => 'status']], [
        ['type' => 'set_field', 'params' => ['field' => 'status', 'value' => 'again']],
    ], $this->owner);
    $this->wf->switch($ulid, true, $this->owner);
    $ann = app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'ann@x.test'], $this->agent);
    app(CustomFields::class)->save('contact', $ann->id, ['status' => 'hot'], $this->owner);
    expect(DB::table('workflow_runs')->count())->toBeLessThanOrEqual(3)
        ->and(app(CustomFields::class)->raw('contact', $ann->id))->toBe(['status' => 'again']);
});

it('dry-runs against the last 30 days and changes nothing', function (): void {
    foreach (['website', 'website', 'zillow'] as $i => $source) {
        app(ManageContacts::class)->create(['first_name' => "Lead{$i}", 'email' => "l{$i}@x.test", 'source' => $source], $this->agent);
    }
    $this->actingAs($this->owner)->postJson('/automations/dry-run', ['trigger' => 'contact.created', 'conditions' => [['fact' => 'source', 'op' => 'is', 'value' => 'website']],
        'steps' => [['type' => 'create_task', 'params' => ['title' => 'Call', 'due_days' => 0, 'assign' => 'owner']]]])
        ->assertOk()->assertJson(['events' => 3, 'matched' => 2])->assertJsonPath('steps.0', 'Create a task for the agent: "Call" (due today)');
    expect(Task::query()->count())->toBe(0)->and(DB::table('workflow_runs')->count())->toBe(0);
});

it('adds ready-made automations switched off, and lists them', function (): void {
    $this->actingAs($this->agent)->get('/automations')->assertForbidden();
    $this->actingAs($this->owner)->get('/automations')->assertInertia(fn (Assert $page) => $page
        ->component('automations/index')->where('templates', fn ($t) => collect($t)->pluck('key')->contains('big_deal_approval')));
    $this->actingAs($this->owner)->post('/automations/templates/closed_anniversary')->assertRedirect();
    $this->actingAs($this->owner)->get('/automations')->assertInertia(fn (Assert $page) => $page
        ->where('automations.0.name', 'Closed: thank-you and review request')->where('automations.0.on', false)
        ->where('automations.0.summary', fn (string $s) => str_contains($s, 'When a deal closes') && str_contains($s, 'Wait 7 days')));

    // A role can manage automations.
    app(Roles::class)->all();
    $role = app(Roles::class)->save(null, 'Ops', null, ['core.workflows.manage' => null], $this->owner);
    app(Roles::class)->assign($this->agent, $role, $this->owner);
    app(Permissions::class)->forget();
    $this->actingAs($this->agent->fresh())->get('/automations')->assertOk();
});

it('drafts an automation from plain English, from the catalogue only', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => AiWorkflowDrafter::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);

    $ai->respond(json_encode(['name' => 'Big deal approval', 'trigger' => 'deal.under_contract', 'conditions' => [['fact' => 'price', 'op' => 'over', 'value' => 1000000]],
        'steps' => [['type' => 'approval', 'params' => ['question' => 'Approve', 'needed' => 1, 'permission' => 'transactions.cda.approve']], ['type' => 'wire_money', 'params' => []]]]));
    $this->actingAs($this->owner)->postJson('/automations/draft', ['text' => 'when a deal over 1M goes under contract ask the broker'])->assertOk()
        ->assertJsonPath('draft.trigger', 'deal.under_contract')->assertJsonCount(1, 'draft.steps')->assertJsonPath('draft.steps.0.type', 'approval');

    $ai->respond(json_encode(['trigger' => 'made.up', 'steps' => [['type' => 'notify']]]));
    $this->actingAs($this->owner)->postJson('/automations/draft', ['text' => 'nonsense'])->assertJsonPath('draft', null);
});
