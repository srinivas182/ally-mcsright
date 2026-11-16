<?php

declare(strict_types=1);

namespace Modules\Transactions\Closing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Transactions\Models\Deal;
use Modules\Transactions\Portal\Copy;

/**
 * The closing-day plan for clients (specs/ai/02, §21), in their language: when and where, what to bring, the final
 * walk-through, and "call before you wire" with the title company's number from the deal itself (never from an
 * email). Shown in the portal in the last week and emailed three days before closing.
 */
final class ClosingDay
{
    public const SHOW_DAYS = 7;

    public const EMAIL_DAYS = 3;

    /**
     * @return list<string>
     */
    public static function plan(Deal $deal, \stdClass $access): array
    {
        if ($deal->closing_date === null || ! in_array($access->role, ['buyer', 'seller'], true)) {
            return [];
        }
        $locale = Copy::locale((string) $access->locale);
        $time = (string) ($deal->getAttribute('closing_time') ?? '');
        $place = trim((string) ($deal->getAttribute('closing_place') ?? ''));
        $when = Copy::date($locale, $deal->closing_date->toDateString()).($time !== '' ? ', '.CarbonImmutable::parse($deal->closing_date->toDateString().' '.$time)->format('g:i A') : '');
        $lines = [Copy::t($locale, 'closing_when', ['when' => $when])];
        $lines[] = $place !== '' ? Copy::t($locale, 'closing_where', ['place' => $place]) : Copy::t($locale, 'closing_tbd');
        $lines[] = Copy::t($locale, $access->role === 'buyer' ? 'closing_bring_buyer' : 'closing_bring_seller');
        $tz = app(TenantContext::class)->current()->timezone ?: 'America/Chicago';
        $walk = DB::connection(Connections::cell())->table('tx_appointments')->where('deal_id', $deal->id)->where('kind', 'walkthrough')->whereIn('status', ['requested', 'confirmed', 'reschedule'])
            ->orderBy('starts_at')->value('starts_at');
        if ($walk !== null) {
            $at = CarbonImmutable::parse((string) $walk)->setTimezone($tz);
            $lines[] = Copy::t($locale, 'closing_walkthrough', ['when' => Copy::date($locale, $at->toDateString()).', '.$at->format('g:i A')]);
        }
        $title = DB::connection(Connections::cell())->table('tx_parties')->where('deal_id', $deal->id)->whereIn('role', ['title', 'escrow'])->whereNotNull('phone')->first(['name', 'company', 'phone']);
        $lines[] = $title !== null ? Copy::t($locale, 'closing_wire', ['company' => (string) ($title->company ?? $title->name), 'phone' => (string) $title->phone]) : Copy::t($locale, 'closing_wire_nophone');

        return $lines;
    }

    /** Whether the portal shows the plan now (the last week before an open deal closes). */
    public static function soon(Deal $deal): bool
    {
        return $deal->status === 'under_contract' && $deal->closing_date !== null
            && $deal->closing_date->toDateString() >= CarbonImmutable::today()->toDateString()
            && $deal->closing_date->toDateString() <= CarbonImmutable::today()->addDays(self::SHOW_DAYS)->toDateString();
    }
}
