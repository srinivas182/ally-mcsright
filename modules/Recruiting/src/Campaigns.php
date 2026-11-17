<?php

declare(strict_types=1);

namespace Modules\Recruiting;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Events\SmsKeywordReceived;
use Modules\Crm\Leads\SpeedToLead;
use Modules\Recruiting\Models\Campaign;
use Modules\Recruiting\Models\Enrollment;
use Modules\Recruiting\Models\Recruit;
use Modules\Recruiting\Models\RecruitNote;
use Modules\Sites\Models\Site;

/**
 * Recruiting campaigns (RC2, specs/grow/17): a few emails and texts on set days, one recruit at a time, started by the
 * recruiter. Emails always carry the brokerage's address and a one-click unsubscribe. Texts go only to recruits who
 * agreed to them in writing and haven't replied STOP, between 8am and 9pm the brokerage's time. A campaign stops when
 * the recruit leaves the open stages (joined, not now).
 */
final class Campaigns
{
    public const MAX_STEPS = 8;

    /** Ready-made campaigns, added the first time recruiting campaigns are opened. */
    public const TEMPLATES = [
        'Introduce us' => [
            ['day' => 0, 'channel' => 'email', 'subject' => 'A quick hello from {brokerage}',
                'body' => "Hi {first_name},\n\nI'm {recruiter} at {brokerage}. I've seen your work in the market and wanted to say hello.\n\nNo pitch today: if you're ever curious what you'd keep here, I can show you with your own last 12 months of deals. It takes ten minutes.\n\n{recruiter}"],
            ['day' => 5, 'channel' => 'email', 'subject' => 'What you would keep here',
                'body' => "Hi {first_name},\n\nMost agents who look at us want one answer: would I keep more? We work that out from your real closings, not a brochure.\n\nYou can try it yourself here: {careers}\n\nOr just reply and I'll do it for you.\n\n{recruiter}"],
            ['day' => 14, 'channel' => 'email', 'subject' => 'Coffee?',
                'body' => "Hi {first_name},\n\nIf a coffee would help, I'm happy to meet whenever suits you. If now isn't the time, no problem at all.\n\n{recruiter}"],
        ],
        'After they apply' => [
            ['day' => 0, 'channel' => 'email', 'subject' => 'Thanks for getting in touch, {first_name}',
                'body' => "Hi {first_name},\n\nThank you for your interest in {brokerage}. I'll call you within one business day to find a time to talk.\n\n{recruiter}"],
            ['day' => 1, 'channel' => 'text', 'subject' => null,
                'body' => 'Hi {first_name}, it\'s {recruiter} from {brokerage}. When is a good time for a 15-minute call this week?'],
        ],
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    public function seedTemplates(): void
    {
        if (Campaign::query()->exists()) {
            return;
        }
        foreach (self::TEMPLATES as $name => $steps) {
            Campaign::query()->create(['name' => $name, 'steps' => $steps, 'status' => 'active']);
        }
    }

    /**
     * @param  array<string, mixed>  $in  name, steps[]
     */
    public function save(?Campaign $campaign, array $in, User $by): Campaign
    {
        $name = trim((string) ($in['name'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Give the campaign a name (up to 120 characters).';
        }
        $steps = [];
        $last = -1;
        foreach (array_values((array) ($in['steps'] ?? [])) as $i => $s) {
            $s = (array) $s;
            $day = (int) ($s['day'] ?? 0);
            $channel = in_array($s['channel'] ?? '', ['email', 'text'], true) ? (string) $s['channel'] : 'email';
            $subject = trim((string) ($s['subject'] ?? ''));
            $body = trim((string) ($s['body'] ?? ''));
            $n = $i + 1;
            if ($day < 0 || $day > 120 || $day < $last) {
                $errors["steps.{$i}.day"] = "Step {$n}: days must be 0 to 120 and in order.";
            }
            if ($channel === 'email' && ($subject === '' || mb_strlen($subject) > 120)) {
                $errors["steps.{$i}.subject"] = "Step {$n}: an email needs a subject (up to 120 characters).";
            }
            if ($body === '' || mb_strlen($body) > ($channel === 'text' ? 300 : 3000)) {
                $errors["steps.{$i}.body"] = "Step {$n}: write the message (texts up to 300 characters).";
            }
            $last = $day;
            $steps[] = ['day' => $day, 'channel' => $channel, 'subject' => $channel === 'email' ? $subject : null, 'body' => $body];
        }
        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            $errors['steps'] = 'Between 1 and '.self::MAX_STEPS.' steps.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $campaign ??= new Campaign(['created_by' => $by->id, 'status' => 'active']);
        $campaign->fill(['name' => $name, 'steps' => $steps])->save();
        app(AuditLogger::class)->record('recruiting.campaign.saved', $campaign, metadata: ['steps' => count($steps)]);

        return $campaign;
    }

    public function setStatus(Campaign $campaign, bool $active): void
    {
        $campaign->fill(['status' => $active ? 'active' : 'paused'])->save();
    }

    public function start(Recruit $recruit, Campaign $campaign, ?User $by): Enrollment
    {
        if (! in_array($recruit->stage, Recruit::OPEN, true)) {
            throw ValidationException::withMessages(['campaign' => 'Campaigns are for people you are still recruiting.']);
        }
        $channels = array_unique(array_column($campaign->steps, 'channel'));
        $reachable = (in_array('email', $channels, true) && $recruit->email !== null && $recruit->email_opt_out_at === null) || (in_array('text', $channels, true) && $recruit->textable());
        if (! $reachable) {
            throw ValidationException::withMessages(['campaign' => 'Add their email first (texts need their written agreement).']);
        }
        $existing = Enrollment::query()->where('campaign_id', $campaign->id)->where('recruit_id', $recruit->id)->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['campaign' => 'They have already had this campaign.']);
        }
        $enrollment = Enrollment::query()->create(['campaign_id' => $campaign->id, 'recruit_id' => $recruit->id, 'next_step' => 0, 'status' => 'active',
            'next_at' => CarbonImmutable::now()->addDays((int) $campaign->steps[0]['day']), 'started_by' => $by?->id]);
        RecruitNote::query()->create(['recruit_id' => $recruit->id, 'user_id' => $by?->id, 'kind' => 'stage', 'body' => "Started the campaign \"{$campaign->name}\".", 'occurred_at' => now()]);
        app(AuditLogger::class)->record('recruiting.campaign.started', $recruit, metadata: ['campaign' => $campaign->id]);

        return $enrollment;
    }

    public function stop(Enrollment $enrollment, string $reason): void
    {
        if ($enrollment->status === 'active') {
            $enrollment->fill(['status' => 'stopped', 'stopped_reason' => mb_substr($reason, 0, 120), 'next_at' => null])->save();
        }
    }

    /**
     * Every few minutes: send the steps that are due.
     *
     * @return int messages sent
     */
    public function sendDue(): int
    {
        $sent = 0;
        $now = CarbonImmutable::now();
        $local = $now->setTimezone($this->tenants->current()->timezone ?: 'America/Chicago');
        $daytime = $local->hour >= 8 && $local->hour < 21;
        foreach (Enrollment::query()->with(['campaign', 'recruit'])->where('status', 'active')->where('next_at', '<=', $now)->orderBy('next_at')->limit(500)->get() as $e) {
            $recruit = $e->recruit;
            if (! in_array($recruit->stage, Recruit::OPEN, true)) {
                $this->stop($e, 'No longer being recruited ('.Recruit::STAGES[$recruit->stage].').');

                continue;
            }
            if ($e->campaign->status !== 'active') {
                continue; // paused: picks up where it left off when started again
            }
            $step = $e->campaign->steps[$e->next_step] ?? null;
            if ($step === null) {
                $e->fill(['status' => 'finished', 'next_at' => null])->save();

                continue;
            }
            if ($step['channel'] === 'text' && $recruit->textable() && ! $daytime) {
                continue; // waits for 8am
            }
            if ($this->deliver($recruit, $step)) {
                $sent++;
            }
            $next = $e->next_step + 1;
            $after = $e->campaign->steps[$next] ?? null;
            $e->fill($after === null ? ['next_step' => $next, 'status' => 'finished', 'next_at' => null]
                : ['next_step' => $next, 'next_at' => max($now, $e->created_at->addDays((int) $after['day']))])->save();
        }

        return $sent;
    }

    /** One-click unsubscribe from recruiting emails. */
    public function unsubscribe(Recruit $recruit): void
    {
        if ($recruit->email_opt_out_at === null) {
            $recruit->fill(['email_opt_out_at' => now()])->save();
            RecruitNote::query()->create(['recruit_id' => $recruit->id, 'kind' => 'stage', 'body' => 'Asked to stop recruiting emails.', 'occurred_at' => now()]);
        }
    }

    /** STOP and START by text, from anyone we may have texted. */
    public function keyword(SmsKeywordReceived $event): void
    {
        foreach (Recruit::query()->where('phone', $event->from)->get() as $recruit) {
            if ($event->stop && $recruit->text_opt_out_at === null) {
                $recruit->fill(['text_opt_out_at' => now()])->save();
                RecruitNote::query()->create(['recruit_id' => $recruit->id, 'kind' => 'stage', 'body' => "Replied {$event->keyword}: no more texts.", 'occurred_at' => now()]);
            } elseif (! $event->stop && $recruit->text_opt_out_at !== null) {
                $recruit->fill(['text_opt_out_at' => null])->save();
                RecruitNote::query()->create(['recruit_id' => $recruit->id, 'kind' => 'stage', 'body' => "Replied {$event->keyword}: texts allowed again.", 'occurred_at' => now()]);
            }
        }
    }

    public function stopUrl(Recruit $recruit): string
    {
        return app(SpeedToLead::class)->link(URL::signedRoute('recruiting.unsubscribe', ['recruit' => $recruit->ulid], absolute: false));
    }

    /**
     * @param  array{day: int, channel: string, subject: string|null, body: string}  $step
     */
    private function deliver(Recruit $recruit, array $step): bool
    {
        $recruiter = $recruit->owner_user_id !== null ? User::query()->find($recruit->owner_user_id) : null;
        $brokerage = $this->tenants->current()->name;
        $fill = fn (string $text): string => strtr($text, ['{first_name}' => $recruit->firstName(), '{name}' => $recruit->name, '{brokerage}' => $brokerage,
            '{recruiter}' => $recruiter !== null ? $recruiter->name : $brokerage, '{careers}' => app(Careers::class)->publicUrl() ?? 'just reply to this email']);
        if ($step['channel'] === 'email') {
            if ($recruit->email === null || $recruit->email_opt_out_at !== null) {
                return false;
            }
            $site = Site::query()->where('kind', 'brokerage')->first();
            $i = $site !== null ? $site->info : [];
            $address = trim(implode(', ', array_filter([$i['street'] ?? '', $i['city'] ?? '', trim(($i['state'] ?? '').' '.($i['zip'] ?? ''))])));
            Mail::to($recruit->email, $recruit->name)->send(new RecruitingMail($fill((string) $step['subject']), $fill($step['body']), $recruiter !== null ? $recruiter->name : $brokerage,
                $recruiter?->email, $brokerage, $address !== '' ? $address : null, $this->stopUrl($recruit)));
            $this->log($recruit, 'email', 'Campaign email: '.$fill((string) $step['subject']));

            return true;
        }
        if (! $recruit->textable()) {
            return false;
        }
        $text = mb_substr($fill($step['body']), 0, 300).' Reply STOP to opt out.';
        app(SmsSender::class)->send((string) $recruit->phone, $text);
        $this->log($recruit, 'note', 'Campaign text: '.$text);

        return true;
    }

    private function log(Recruit $recruit, string $kind, string $body): void
    {
        RecruitNote::query()->create(['recruit_id' => $recruit->id, 'kind' => $kind, 'body' => $body, 'occurred_at' => now()]);
    }
}
