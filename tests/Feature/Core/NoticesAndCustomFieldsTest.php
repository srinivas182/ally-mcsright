<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AI\Models\AiFeatureRoute;
use Modules\AI\Models\AiModel;
use Modules\AI\Providers\ProviderRegistry;
use Modules\Connect\Models\Connection;
use Modules\Core\Access\AccessAlertMail;
use Modules\Core\Access\Role;
use Modules\Core\Access\Roles;
use Modules\Core\CustomFields\CustomFields;
use Modules\Core\Notices\NoticeDigestMail;
use Modules\Core\Notices\Notices;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Actions\Timeline;
use Modules\Crm\Connect\Importer;
use Modules\Crm\FieldSuggestions;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\ContactImport;
use Modules\Crm\SmartLists;
use Modules\Transactions\Deals;
use Tests\Fixtures\FakeAiProvider;

final class NewLeadArrivedMail extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('New lead: Ann Lee')->line('Ann wants to see 12 Oak St.')->action('Open the lead', url('/leads/abc'));
    }
}

final class SignInCodeMail extends Notification
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your code is 123456');
    }
}

beforeEach(function (): void {
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['timezone' => 'America/Chicago']);
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'timezone' => 'America/Chicago']);
    $this->staff = User::factory()->staff()->create(['first_name' => 'Sue']);
    Event::fake([NotificationSent::class]);
});

function emailsTo(User $user, string $class): int
{
    return count(Event::dispatched(NotificationSent::class, fn (NotificationSent $e) => $e->notifiable->is($user) && $e->notification instanceof $class && $e->channel === 'mail'));
}

it('puts every email alert in the notification centre and the bell', function (): void {
    $this->agent->notify(new NewLeadArrivedMail);
    expect(emailsTo($this->agent, NewLeadArrivedMail::class))->toBe(1);

    $this->actingAs($this->agent)->get('/notifications')->assertInertia(fn (Assert $page) => $page
        ->component('notifications/index')->where('notices.0.title', 'New lead: Ann Lee')->where('notices.0.body', 'Ann wants to see 12 Oak St.')
        ->where('notices.0.url', '/leads/abc')->where('notices.0.read', false)->count('notices', 1)
        ->where('notices.0.label', 'New Lead Arrived')->where('notices.0.type', 'new_lead_arrived'));
    $this->actingAs($this->agent)->get('/contacts')->assertInertia(fn (Assert $page) => $page->where('notices.unread', 1));
    $this->actingAs($this->agent)->getJson('/notifications/latest')->assertJsonPath('unread', 1);

    $ulid = DB::table('user_notices')->value('ulid');
    $this->actingAs($this->agent)->postJson("/notifications/read/{$ulid}")->assertJsonPath('unread', 0);

    // Snoozing hides it and brings it back as unread later.
    $this->actingAs($this->agent)->post("/notifications/{$ulid}/snooze", ['hours' => 24])->assertSessionHasNoErrors();
    expect(app(Notices::class)->latest($this->agent))->toBe([]);
    $this->travel(25)->hours();
    expect(app(Notices::class)->unread($this->agent))->toBe(1);

    // Essential messages always go by email and stay out of the inbox.
    $this->agent->notify(new SignInCodeMail);
    expect(emailsTo($this->agent, SignInCodeMail::class))->toBe(1)->and(DB::table('user_notices')->count())->toBe(1);
});

