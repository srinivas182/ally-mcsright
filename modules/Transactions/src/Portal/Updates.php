<?php

declare(strict_types=1);

namespace Modules\Transactions\Portal;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Core\Messaging\MessagingSettings;
use Modules\Core\Messaging\SmsSender;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Deadlines\Deadlines;
use Modules\Transactions\Models\Deal;
use Throwable;

/**
 * Updates to clients without anyone typing them (specs/ai/02, §22), each sent once and in the client's language:
 * - a milestone update when the deal reaches the next step (email; a text too for clients who opted in, 8am–9pm);
 * - a Monday summary while under contract: where things are, what is coming up, what is waiting on them;
 * - a check-in a week after closing, asking how it went (the answer goes to the agent).
 */
final class Updates
{
    public function __construct(private readonly TenantContext $tenants, private readonly MessagingSettings $messaging) {}

    /**
     * Runs for this brokerage (hourly).
     *
     * @return array{milestones: int, weekly: int, checkins: int, texts: int}
     */
    public function run(): array
    {
        $tenant = $this->tenants->current();
        $tz = $tenant->timezone !== '' ? $tenant->timezone : 'America/Chicago';
        $now = CarbonImmutable::now($tz);
        $awake = $now->hour >= Deadlines::QUIET_UNTIL && $now->hour < Deadlines::QUIET_FROM;
        $smsOn = $this->messaging->smsFor($tenant->id);
        $counts = ['milestones' => 0, 'weekly' => 0, 'checkins' => 0, 'texts' => 0];
        $db = DB::connection(Connections::cell());
        $dealIds = $db->table('tx_portal_access')->whereNull('revoked_at')->whereIn('role', PortalAccess::CLIENT_ROLES)->distinct()->pluck('deal_id');

        foreach (Deal::query()->whereIn('id', $dealIds)->whereIn('status', ['under_contract', 'closed'])->get() as $deal) {
            $clients = $db->table('tx_portal_access')->where('deal_id', $deal->id)->whereNull('revoked_at')->whereIn('role', PortalAccess::CLIENT_ROLES)->get();
            $stage = PortalView::stage($deal);
            if ($stage !== null && $stage !== $deal->getAttribute('stage_shown')) {
                foreach ($clients as $c) {
                    $locale = Copy::locale((string) $c->locale);
                    $label = Copy::t($locale, 'stage.'.$stage);
                    if ($this->once($c, 'milestone', $stage, 'email')) {
                        $lines = [Copy::t($locale, 'stage_note.'.$stage), ...self::comingUp($deal, $c)];
                        Notification::route('mail', (string) $c->email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.milestone_subject', ['street' => $deal->street, 'stage' => $label]),
                            $lines, url('/my-deal/'.$c->ulid)));
                        $counts['milestones']++;
                    }
                    if ($awake && $smsOn && $c->sms_consent_at !== null && $c->phone !== null && $this->once($c, 'milestone', $stage, 'sms')) {
                        $counts['texts'] += $this->text((string) $c->phone, $deal->street.': '.$label.'. '.Copy::t($locale, 'stage_note.'.$stage).' '.url('/my-deal'));
                    }
                }
                $deal->forceFill(['stage_shown' => $stage])->save();
            }
            if ($deal->status === 'under_contract' && $now->isMonday() && $now->hour >= 9 && $awake) {
                foreach ($clients as $c) {
                    if ($this->once($c, 'weekly', $now->format('o-\WW'), 'email')) {
                        $locale = Copy::locale((string) $c->locale);
                        $current = $stage !== null ? Copy::t($locale, 'stage.'.$stage).': '.Copy::t($locale, 'stage_note.'.$stage) : '';
                        Notification::route('mail', (string) $c->email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.weekly_subject', ['street' => $deal->street]),
                            array_values(array_filter([Copy::t($locale, 'mail.weekly_intro'), $current, ...self::comingUp($deal, $c)])), url('/my-deal/'.$c->ulid)));
                        $counts['weekly']++;
                    }
                }
            }
            $closed = $deal->closed_on !== null ? CarbonImmutable::parse($deal->closed_on->toDateString(), $tz) : null;
            if ($deal->status === 'closed' && $closed !== null && $closed->addDays(7)->lte($now->startOfDay()) && $closed->addDays(30)->gte($now->startOfDay())) {
                foreach ($clients as $c) {
                    if ($this->once($c, 'checkin', 'closed', 'email')) {
                        $locale = Copy::locale((string) $c->locale);
                        Notification::route('mail', (string) $c->email)->notify(new PortalMail($locale, Copy::t($locale, 'mail.checkin_subject'),
                            [Copy::t($locale, 'checkin_intro')], url('/my-deal/'.$c->ulid)));
                        $counts['checkins']++;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * "Coming up" and "waiting on you" lines for an email.
     *
     * @return list<string>
     */
    public static function comingUp(Deal $deal, \stdClass $access): array
    {
        $locale = Copy::locale((string) $access->locale);
        $lines = [];
        $soon = CarbonImmutable::today()->addDays(14)->toDateString();
        $dates = array_filter(PortalView::dates($deal, (string) $access->role, $locale), fn (array $d): bool => ! $d['done'] && $d['date'] >= CarbonImmutable::today()->toDateString() && $d['date'] <= $soon);
        if ($dates !== []) {
            $lines[] = Copy::t($locale, 'mail.coming_up');
            foreach (array_slice($dates, 0, 5) as $d) {
                $lines[] = '• '.$d['label'].': '.$d['when'];
            }
        }
        $requests = PortalView::requests($deal, $access);
        if ($requests !== []) {
            $lines[] = Copy::t($locale, 'mail.waiting_on_you');
            foreach ($requests as $r) {
                $lines[] = '• '.$r['what'];
            }
        }

        return $lines;
    }

    /** Records the update; false when it was already sent. */
    private function once(\stdClass $access, string $kind, string $ref, string $channel): bool
    {
        $db = DB::connection(Connections::cell());
        if ($db->table('tx_portal_updates')->where('access_id', $access->id)->where('kind', $kind)->where('ref', $ref)->where('channel', $channel)->exists()) {
            return false;
        }
        $db->table('tx_portal_updates')->insert(['tenant_id' => $access->tenant_id, 'access_id' => $access->id, 'kind' => $kind, 'ref' => $ref, 'channel' => $channel, 'sent_at' => now()]);

        return true;
    }

    private function text(string $phone, string $message): int
    {
        try {
            app(SmsSender::class)->send($phone, mb_substr($message, 0, 300));

            return 1;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
