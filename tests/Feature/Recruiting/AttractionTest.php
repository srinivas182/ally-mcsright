<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Compensation\Actions\ManagePlans;
use Modules\Compensation\Enums\PlanType;
use Modules\Core\Messaging\FakeSmsSender;
use Modules\Core\Messaging\SmsSender;
use Modules\Crm\Inbox\Inbox;
use Modules\Listings\Access\MlsAccess;
use Modules\Listings\Models\Listing;
use Modules\Listings\Models\MlsAuthorization;
use Modules\Listings\Models\MlsSource;
use Modules\Recruiting\AgentDatabase;
use Modules\Recruiting\Campaigns;
use Modules\Recruiting\Careers;
use Modules\Recruiting\Models\Campaign;
use Modules\Recruiting\Models\Enrollment;
use Modules\Recruiting\Models\MlsAgent;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\MoveScore;
use Modules\Recruiting\Pipeline;
use Modules\Recruiting\RecruitingMail;
use Modules\Sites\SiteSetup;

/* RC2: likely to move, recruiting campaigns, the careers page and agents' own words. */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 15:00', 'UTC')); // 10:00 in Austin
    Mail::fake();
    app()->instance(SmsSender::class, $this->sms = new FakeSmsSender);
    $this->tenant = $this->onTenantHost('acme');
    $this->owner = User::factory()->owner()->create(['first_name' => 'Olive', 'last_name' => 'Owner']);
    $this->agent = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'Agent']);
    $this->web = 'http://acme.rightally.site';
});