it('honours each person\'s choices: email off, quiet hours, the digest, and urgent alerts', function (): void {
    $this->actingAs($this->agent)->post('/notifications/preferences', ['type' => 'new_lead_arrived', 'in_app' => true, 'email' => false])->assertSessionHasNoErrors();
    $this->agent->notify(new NewLeadArrivedMail);
    expect(emailsTo($this->agent, NewLeadArrivedMail::class))->toBe(0)->and(DB::table('user_notices')->count())->toBe(1);

    // Quiet hours 9pm to 7am Chicago: held, then sent once they end.
    app(Notices::class)->setPref($this->agent, 'new_lead_arrived', true, true);
    $this->actingAs($this->agent)->post('/notifications/settings', ['quiet_start' => 21, 'quiet_end' => 7, 'digest' => false])->assertSessionHasNoErrors();
    $this->travelTo(now('America/Chicago')->setTime(23, 0));
    $this->agent->notify(new NewLeadArrivedMail);
    expect(emailsTo($this->agent, NewLeadArrivedMail::class))->toBe(0)->and(DB::table('user_notices')->where('email_pending', true)->count())->toBe(1);
    $this->artisan('core:notice-digests')->assertSuccessful();
    expect(emailsTo($this->agent, NoticeDigestMail::class))->toBe(0);

    // Urgent alerts (access changes) come through quiet hours.
    app(Notices::class)->saveSettings($this->owner, 21, 7, false);
    app(Roles::class)->all();
    app(Roles::class)->assign($this->staff, Role::query()->where('template_key', 'bookkeeper')->firstOrFail(), User::factory()->owner()->create());
    expect(emailsTo($this->owner, AccessAlertMail::class))->toBe(1)
        ->and(DB::table('user_notices')->where('user_id', $this->owner->id)->where('urgent', true)->count())->toBe(1);
    app(Notices::class)->saveSettings($this->owner, null, null, false);

    $this->travelTo(now('America/Chicago')->addDay()->setTime(8, 0));
    $this->artisan('core:notice-digests')->assertSuccessful();
    expect(emailsTo($this->agent, NoticeDigestMail::class))->toBe(1)->and(DB::table('user_notices')->where('email_pending', true)->count())->toBe(0);

    // The daily digest: one email at 7am.
    app(Notices::class)->saveSettings($this->owner, null, null, true);
    $this->travelTo(now('America/Chicago')->setTime(15, 0));
    $this->owner->notify(new NewLeadArrivedMail);
    $this->owner->notify(new NewLeadArrivedMail);
    expect(emailsTo($this->owner, NewLeadArrivedMail::class))->toBe(0);
    $this->travelTo(now('America/Chicago')->addDay()->setTime(7, 5));
    $this->artisan('core:notice-digests')->assertSuccessful();
    $this->artisan('core:notice-digests')->assertSuccessful();
    expect(emailsTo($this->owner, NoticeDigestMail::class))->toBe(1);
});

it('suggests turning off alerts someone never opens', function (): void {
    foreach (range(1, 9) as $i) {
        $this->agent->notify(new NewLeadArrivedMail);
    }
    $this->actingAs($this->agent)->get('/notifications')->assertInertia(fn (Assert $page) => $page
        ->where('noisy.0.type', 'new_lead_arrived')->where('noisy.0.sent', 9)->where('noisy.0.opened', 0));
});

