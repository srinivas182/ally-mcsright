<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Enums\Lifecycle;
use Modules\Crm\FollowUpPlans;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Models\Task;
use Modules\Crm\Plans\Reactions;
use Modules\Sites\SiteSetup;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 15:00', 'UTC'));
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat']);
});

function reactiveContact(User $agent, string $first = 'Maria', array $extra = []): Contact
{
    return Contact::query()->create(['first_name' => $first, 'last_name' => 'Lopez', 'emails' => [['address' => 'maria@example.test', 'primary' => true]],
        'phones' => [['e164' => '+15125550100', 'primary' => true]], 'origin' => 'personal', 'source' => 'website', 'lifecycle' => 'lead', 'owner_user_id' => $agent->id] + $extra);
}

it('gives every RightAlly the ready-made plans once, in English and Spanish, and never brings back one switched off', function (): void {
    // A RightAlly from before keys existed already has the old buyer plan by name.
    FollowUpPlan::query()->create(['name' => 'New online lead (10 days)', 'shared' => true, 'active' => true, 'trigger' => 'manual']);
    $plans = app(FollowUpPlans::class);
    $plans->plansFor($this->agent);
    expect(FollowUpPlan::query()->count())->toBe(count(FollowUpPlans::TEMPLATES))
        ->and(FollowUpPlan::query()->where('name', 'New online lead (10 days)')->value('template_key'))->toBe('buyer_lead')
        ->and(FollowUpPlan::query()->pluck('template_key')->all())->toContain('seller_lead', 'expired_fsbo', 'not_ready', 'buyer_lead_es', 'seller_lead_es', 'past_client_es');

    FollowUpPlan::query()->where('template_key', 'expired_fsbo')->update(['active' => false]);
    $plans->plansFor($this->agent);
    expect(FollowUpPlan::query()->count())->toBe(count(FollowUpPlans::TEMPLATES))
        ->and(FollowUpPlan::query()->where('template_key', 'expired_fsbo')->value('active'))->toBeFalse();
    expect(FollowUpPlan::query()->where('template_key', 'buyer_lead_es')->first()?->steps()->first()?->title)->toBe('Llamar para presentarse');
});

it('steps up with a reason when the person shows they are ready, once a day, and not after they have moved on', function (): void {
    $contact = reactiveContact($this->agent);
    $reactions = app(Reactions::class);
    $task = $reactions->stepUp($contact, 'saved 12 Oak St on your website');
    expect($task?->title)->toBe('Reach out now: Maria Lopez saved 12 Oak St on your website')->and($task?->assigned_to)->toBe($this->agent->id)
        ->and($task?->type)->toBe('call');
    expect($reactions->stepUp($contact, 'worked out the payment on 12 Oak St on your website'))->toBeNull(); // once a day
    expect(Activity::query()->where('contact_id', $contact->id)->where('type', 'system')->latest('id')->value('body'))->toContain('Follow-up stepped up');

    $this->travel(1)->days();
    $contact->forceFill(['do_not_call' => true])->save();
    expect($reactions->stepUp($contact->refresh(), 'came back to your website after 20 days')?->type)->toBe('email'); // Do Not Call: email instead

    $this->travel(1)->days();
    $contact->forceFill(['lifecycle' => Lifecycle::UnderContract])->save();
    expect($reactions->stepUp($contact->refresh(), 'saved a home on your website'))->toBeNull();
});

it('moves people who stayed quiet after a plan to the monthly plan, but not those who replied', function (): void {
    $plans = app(FollowUpPlans::class);
    $plans->plansFor($this->agent);
    $quick = $plans->save(null, ['name' => 'Two-day check', 'steps' => [['day' => 0, 'type' => 'call', 'title' => 'Call'], ['day' => 1, 'type' => 'email', 'title' => 'Email']]], $this->agent);
    $quiet = reactiveContact($this->agent, 'Quinn');
    $replied = reactiveContact($this->agent, 'Riley');
    $plans->enroll($quiet, $quick, $this->agent);
    $plans->enroll($replied, $quick, $this->agent);
    $this->travel(1)->days();
    $plans->processDue();
    // Riley answered an email, which also stops plans; then Riley is done with this plan.
    DB::table('crm_activities')->insert(['ulid' => (string) str()->ulid(), 'tenant_id' => $this->tenant->id, 'contact_id' => $replied->id, 'type' => 'email', 'direction' => 'in',
        'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(PlanEnrollment::query()->where('contact_id', $quiet->id)->value('status'))->toBe('completed');

    $this->travel(4)->days();
    $this->artisan('crm:run-plans')->assertSuccessful();
    $monthly = FollowUpPlan::query()->where('template_key', 'not_ready')->sole();
    expect(PlanEnrollment::query()->where('contact_id', $quiet->id)->where('plan_id', $monthly->id)->where('status', 'active')->exists())->toBeTrue()
        ->and(PlanEnrollment::query()->where('contact_id', $replied->id)->where('plan_id', $monthly->id)->exists())->toBeFalse();
    expect(Activity::query()->where('contact_id', $quiet->id)->where('body', 'like', 'Moved to "Not ready yet (monthly)"%')->exists())->toBeTrue();

    $this->artisan('crm:run-plans'); // runs again: no second enrolment
    expect(PlanEnrollment::query()->where('contact_id', $quiet->id)->where('plan_id', $monthly->id)->count())->toBe(1);
    expect(Task::query()->where('contact_id', $quiet->id)->whereNull('completed_at')->where('title', 'Monthly market note')->exists())->toBeTrue();
});

it('offers three new website designs that start in their own colours', function (): void {
    foreach (['hearth' => '#b4532a', 'coastal' => '#0e7490', 'metro' => '#e11d48'] as $design => $colour) {
        expect(config("sites.templates.{$design}.name"))->not->toBeNull();
    }
    $site = app(SiteSetup::class)->create('coastal', ['phone' => '(512) 555-0100'], $this->owner);
    expect($site->brand['primary'])->toBe('#0e7490');
    $css = (string) file_get_contents(public_path('site-assets/site.css'));
    expect($css)->toContain('.theme-hearth {')->toContain('.theme-coastal {')->toContain('.theme-metro {');
});
