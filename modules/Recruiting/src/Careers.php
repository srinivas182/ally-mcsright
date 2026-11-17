<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Recruiting\Models\Campaign;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Models\RecruitNote;
use Modules\Recruiting\Models\Story;
use Modules\Sites\Models\Site;
use Modules\Sites\SiteSetup;
use Modules\Transactions\Models\Deal;

/**
 * The careers page (RC2, specs/grow/17): /careers on the brokerage's website, with why agents join, an "earn here"
 * calculator on the brokerage's real plan, agents' own words (only with their consent), and an application that
 * lands in the recruiting pipeline.
 */
final class Careers
{
    public const AUTO_CAMPAIGN = 'After they apply';

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return array{live: bool, headline: string, intro: string, reasons: list<string>, calculator: bool}
     */
    public function settings(): array
    {
        $raw = DB::connection(Connections::cell())->table('rec_settings')->where('tenant_id', $this->tenants->id())->value('careers');
        $c = is_string($raw) ? (array) json_decode($raw, true) : [];
        $name = $this->tenants->current()->name;

        return ['live' => (bool) ($c['live'] ?? false), 'headline' => (string) ($c['headline'] ?? "Build your business at {$name}"),
            'intro' => (string) ($c['intro'] ?? "{$name} gives agents the tools, support and split to grow. See what you would keep here with your own numbers, then talk to us."),
            'reasons' => array_values(array_map('strval', (array) ($c['reasons'] ?? ['One app for leads, deals, marketing and your money', 'A plan you can check deal by deal', 'A broker who answers the phone']))),
            'calculator' => (bool) ($c['calculator'] ?? true)];
    }

