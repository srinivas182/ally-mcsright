<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Actions\ManageContacts;
use Modules\Crm\Booking\Availability;
use Modules\Crm\Models\Activity;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\Task;
use Modules\Sites\SiteSetup;

require_once __DIR__.'/../Contracts/contract-helpers.php';

/* MK1 (specs/crm/20): booking links, video messages, the call queue, texting registration. */

beforeEach(function (): void {
    Storage::fake('local');
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-06-01 14:00', 'UTC')); // Monday, 9am in Texas, 7am in California
    $this->tenant = $this->onTenantHost('acme');
    $this->tenant->forceFill(['timezone' => 'America/Chicago'])->save();
    $this->owner = User::factory()->owner()->create();
    $this->agent = User::factory()->create(['first_name' => 'Pat', 'last_name' => 'Agent', 'phone' => '+15125550123']);
    $this->web = 'http://acme.rightally.site';
    $sites = app(SiteSetup::class);
    $site = $sites->create('modern', ['phone' => '(512) 555-0100'], $this->owner);
    $sites->publish($site, true, $this->owner);
});

it('gives agents a booking link that books a time and a lead', function (): void {
    assertPageContract($this->actingAs($this->agent)->get('/outreach'), 'outreach/index');
    assertPageContract($this->actingAs($this->agent)->get('/outreach/booking'), 'outreach/booking');
    $this->actingAs($this->agent)->post('/outreach/booking', ['slug' => 'pat', 'title' => 'Book a call with Pat', 'kind' => 'call', 'active' => true])
        ->assertSessionHasErrors('active'); // hours first
    app(Availability::class)->save($this->agent, true, Availability::DEFAULT_HOURS, 30, 2, $this->agent);
    $this->actingAs($this->agent)->post('/outreach/booking', ['slug' => 'Pat Agent', 'title' => 'Book a call with Pat', 'kind' => 'call', 'active' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/outreach/booking')->assertInertia(fn (Assert $p) => $p->where('link.slug', 'pat-agent')->where('link.url', fn ($u) => str_ends_with((string) $u, '/meet/pat-agent')));

    $page = $this->get($this->web.'/meet/pat-agent')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $html = (string) $page->getContent();
    expect($html)->toContain('Book a call with Pat')->toContain('name="slot"');
    preg_match('/name="slot" value="([^"]+)"/', $html, $m);
    $slot = $m[1];
    expect($slot)->toBe('2026-06-01T16:00Z'); // 11am Texas: 2 hours' notice

    $form = ['slot' => $slot, 'name' => 'Lee Buyer', 'email' => 'lee@x.test', 'phone' => '5125550111', 'notes' => 'First home', 't' => Crypt::encryptString((string) (time() - 30))];
    $this->post($this->web.'/_meet/pat-agent', $form)->assertRedirect();
    $lead = Lead::query()->sole();
    expect($lead->assigned_user_id)->toBe($this->agent->id);
    $task = Task::query()->sole();
    expect($task->assigned_to)->toBe($this->agent->id)->and(CarbonImmutable::parse($task->due_at)->utc()->format('Y-m-d H:i'))->toBe('2026-06-01 16:00');
    // The same time cannot be booked twice.
    $again = $this->post($this->web.'/_meet/pat-agent', [...$form, 'email' => 'kim@x.test'])->headers->get('Location');
    expect((string) $again)->toContain('error=');
    $this->get($this->web.'/meet/nobody')->assertNotFound();
});

it('records and shares video messages privately and tells the agent when one is watched', function (): void {
    $jane = app(ManageContacts::class)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@x.test', 'phone' => '5125550199'], $this->agent);
    assertPageContract($this->actingAs($this->agent)->get('/outreach/videos?contact='.$jane->ulid), 'outreach/videos');
    $webm = UploadedFile::fake()->createWithContent('video.webm', "\x1A\x45\xDF\xA3".str_repeat('x', 2000));
    $this->actingAs($this->agent)->post('/outreach/videos', ['video' => $webm, 'title' => 'Your search', 'contact' => $jane->ulid])->assertSessionHasNoErrors();
    $fake = UploadedFile::fake()->createWithContent('video.mp4', 'not a video');
    $this->actingAs($this->agent)->post('/outreach/videos', ['video' => $fake, 'title' => 'Bad'])->assertSessionHasErrors('video');

    $token = (string) DB::table('outreach_videos')->value('token');
    $html = (string) $this->get($this->web.'/v/'.$token)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($html)->toContain('Your search')->toContain('/v/'.$token.'/file')->toContain('tel:+15125550123');
    $this->get($this->web.'/v/'.$token.'/file')->assertOk()->assertHeader('Content-Type', 'video/webm');
    $this->get($this->web.'/v/'.$token);
    expect(DB::table('outreach_videos')->value('views'))->toBe(2)
        ->and(Activity::query()->where('contact_id', $jane->id)->where('type', 'system')->where('body', 'like', 'Watched your video%')->count())->toBe(1);
    $this->get($this->web.'/v/'.str_repeat('a', 48))->assertNotFound();

    $this->actingAs($this->agent)->get('/outreach/videos')->assertInertia(fn (Assert $p) => $p->where('videos.0.views', 2)->where('videos.0.contact', 'Jane Doe'));
    $ulid = (string) DB::table('outreach_videos')->value('ulid');
    $this->actingAs($this->owner)->delete('/outreach/videos/'.$ulid)->assertNotFound(); // only the agent's own
    $this->actingAs($this->agent)->delete('/outreach/videos/'.$ulid)->assertRedirect();
    expect(DB::table('outreach_videos')->count())->toBe(0);
});

it('builds a call queue that skips Do Not Call and calling hours, and logs each call', function (): void {
    $m = app(ManageContacts::class);
    $tx = $m->create(['first_name' => 'Tex', 'last_name' => 'Ready', 'phone' => '5125550101', 'state' => 'TX'], $this->agent);
    $m->create(['first_name' => 'Cal', 'last_name' => 'Early', 'phone' => '4155550101'], $this->agent)->forceFill(['state' => 'CA'])->save();
    $tx->forceFill(['state' => 'TX'])->save();
    $dnc = $m->create(['first_name' => 'Dee', 'last_name' => 'Nocall', 'phone' => '5125550102', 'state' => 'TX'], $this->agent);
    $dnc->forceFill(['do_not_call' => true])->save();
    $m->create(['first_name' => 'Nora', 'last_name' => 'Nophone', 'email' => 'nora@x.test'], $this->agent);

    $this->actingAs($this->agent)->get('/outreach/calls?list=new-leads')->assertOk()->assertInertia(fn (Assert $p) => $p->component('outreach/calls')
        ->where('queue.ready', fn ($r) => count($r) === 1 && $r[0]['name'] === 'Tex Ready')
        ->where('queue.skipped.do_not_call', 1)->where('queue.skipped.quiet_hours', 1)->where('queue.skipped.no_phone', 1));

    $this->actingAs($this->agent)->post('/outreach/calls/'.$tx->ulid, ['outcome' => 'left_voicemail', 'note' => 'Call back Tuesday'])->assertSessionHasNoErrors();
    expect(Activity::query()->where('contact_id', $tx->id)->where('type', 'call')->value('outcome'))->toBe('left_voicemail');
    $this->actingAs($this->agent)->get('/outreach/calls?list=new-leads')->assertInertia(fn (Assert $p) => $p->where('queue.skipped.called_today', 1)->where('queue.ready', []));

    $this->actingAs($this->agent)->post('/outreach/calls/'.$tx->ulid, ['outcome' => 'do_not_call'])->assertSessionHasNoErrors();
    expect(Contact::query()->find($tx->id)?->do_not_call)->toBeTrue();
    $this->actingAs($this->agent)->post('/outreach/calls/'.$tx->ulid, ['outcome' => 'robocall'])->assertSessionHasErrors('outcome');
});

it('guides the brokerage through texting registration and lets HQ record the outcome', function (): void {
    $this->actingAs($this->agent)->get('/settings/texting')->assertForbidden();
    assertPageContract($this->actingAs($this->owner)->get('/settings/texting'), 'outreach/texting');
    $this->actingAs($this->owner)->get('/settings/texting')->assertInertia(fn (Assert $p) => $p->where('registration.status', 'draft')
        ->where('registration.campaign.sample_1', fn ($s) => str_contains((string) $s, 'Reply STOP')));

    $this->actingAs($this->owner)->post('/settings/texting', ['submit' => true, 'legal_name' => 'Acme Realty LLC'])->assertSessionHasErrors(['business_type', 'ein', 'website', 'state']);
    $this->actingAs($this->owner)->post('/settings/texting', ['submit' => true, 'legal_name' => 'Acme Realty LLC', 'business_type' => 'llc', 'ein' => '12-3456789',
        'website' => 'https://acme.example', 'street' => '1 Main St', 'city' => 'Austin', 'state' => 'tx', 'zip' => '78701', 'contact_name' => 'Olive Owner',
        'contact_email' => 'olive@acme.test'])->assertSessionHasNoErrors();
    $row = DB::connection(Connections::control())->table('outreach_texting_registrations')->sole();
    expect($row->status)->toBe('submitted')->and((string) $row->brand)->not->toContain('123456789')
        ->and(json_decode(Crypt::decryptString((string) $row->brand), true)['ein'])->toBe('123456789');
    $this->actingAs($this->owner)->get('/settings/texting')->assertInertia(fn (Assert $p) => $p->where('registration.brand.ein', '•••••6789'));

    // HQ records the carriers' decision.
    config(['core.hq_host' => 'app.rightally.io']);
    $this->artisan('tenants:bootstrap-hq')->assertSuccessful();
    $admin = app(TenantContext::class)->runAs(Tenant::query()->where('type', 'hq')->sole(), fn () => User::factory()->hqStaff()->create());
    $this->actingAs($admin)->get('http://app.rightally.io/hq/texting')->assertOk()->assertInertia(fn (Assert $p) => $p->component('hq/texting')
        ->where('registrations.0.brokerage', $this->tenant->name)->where('registrations.0.brand.legal_name', 'Acme Realty LLC'));
    $this->actingAs($admin)->post('http://app.rightally.io/hq/texting/'.$this->tenant->id, ['status' => 'approved'])->assertSessionHasNoErrors();
    expect(DB::connection(Connections::control())->table('outreach_texting_registrations')->value('status'))->toBe('approved');
    $this->actingAs($this->owner)->get($this->web.'/hq/texting')->assertNotFound();
});
