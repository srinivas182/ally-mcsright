<?php

declare(strict_types=1);

namespace Modules\Crm;

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Modules\AI\Events\AiBatchCompleted;
use Modules\AI\FeatureCatalog;
use Modules\AI\Models\AiBatch;
use Modules\AI\Support\PromptRegistry;
use Modules\Core\Access\PermissionRegistry;
use Modules\Core\Brief\DailyBriefs;
use Modules\Core\Identity\Enums\UserKind;
use Modules\Core\Mcp\McpToolbox;
use Modules\Core\Navigation\NavigationRegistry;
use Modules\Core\Navigation\NavItem;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Pipelines;
use Modules\Crm\Actions\Tasks;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Console\SendTaskReminders;
use Modules\Crm\Events\ActivityLogged;
use Modules\Crm\Events\ContactCreated;
use Modules\Crm\Events\ContactOwnerChanged;
use Modules\Crm\Events\ContactStageChanged;
use Modules\Crm\Events\ContactUpdated;
use Modules\Crm\Events\TaskCompleted;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\FollowUpPlan;
use Modules\Crm\Models\PlanEnrollment;
use Modules\Crm\Models\Stage;
use Modules\Crm\Webhooks\CrmEvents;

final class CrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Booking\Availability::class);
        $this->app->scoped(ContactVisibility::class);
        $this->app->scoped(ManageContacts::class);
        $this->app->scoped(Timeline::class);
        $this->app->scoped(Tasks::class);
        $this->app->scoped(Pipelines::class);
        $this->app->scoped(Actions\Tags::class);
        $this->app->scoped(SmartLists::class);
        $this->app->scoped(Consent::class);
        $this->app->scoped(ContactBriefs::class);
        $this->app->scoped(AskDatabase::class);
        $this->app->scoped(VoiceUpdates::class);
        $this->app->scoped(FollowUpPlans::class);
        $this->app->scoped(DatabaseIntelligence::class);
        $this->app->scoped(TodaysTen::class);
        $this->app->scoped(PersonalMemory::class);
        $this->app->scoped(Referrals::class);
        $this->app->scoped(LeadPond::class);
        $this->app->scoped(Leads\LeadRouting::class);
        $this->app->scoped(Leads\LeadIntake::class);
        $this->app->scoped(Leads\LeadSources::class);
        $this->app->scoped(Leads\SpeedToLead::class);
        $this->app->scoped(Leads\PortalEmail::class);
        $this->app->scoped(Inbox\Inbox::class);
        $this->app->scoped(Leads\AiResponder::class);
        $this->app->scoped(Leads\LeadScoring::class);
        $this->app->scoped(Searches\SavedSearches::class);
        $this->app->scoped(Leads\LeadReports::class);
        $this->app->bind(Leads\SlowResponseRisks::class);
        $this->app->scoped(Inbox\SendGuard::class);
        $this->app->scoped(Inbox\SignCodes::class);
        $this->app->scoped(Inbox\Templates::class);
        $this->app->bind(Inbox\UnansweredRisks::class);
        $this->app->tag([Inbox\UnansweredRisks::class, Leads\SlowResponseRisks::class], 'risk.sources');
        $this->app->scoped(Actions\MergeContacts::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'crm');

        // AI: prompts live with the module; features are declared for ai:sync-features.
        $this->app->make(PromptRegistry::class)->addPath(__DIR__.'/../prompts');
        // Nightly brief answers come back through the AI batch service.
        Event::listen(AiBatchCompleted::class, function (AiBatchCompleted $event): void {
            if (! in_array($event->featureKey, [ContactBriefs::FEATURE, DatabaseIntelligence::FEATURE], true)) {
                return;
            }
            $batch = AiBatch::query()->where('ulid', $event->batchUlid)->first();
            foreach ($batch?->items()->where('status', 'ok')->get() ?? [] as $item) {
                $event->featureKey === ContactBriefs::FEATURE
                    ? $this->app->make(ContactBriefs::class)->storeBatchResult($item->idempotency_key, (string) $item->result)
                    : $this->app->make(DatabaseIntelligence::class)->storeBatchResult($item->idempotency_key, (string) $item->result);
                $item->update(['result' => null]);
            }
        });

        // Tools for people's AI assistants (MCP, specs/connect/03): same visibility rules as the screens.
        foreach ([Mcp\SearchContacts::class, Mcp\GetContact::class, Mcp\MyTasks::class, Mcp\TodaysList::class, Mcp\OpenTimes::class,
            Mcp\AddContact::class, Mcp\LogNote::class, Mcp\AddTask::class, Mcp\CompleteTask::class] as $tool) {
            $this->app->make(McpToolbox::class)->add($tool);
        }
        Mcp\CrmBrief::register($this->app->make(McpToolbox::class));
        Brief\CrmBriefItems::register($this->app->make(DailyBriefs::class));

        // Connected apps hear about company contacts, stage changes and completed tasks (specs/connect/01).
        Event::listen(ContactCreated::class, fn (ContactCreated $e) => CrmEvents::contactCreated($e->contactId));
        Event::listen(ContactStageChanged::class, fn (ContactStageChanged $e) => CrmEvents::stageChanged($e->contactId, $e->stageId));
        Event::listen(TaskCompleted::class, fn (TaskCompleted $e) => CrmEvents::taskCompleted($e->taskId));

        // Two-way CRMs: new contacts and notes go to Follow Up Boss (specs/connect/05).
        Event::listen(ContactCreated::class, fn (ContactCreated $e) => Connect\FollowUpBossOutbox::contactCreated($e->contactId));
        Event::listen(ContactUpdated::class, fn (ContactUpdated $e) => Connect\FollowUpBossOutbox::contactChanged($e->contactId));
        Event::listen(ContactOwnerChanged::class, fn (ContactOwnerChanged $e) => Connect\FollowUpBossOutbox::contactChanged($e->contactId));
        Event::listen(ActivityLogged::class, function (ActivityLogged $e): void {
            if ($e->type === 'note') {
                Connect\FollowUpBossOutbox::noteLogged($e->contactId, $e->activityId);
            }
        });

        // Follow-up plans: automatic enrolment by source or stage; stop when the person engages.
        Event::listen(ContactCreated::class, function (ContactCreated $e): void {
            $contact = Contact::query()->find($e->contactId);
            $by = $e->byUserId !== null ? User::query()->find($e->byUserId) : null;
            if ($contact === null || $by === null) {
                return;
            }
            $plans = $this->app->make(FollowUpPlans::class);
            foreach (FollowUpPlan::query()->where('active', true)->where('trigger', 'source')->where('trigger_value', $contact->source->value)->get() as $plan) {
                try {
                    $plans->enroll($contact, $plan, $by);
                } catch (ValidationException) {
                    // not usable for this contact
                }
            }
        });
        Event::listen(ContactStageChanged::class, function (ContactStageChanged $e): void {
            $contact = Contact::query()->find($e->contactId);
            $by = $e->byUserId !== null ? User::query()->find($e->byUserId) : null;
            if ($contact === null || $by === null) {
                return;
            }
            $plans = $this->app->make(FollowUpPlans::class);
            foreach (PlanEnrollment::query()->where('contact_id', $contact->id)->where('status', 'active')->get() as $enrollment) {
                $plans->process($enrollment);
            }
            if (Stage::query()->whereKey($e->stageId)->value('outcome') === 'won') {
                $this->app->make(Referrals::class)->closed($contact);
            }
            $stageUlid = Stage::query()->whereKey($e->stageId)->value('ulid');
            foreach (FollowUpPlan::query()->where('active', true)->where('trigger', 'stage')->where('trigger_value', $stageUlid)->get() as $plan) {
                try {
                    $plans->enroll($contact, $plan, $by);
                } catch (ValidationException) {
                    // already enrolled or not usable
                }
            }
        });
        Event::listen(ActivityLogged::class, function (ActivityLogged $e): void {
            $activity = Activity::query()->find($e->activityId);
            $engaged = $activity !== null && (($activity->type === 'call' && $activity->outcome === 'connected') || (in_array($activity->type, ['email', 'text'], true) && $activity->direction === 'in'));
            $contact = $engaged ? Contact::query()->find($e->contactId) : null;
            if ($contact !== null) {
                $this->app->make(FollowUpPlans::class)->stopAllFor($contact, 'Contact responded');
            }
            // Speed to lead: the first call, text or email answers the contact's open leads.
            if ($activity !== null) {
                $this->app->make(Leads\SpeedToLead::class)->activityLogged($activity);
            }
            // Keep the opportunity score current (cheap, rule-based).
            $scored = Contact::query()->find($e->contactId);
            if ($scored !== null) {
                $this->app->make(DatabaseIntelligence::class)->score($scored);
            }
        });

        // Permissions (specs/core/03); scopes widen access to company contacts only.
        $this->app->make(PermissionRegistry::class)->register('Contacts and CRM', [
            'crm.contacts.view' => ['See company contacts', true],
            'crm.contacts.manage' => ['Edit company contacts', true],
            'crm.contacts.reassign' => ['Reassign company contacts', true],
            'crm.import' => ['Import contacts into the company database'],
            'crm.settings.manage' => ['Manage CRM settings (pipelines, shared plans and lists, lead pond)'],
        ]);

        $features = $this->app->make(FeatureCatalog::class);
        $features->declare(ContactBriefs::FEATURE, 'ai.crm_brief', 'AI contact brief on every contact');
        $features->declare(AskDatabase::FEATURE, 'ai.crm_ask', 'Ask your database in plain language');
        $features->declare(VoiceUpdates::TRANSCRIBE, 'ai.voice', 'Speech to text in the CRM', 'transcribe');
        $features->declare(VoiceUpdates::LOG, 'ai.crm_voice_log', 'Spoken or typed contact updates');
        $features->declare(VoiceUpdates::QUICK_ADD, 'ai.crm_quick_add', 'Spoken quick add');
        $features->declare(DatabaseIntelligence::FEATURE, 'ai.crm_intents', 'Reading plans and dates from notes');
        $features->declare(Leads\PortalEmail::FEATURE, 'ai.crm_lead_email', 'Reading forwarded lead emails from any sender');
        $features->declare(Inbox\Inbox::DRAFT, 'ai.crm_reply_draft', 'Suggested replies in the inbox');
        $features->declare(Leads\AiResponder::FEATURE, 'ai.crm_ai_responder', 'AI first responder: answers and qualifies new leads');
        $features->declare(Leads\LongNurture::FEATURE, 'ai.crm_nurture', 'AI long follow-up: 12 months of check-ins for quiet leads');

        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Contacts', '/contacts', 'contact', 10,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Leads', '/leads', 'zap', 5,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent], mobile: true));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Grow', 'Inbox', '/inbox', 'inbox', 7,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Overview', "Today's 10", '/today', 'target', 15,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Overview', 'Tasks', '/tasks', 'list-checks', 20,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent, UserKind::Assistant], mobile: true));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Settings', 'My availability', '/settings/availability', 'calendar-check', 67,
            kinds: [UserKind::Owner, UserKind::Staff, UserKind::Agent]));
        $this->app->make(NavigationRegistry::class)->add(new NavItem('Settings', 'Fair Housing', '/settings/fair-housing', 'scale', 88, kinds: [UserKind::Owner]));

        if ($this->app->runningInConsole()) {
            $this->commands([SendTaskReminders::class, Console\RefreshBriefs::class, Console\RunPlans::class, Console\RunIntelligence::class, Console\SweepPonds::class, Console\ReassignLeads::class, Console\SweepInbox::class, Console\ScoreLeads::class, Console\SendSearchAlerts::class, Console\RunNurture::class, Console\SendFairHousingPacks::class]);
        }
    }
}