    /**
     * @param  array<string, mixed>  $in
     */
    public function save(array $in): void
    {
        $headline = trim((string) ($in['headline'] ?? ''));
        $intro = trim((string) ($in['intro'] ?? ''));
        $reasons = array_values(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 140), preg_split('/\R/', (string) ($in['reasons'] ?? '')) ?: []), fn (string $l): bool => $l !== ''));
        if ($headline === '' || mb_strlen($headline) > 90) {
            throw ValidationException::withMessages(['headline' => 'A headline of up to 90 characters.']);
        }
        if ($intro === '' || mb_strlen($intro) > 600) {
            throw ValidationException::withMessages(['intro' => 'An introduction of up to 600 characters.']);
        }
        $value = json_encode(['live' => (bool) ($in['live'] ?? false), 'headline' => $headline, 'intro' => $intro, 'reasons' => array_slice($reasons, 0, 8),
            'calculator' => (bool) ($in['calculator'] ?? true)]);
        DB::connection(Connections::cell())->table('rec_settings')->updateOrInsert(['tenant_id' => $this->tenants->id()], ['careers' => $value, 'updated_at' => now()]);
        app(AuditLogger::class)->record('recruiting.careers.saved', metadata: ['live' => (bool) ($in['live'] ?? false)]);
    }

    /** Whether /careers is on the website now: turned on, and the website itself is live. */
    public function live(): bool
    {
        return $this->settings()['live'] && Site::query()->where('kind', 'brokerage')->where('status', 'live')->exists();
    }

    public function publicUrl(): ?string
    {
        $host = $this->live() ? app(SiteSetup::class)->liveHost() : null;

        return $host !== null ? 'https://'.$host.'/careers' : null;
    }

    /**
     * Agents' own words, only theirs and only while they agree; their deals in the last 12 months if they said so.
     *
     * @return list<array{name: string, quote: string, deals: int|null}>
     */
    public function stories(): array
    {
        $stories = Story::query()->orderBy('consented_at')->get();
        $users = User::query()->whereIn('id', $stories->pluck('user_id')->all() ?: [0])->where('status', 'active')->get()->keyBy('id');

        return array_values($stories->filter(fn (Story $s): bool => $users->has($s->user_id))->map(fn (Story $s): array => ['name' => (string) $users->get($s->user_id)?->name,
            'quote' => $s->quote, 'deals' => $s->show_deals ? Deal::query()->where('agent_user_id', $s->user_id)->where('status', 'closed')
                ->where('closed_on', '>=', CarbonImmutable::today()->subYear()->toDateString())->count() : null])->all());
    }

    public function story(User $user): ?Story
    {
        return Story::query()->where('user_id', $user->id)->first();
    }

    public function saveStory(User $user, string $quote, bool $showDeals): void
    {
        $quote = trim(strip_tags($quote));
        if (mb_strlen($quote) < 20 || mb_strlen($quote) > 600) {
            throw ValidationException::withMessages(['quote' => 'Between 20 and 600 characters, in your own words.']);
        }
        Story::query()->updateOrCreate(['user_id' => $user->id], ['quote' => $quote, 'show_deals' => $showDeals, 'consented_at' => now()]);
        app(AuditLogger::class)->record('recruiting.story.consented', metadata: ['show_deals' => $showDeals]);
    }

    public function removeStory(User $user): void
    {
        Story::query()->where('user_id', $user->id)->delete();
        app(AuditLogger::class)->record('recruiting.story.withdrawn');
    }

    /**
     * An application from the careers page: a recruit (or an update to one with the same email), an alert for the
     * recruiters, and the "After they apply" campaign when it is on.
     *
     * @param  array{name: string, email: string, phone: string|null, licence: string|null, brokerage: string|null, deals: int|null, message: string|null, text_consent: bool}  $a
     */
    public function apply(array $a): Recruit
    {
        $recruit = Recruit::query()->where('email', $a['email'])->first();
        $application = array_filter(['licence' => $a['licence'], 'deals_a_year' => $a['deals'], 'message' => $a['message'], 'applied_on' => CarbonImmutable::today()->toDateString()],
            fn ($v): bool => $v !== null && $v !== '');
        $consent = $a['text_consent'] && $a['phone'] !== null ? ['text_consent_at' => now(), 'text_consent_wording' => self::textConsentWording($this->tenants->current()->name),
            'text_opt_out_at' => null] : [];
        $new = $recruit === null;
        if ($recruit === null) {
            $recruit = Recruit::query()->create(['name' => $a['name'], 'email' => $a['email'], 'phone' => $a['phone'], 'brokerage' => $a['brokerage'], 'source' => 'career_site',
                'stage' => 'prospect', 'stage_changed_on' => CarbonImmutable::today(), 'next_step' => 'Call them back', 'next_step_on' => CarbonImmutable::today(), 'application' => $application] + $consent);
        } else {
            $recruit->fill(['phone' => $a['phone'] ?? $recruit->phone, 'application' => $application, 'next_step' => 'Call them back', 'next_step_on' => CarbonImmutable::today()] + $consent
                + (in_array($recruit->stage, Recruit::OPEN, true) ? [] : ['stage' => 'talking', 'stage_changed_on' => CarbonImmutable::today()]))->save();
        }
        $words = 'Applied on the careers page'.($a['deals'] !== null ? " ({$a['deals']} deals a year)" : '').'.'.($a['message'] ? "\n".$a['message'] : '');
        RecruitNote::query()->create(['recruit_id' => $recruit->id, 'kind' => 'note', 'body' => $words, 'occurred_at' => now()]);
        DB::connection(Connections::cell())->table('rec_alerts')->insert(['tenant_id' => $this->tenants->id(), 'recruit_id' => $recruit->id, 'kind' => 'applied',
            'message' => "{$recruit->name} applied on your careers page.", 'created_at' => now(), 'updated_at' => now()]);
        $campaign = Campaign::query()->where('name', self::AUTO_CAMPAIGN)->where('status', 'active')->first();
        if ($new && $campaign !== null) {
            try {
                app(Campaigns::class)->start($recruit, $campaign, null);
            } catch (ValidationException) {
                // nothing they can be reached by
            }
        }
        app(AuditLogger::class)->asAutomation('recruiting.careers', null, null, fn () => app(AuditLogger::class)->record('recruiting.recruit.applied', $recruit,
            metadata: ['new' => $new, 'text_consent' => $consent !== []]), 'visitor');

        return $recruit;
    }

    public static function textConsentWording(string $brokerage): string
    {
        return "I agree to receive calls and text messages, including automated messages, from {$brokerage} about working there, at this number. Consent is not a condition of anything. Message and data rates may apply. Reply STOP to opt out.";
    }
}