function attractionSite(object $t): void
{
    $sites = app(SiteSetup::class);
    $site = $sites->create('aurum', ['street' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'], $t->owner);
    $sites->publish($site, true, $t->owner);
    $plans = app(ManagePlans::class);
    $plan = $plans->create(PlanType::Commission, '85/15', ['split' => ['mode' => 'percentage', 'agent_percent' => '85']]);
    $plans->publish($plan->versions()->sole(), CarbonImmutable::parse('2026-01-01'));
    $plans->setDefault($plan->fresh());
}

it('scores how likely an agent is to move, always with the reasons', function (): void {
    $source = MlsSource::query()->create(['key' => 'actris', 'name' => 'ACTRIS', 'states' => ['TX'], 'provider' => 'mls_grid', 'base_url' => 'https://x.test', 'credentials' => ['token' => 't'], 'status' => 'active']);
    $auth = MlsAuthorization::query()->create(['tenant_id' => $this->tenant->id, 'mls_source_id' => $source->id, 'broker_mls_id' => 'B1', 'scopes' => ['idx'], 'status' => 'pending']);
    app(MlsAccess::class)->approve($auth, ['agent_production'], null, null, User::factory()->hqStaff()->make());
    $n = 0;
    $sale = function (string $agent, string $office, string $closed) use ($source, &$n): void {
        $n++;
        Listing::query()->create(['mls_source_id' => $source->id, 'listing_key' => "M{$n}", 'standard_status' => 'Closed', 'list_agent_mls_id' => $agent, 'list_agent_name' => "{$agent} Name",
            'list_office_mls_id' => $office, 'list_office_name' => "{$office} Realty", 'close_price_cents' => 40_000_000, 'close_date' => $closed,
            'listing_contract_date' => CarbonImmutable::parse($closed)->subDays(30)->toDateString(), 'modification_timestamp' => now()]);
    };
    foreach (['2025-01-10', '2025-02-10', '2025-03-10', '2025-04-10', '2025-05-10'] as $d) {
        $sale('SLOW', 'KW', $d); // 5 the year before
    }
    $sale('SLOW', 'KW', '2026-02-10'); // 1 this year
    app(AgentDatabase::class)->refresh();
    $slow = MlsAgent::query()->where('agent_mls_id', 'SLOW')->sole();
    expect($slow->move_score)->toBe(35)->and($slow->move_reasons)->toBe(['Closed 1 sides, down 80% on the year before.']);

    // Two agents left KW in the last 90 days, and SLOW has been there about a year.
    foreach (['X1', 'X2'] as $id) {
        MlsAgent::query()->create(['mls_source_id' => $source->id, 'agent_mls_id' => $id, 'office_mls_id' => 'EXP', 'previous_office_mls_id' => 'KW',
            'office_changed_on' => '2026-08-01', 'refreshed_at' => now()]);
    }
    $slow->forceFill(['office_changed_on' => '2025-09-05'])->save();
    [$score, $reasons] = app(MoveScore::class)->score($slow->fresh() ?? $slow, 2, CarbonImmutable::today());
    expect($score)->toBe(80)->and($reasons)->toContain('2 agents left KW Realty in the last 90 days.', 'About a year at KW Realty: often when agents review their plan.');
});

it('runs campaigns: emails with an unsubscribe link, texts only with consent and in the daytime, and stops when they join', function (): void {
    attractionSite($this);
    $c = app(Campaigns::class);
    $c->seedTemplates();
    $intro = Campaign::query()->where('name', 'Introduce us')->sole();
    $sam = app(Pipeline::class)->add(['name' => 'Sam Seller'], $this->owner);
    expect(fn () => $c->start($sam, $intro, $this->owner))->toThrow(ValidationException::class); // no email yet

    $sam->fill(['email' => 'sam@x.test'])->save();
    $this->actingAs($this->owner)->post("/recruiting/{$sam->ulid}/campaign", ['campaign' => $intro->ulid])->assertSessionHasNoErrors();
    expect($c->sendDue())->toBe(1);
    Mail::assertSent(RecruitingMail::class, function (RecruitingMail $m): bool {
        return $m->hasTo('sam@x.test') && $m->subjectLine === 'A quick hello from '.$this->tenant->name && str_contains($m->body, "Hi Sam,\n\nI'm Olive Owner at {$this->tenant->name}")
            && $m->postalAddress === '1 Main St, Austin, TX 78701' && str_contains($m->stopUrl, '/recruiting-stop/');
    });
    $this->travel(5)->days();
    expect($c->sendDue())->toBe(1);
    Mail::assertSent(RecruitingMail::class, fn (RecruitingMail $m): bool => str_contains($m->body, $this->web.'/careers') === false); // careers page not live yet

    // One click to unsubscribe: the rest of the emails are skipped.
    $stop = Mail::sent(RecruitingMail::class)->first()->stopUrl;
    $this->get($stop)->assertOk()->assertSee('Stop recruiting emails?')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->post($stop)->assertOk()->assertSee('unsubscribed');
    $this->post(str_replace('signature=', 'signature=x', $stop))->assertForbidden();
    $this->travel(9)->days();
    expect($c->sendDue())->toBe(0)->and(Enrollment::query()->sole()->status)->toBe('finished');

    // Texts: only with consent, between 8am and 9pm, and STOP stops them.
    $after = Campaign::query()->where('name', 'After they apply')->sole();
    $kim = Recruit::query()->create(['name' => 'Kim Lee', 'email' => 'kim@x.test', 'phone' => '+15125550111', 'stage' => 'talking', 'source' => 'manual', 'owner_user_id' => $this->owner->id]);
    $c->start($kim, $after, $this->owner);
    $c->sendDue(); // the email
    $this->travel(1)->days();
    expect($c->sendDue())->toBe(0)->and($this->sms->sent)->toBe([]); // no consent: the text is skipped
    $lee = Recruit::query()->create(['name' => 'Lee Ray', 'email' => 'lee@x.test', 'phone' => '+15125550122', 'stage' => 'talking', 'source' => 'manual',
        'text_consent_at' => now(), 'owner_user_id' => $this->owner->id]);
    $c->start($lee, $after, $this->owner);
    $c->sendDue();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 03:00', 'UTC')); // 10pm in Austin
    expect($c->sendDue())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-09-18 14:00', 'UTC')); // 9am
    expect($c->sendDue())->toBe(1)->and($this->sms->sent[0])->toBe(['to' => '+15125550122', 'message' => 'Hi Lee, it\'s Olive Owner from '.$this->tenant->name.'. When is a good time for a 15-minute call this week? Reply STOP to opt out.']);
    app(Inbox::class)->receiveSms('+15125550122', 'Stop', 'SM1');
    expect($lee->fresh()?->textable())->toBeFalse();

    // Joining stops a campaign.
    $c->start($lee->fresh() ?? $lee, $intro, $this->owner);
    app(Pipeline::class)->move($lee->fresh() ?? $lee, 'joined', $this->owner);
    $this->travel(1)->hours();
    $c->sendDue();
    expect(Enrollment::query()->where('recruit_id', $lee->id)->where('campaign_id', $intro->id)->sole()->stopped_reason)->toBe('No longer being recruited (Joined).');
});

it('publishes a careers page with the calculator, agents\' own words and an application that lands in recruiting', function (): void {
    attractionSite($this);
    $this->get($this->web.'/careers')->assertNotFound(); // off until the broker turns it on
    $this->actingAs($this->agent)->post('/recruiting/careers', ['live' => true])->assertForbidden();
    $this->actingAs($this->owner)->post('/recruiting/careers', ['live' => true, 'headline' => 'Grow with acme', 'intro' => 'Tools, support and a fair split.', 'reasons' => "Real support\nOne app", 'calculator' => true])
        ->assertSessionHasNoErrors();

    // Agents add their own words, with consent; the broker can't write them.
    $this->actingAs($this->agent)->post('/recruiting/my-story', ['quote' => 'The best support I have had in twenty years.', 'show_deals' => true])->assertSessionHasErrors('consent');
    $this->actingAs($this->agent)->post('/recruiting/my-story', ['quote' => 'The best support I have had in twenty years.', 'show_deals' => true, 'consent' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->agent)->get('/recruiting/my-story')->assertInertia(fn (Assert $p) => $p->component('recruiting/my-story')->where('story.show_deals', true));

    $html = (string) $this->get($this->web.'/careers')->assertOk()->assertHeader('Cache-Control')->getContent();
    expect($html)->toContain('Grow with acme')->toContain('The best support I have had in twenty years.')->toContain('Ann Agent')
        ->toContain('"@type":"JobPosting"')->toContain('"employmentType":"CONTRACTOR"')->toContain('"@type":"FAQPage"')->toContain('index, follow');
    expect((string) $this->get($this->web.'/sitemap.xml')->getContent())->toContain($this->web.'/careers')
        ->and((string) $this->get($this->web.'/llms.txt')->getContent())->toContain('[Careers]('.$this->web.'/careers)');

    // The calculator: 10 deals at $400,000 → $10,000 commission each; 85% here vs 70% there.
    $calc = (string) $this->get($this->web.'/careers?deals=10&price=400,000&split=70')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($calc)->toContain('$85,000</strong> a year here')->toContain('$15,000 more')->toContain('noindex');

    $form = ['name' => 'Pat Applicant', 'email' => 'Pat@X.test', 'phone' => '512-555-0199', 'deals' => 12, 'licence' => 'TX123', 'message' => 'Curious about the split.',
        'text_consent' => '1', 't' => Crypt::encryptString((string) (time() - 30))];
    $this->post($this->web.'/_careers', $form)->assertRedirect($this->web.'/careers?sent=1#apply');
    $pat = Recruit::query()->where('email', 'pat@x.test')->sole();
    expect($pat->only(['source', 'stage', 'phone']))->toBe(['source' => 'career_site', 'stage' => 'prospect', 'phone' => '+15125550199'])
        ->and($pat->textable())->toBeTrue()->and($pat->text_consent_wording)->toContain('about working there')
        ->and($pat->application)->toMatchArray(['licence' => 'TX123', 'deals_a_year' => 12])
        ->and(collect(app(Pipeline::class)->alerts())->pluck('message')->all())->toContain('Pat Applicant applied on your careers page.');
    $this->post($this->web.'/_careers', [...$form, 'website' => 'spam'])->assertRedirect($this->web.'/careers?sent=1#apply');
    expect(Recruit::query()->count())->toBe(1);

    $this->actingAs($this->agent)->delete('/recruiting/my-story');
    expect(app(Careers::class)->stories())->toBe([]);
    $this->actingAs($this->owner)->get('/recruiting/careers')->assertInertia(fn (Assert $p) => $p->component('recruiting/careers')->where('url', 'https://acme.rightally.site/careers'));
    $this->actingAs($this->owner)->get('/recruiting/campaigns')->assertInertia(fn (Assert $p) => $p->component('recruiting/campaigns')->has('campaigns', 2));
});
