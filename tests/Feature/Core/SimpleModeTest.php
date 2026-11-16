<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Simple\SimpleMode;
use Modules\Crm\Actions\Tasks;
use Modules\Transactions\Models\Deal;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Lee']);
});

/** @return list<string> */
function simpleMenu(User $user): array
{
    return collect(app(NavigationRegistry::class)->for($user->refresh()))->flatMap(fn ($g) => array_column($g['items'], 'title'))->all();
}

it('has every simple-mode word in English and Spanish', function (): void {
    expect(SimpleMode::missing())->toBe([]);
});

it('asks once, then switches to a short menu and a big-button home, in English or Spanish', function (): void {
    $this->actingAs($this->agent)->get('/agent')->assertOk()->assertInertia(fn (Assert $page) => $page->where('simple.mode', null)->where('simple.words.choose_title', 'Would you like RightAlly simpler?'));
    $this->actingAs($this->agent)->post('/settings/view', ['mode' => 'simple'])->assertRedirect('/simple');
    expect($this->agent->refresh()->getAttribute('ui_mode'))->toBe('simple');
    $this->actingAs($this->agent)->get('/agent')->assertRedirect('/simple');
    $this->actingAs($this->agent)->get('/simple')->assertOk()->assertInertia(fn (Assert $page) => $page->component('simple/home')->where('firstName', 'Pat')->has('today')
        ->where('navigation.0.items.0.href', '/simple'));
    expect(simpleMenu($this->agent))->toBe(['Home', 'Who to call today', 'My deals', 'My people', 'My to-dos', 'Ask a question']);

    $this->actingAs($this->agent)->post('/settings/view', ['locale' => 'es'])->assertRedirect();
    expect(simpleMenu($this->agent))->toBe(['Inicio', 'A quién llamar hoy', 'Mis transacciones', 'Mis contactos', 'Mis pendientes', 'Hacer una pregunta']);
    $this->actingAs($this->agent)->get('/simple')->assertInertia(fn (Assert $page) => $page->where('simple.words.what', '¿Qué quiere hacer?'));

    $this->actingAs($this->agent)->post('/settings/view', ['mode' => 'full'])->assertRedirect('/agent');
    expect(simpleMenu($this->agent))->toContain('Business planner');
    $this->actingAs($this->agent)->post('/settings/view', ['mode' => 'fancy'])->assertSessionHasErrors('mode');
});

it('never shows a page in the short menu that the person cannot open', function (): void {
    $assistant = User::factory()->assistantOf($this->agent)->create();
    $this->actingAs($assistant)->post('/settings/view', ['mode' => 'simple']);
    expect(simpleMenu($assistant)[0])->toBe('Home');
    $menu = SimpleMode::menu([['label' => 'Overview', 'items' => [['title' => 'Deals', 'href' => '/deals', 'icon' => 'briefcase', 'mobile' => true]]]], 'en');
    expect(array_column($menu[0]['items'], 'title'))->toBe(['Home', 'My deals']); // nothing the person could not open
    $this->actingAs($this->owner)->post('/settings/view', ['mode' => 'simple']);
    $this->actingAs($this->owner)->get('/dashboard')->assertRedirect('/simple');
});

it('offers undo for ten minutes, once, only to the person who did it', function (): void {
    $task = app(Tasks::class)->create(null, ['title' => 'Call Maria', 'due' => 'today'], $this->agent);
    $this->actingAs($this->agent)->post("/tasks/{$task->ulid}/complete")->assertSessionHasNoErrors();
    $undo = (string) DB::table('undo_actions')->where('kind', 'crm.task.complete')->value('ulid');
    expect($task->refresh()->completed_at)->not->toBeNull();

    $other = User::factory()->create();
    $this->actingAs($other)->post("/undo/{$undo}")->assertSessionHasErrors('undo');
    $this->actingAs($this->agent)->post("/undo/{$undo}")->assertSessionHasNoErrors();
    expect($task->refresh()->completed_at)->toBeNull();
    $this->actingAs($this->agent)->post("/undo/{$undo}")->assertSessionHasErrors('undo'); // once

    $this->actingAs($this->agent)->post("/tasks/{$task->ulid}/complete");
    $undo = (string) DB::table('undo_actions')->where('kind', 'crm.task.complete')->orderByDesc('id')->value('ulid');
    $this->travel(11)->minutes();
    $this->actingAs($this->agent)->post("/undo/{$undo}")->assertSessionHasErrors('undo');
});

it('puts deal actions back: a ticked date, a removed date, a skipped email', function (): void {
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '80/20', ['split' => ['mode' => 'percentage', 'agent_percent' => '80']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
    $this->actingAs($this->agent)->post('/deals', ['type' => 'buyer', 'street' => '12 Oak St', 'city' => 'Austin', 'state_code' => 'TX', 'price' => '480000']);
    $deal = Deal::query()->latest('id')->firstOrFail();
    $id = (int) DB::table('tx_dates')->insertGetId(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'label' => 'Survey', 'due_on' => '2026-06-10', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $last = fn (string $kind): string => (string) DB::table('undo_actions')->where('kind', $kind)->orderByDesc('id')->value('ulid');

    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/dates/{$id}/toggle");
    expect(DB::table('tx_dates')->where('id', $id)->value('done_at'))->not->toBeNull();
    $this->actingAs($this->agent)->post('/undo/'.$last('deal.date.toggle'))->assertSessionHasNoErrors();
    expect(DB::table('tx_dates')->where('id', $id)->value('done_at'))->toBeNull();

    $this->actingAs($this->agent)->delete("/deals/{$deal->ulid}/dates/{$id}");
    expect(DB::table('tx_dates')->where('deal_id', $deal->id)->where('label', 'Survey')->exists())->toBeFalse();
    $this->actingAs($this->agent)->post('/undo/'.$last('deal.date.remove'))->assertSessionHasNoErrors();
    expect(DB::table('tx_dates')->where('deal_id', $deal->id)->where('label', 'Survey')->value('due_on'))->toStartWith('2026-06-10');

    DB::table('tx_playbook_steps')->insert(['tenant_id' => $deal->tenant_id, 'deal_id' => $deal->id, 'step' => 'introductions', 'due_at' => now(), 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/playbook/introductions/skip");
    $this->actingAs($this->agent)->post('/undo/'.$last('deal.step.skip'))->assertSessionHasNoErrors();
    expect(DB::table('tx_playbook_steps')->where('step', 'introductions')->value('status'))->toBe('ready');
});