it('lets owners add custom fields and checks values by type', function (): void {
    $this->actingAs($this->agent)->get('/settings/custom-fields')->assertForbidden();
    $this->actingAs($this->owner)->post('/settings/custom-fields/contact', ['label' => 'Pre-approved', 'type' => 'yesno'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/settings/custom-fields/contact', ['label' => 'Lender', 'type' => 'choice', 'options' => ['Chase', 'Wells Fargo']])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/settings/custom-fields/contact', ['label' => 'Budget', 'type' => 'money', 'hidden_from' => 'commission'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post('/settings/custom-fields/contact', ['label' => 'Bad', 'type' => 'choice', 'options' => ['Only one']])->assertSessionHasErrors('options');
    $this->actingAs($this->owner)->get('/settings/custom-fields')->assertInertia(fn (Assert $page) => $page
        ->component('settings/custom-fields')->where('fields.contact.0.label', 'Pre-approved')->where('fields.contact.1.options', ['Chase', 'Wells Fargo']));

    $ann = app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'ann@x.test'], $this->owner);
    $this->actingAs($this->owner)->post("/contacts/{$ann->ulid}/fields", ['fields' => ['pre_approved' => 'yes', 'lender' => 'Citi']])->assertSessionHasErrors('fields.lender');
    $this->actingAs($this->owner)->post("/contacts/{$ann->ulid}/fields", ['fields' => ['pre_approved' => true, 'lender' => 'Chase', 'budget' => '450,000']])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get("/contacts/{$ann->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('customFields.0.value', true)->where('customFields.1.value', 'Chase')->where('customFields.2.value', '450000.00'));

    // A role without commission access can't see or change the hidden field.
    app(Roles::class)->all();
    app(Roles::class)->assign($this->staff, Role::query()->where('template_key', 'isa')->firstOrFail(), $this->owner);
    $this->actingAs($this->staff->fresh())->get("/contacts/{$ann->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('customFields.2.hidden', true)->where('customFields.2.value', null));
    expect(fn () => app(CustomFields::class)->save('contact', $ann->id, ['budget' => '1'], $this->staff->fresh()))->toThrow(ValidationException::class);

    // Smart lists filter on them.
    $bob = app(ManageContacts::class)->create(['first_name' => 'Bob', 'email' => 'bob@x.test'], $this->owner);
    app(CustomFields::class)->save('contact', $bob->id, ['pre_approved' => 'no'], $this->owner);
    $lists = app(SmartLists::class);
    $filters = $lists->validate(['custom' => ['pre_approved' => true]]);
    expect($lists->apply(Contact::query(), $filters)->pluck('first_name')->all())->toBe(['Ann'])
        ->and(fn () => $lists->validate(['custom' => ['nope' => 'x']]))->toThrow(ValidationException::class);
});

it('holds a deal from closing until required fields are filled', function (): void {
    app(CustomFields::class)->define('deal', ['label' => 'Lender', 'type' => 'text', 'required_to_close' => true], $this->owner);
    $deal = app(Deals::class)->create(['type' => 'buyer', 'street' => '12 Oak St', 'state_code' => 'TX'], $this->agent, $this->owner);
    expect(collect(app(Deals::class)->missing($deal))->firstWhere('label', 'Lender'))->toBe(['label' => 'Lender', 'status' => 'field_missing']);
    $this->actingAs($this->agent)->post("/deals/{$deal->ulid}/fields", ['fields' => ['lender' => 'Chase']])->assertSessionHasNoErrors();
    expect(collect(app(Deals::class)->missing($deal))->firstWhere('label', 'Lender'))->toBeNull();
    $this->actingAs($this->agent)->get("/deals/{$deal->ulid}")->assertInertia(fn (Assert $page) => $page->where('customFields.0.value', 'Chase'));
});

it('brings custom fields in from CSV files and other CRMs', function (): void {
    app(CustomFields::class)->define('contact', ['label' => 'Lender', 'type' => 'text'], $this->owner);
    $csv = "First Name,Email,Lender\nAnn,ann@x.test,Chase\n";
    $this->actingAs($this->owner)->post('/contacts/imports', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv)]);
    $import = ContactImport::query()->sole();
    $this->actingAs($this->owner)->get("/contact-imports/{$import->ulid}")->assertInertia(fn (Assert $page) => $page
        ->where('guess.Lender', 'custom:lender')->where('customFields.0.label', 'Lender'));
    $this->actingAs($this->owner)->post("/contact-imports/{$import->ulid}/run", ['mapping' => ['First Name' => 'first_name', 'Email' => 'email', 'Lender' => 'custom:lender']])->assertRedirect();
    $ann = Contact::query()->where('first_name', 'Ann')->sole();
    expect(app(CustomFields::class)->raw('contact', $ann->id))->toBe(['lender' => 'Chase']);

    // From a connected CRM: unknown fields become text fields so nothing is lost.
    $connection = Connection::query()->create(['app_key' => 'follow_up_boss', 'status' => 'connected', 'credentials' => ['api_key' => 'x'], 'settings' => [], 'connected_by' => $this->owner->id])->data();
    app(Importer::class)->person($connection, $this->owner, ['id' => 'p1', 'first_name' => 'Cal', 'last_name' => 'Ng', 'email' => 'cal@x.test', 'phone' => null,
        'tags' => [], 'stage' => null, 'source' => null, 'agent_id' => null, 'custom' => ['Closing Anniversary' => '2021-05-01', 'Lender' => 'Wells']], 'Follow Up Boss', 'FUB stage');
    $cal = Contact::query()->where('first_name', 'Cal')->sole();
    expect(app(CustomFields::class)->raw('contact', $cal->id))->toEqual(['lender' => 'Wells', 'closing_anniversary' => '2021-05-01'])
        ->and(collect(app(CustomFields::class)->definitions('contact'))->firstWhere('key', 'closing_anniversary')['help'])->toBe('Brought over by an import.');
});

it('suggests a field value from the notes, saved only once confirmed', function (): void {
    $model = AiModel::factory()->create(['capabilities' => ['chat']]);
    AiFeatureRoute::query()->create(['feature_key' => FieldSuggestions::FEATURE, 'primary_model_id' => $model->id, 'fallback_model_ids' => [], 'credit_action_key' => 'ai.x']);
    $ai = new FakeAiProvider;
    app(ProviderRegistry::class)->fake($model->provider->key, $ai);
    app(CustomFields::class)->define('contact', ['label' => 'Lender', 'type' => 'choice', 'options' => ['Chase', 'Wells Fargo']], $this->owner);
    $ann = app(ManageContacts::class)->create(['first_name' => 'Ann', 'email' => 'ann@x.test'], $this->agent);
    app(Timeline::class)->log($ann, ['type' => 'note', 'body' => 'Pre-approved with Chase for 450k'], $this->agent);

    $ai->respond(json_encode(['value' => 'Chase', 'evidence' => 'Pre-approved with Chase']));
    $this->actingAs($this->agent)->postJson("/contacts/{$ann->ulid}/fields/suggest", ['key' => 'lender'])->assertOk()
        ->assertJson(['value' => 'Chase', 'evidence' => 'Pre-approved with Chase']);
    expect(app(CustomFields::class)->raw('contact', $ann->id))->toBe([]);

    $ai->respond(json_encode(['value' => 'Citibank', 'evidence' => 'x']));
    $this->actingAs($this->agent)->postJson("/contacts/{$ann->ulid}/fields/suggest", ['key' => 'lender'])->assertJsonPath('value', null);
});
