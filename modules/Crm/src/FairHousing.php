<?php

declare(strict_types=1);

namespace Modules\Crm;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Wording that can steer buyers or refer to protected characteristics (US Fair Housing Act). One check for every
 * channel: ads, texts and emails, AIlly, websites, AI content, templates and listing descriptions
 * (specs/grow/12). A guardrail, not legal advice.
 *
 * screen() is the check with a record: it counts what each channel checked and logs every time it stopped,
 * removed or warned about wording, so the monthly audit pack can show what changed and why.
 */
final class FairHousing
{
    /** The channels in the audit pack. */
    public const CHANNELS = ['ads' => 'Ads', 'messages' => 'Texts and emails', 'ailly' => 'AIlly website assistant', 'websites' => 'Websites and funnels',
        'ai_content' => 'AI drafts and summaries', 'templates' => 'Templates and plans', 'listings' => 'Listing descriptions'];

    public const FLAGS = ['safe neighborhood', 'safe neighbourhood', 'safe area', 'safe community', 'safe place to live', 'family-friendly', 'family friendly', 'good for families', 'perfect for families', 'ideal for families', 'young professionals', 'empty nesters',
        'perfect for young', 'great for kids', 'church', 'mosque', 'synagogue', 'integrated', 'exclusive neighborhood', 'no children',
        'adults only', 'walking distance to temple', 'traditional neighborhood', 'ethnic', 'bachelor pad', 'handicap'];

    /**
     * @return list<string> the risky phrases found
     */
    public static function flags(string $text): array
    {
        $lower = mb_strtolower($text);

        return array_values(array_filter(self::FLAGS, fn (string $flag): bool => str_contains($lower, $flag)));
    }

    /**
     * The check, recorded: what it found, counted for the channel, and logged when it acted.
     *
     * @param  'blocked'|'removed'|'warned'  $action  what happens to flagged wording here
     * @return list<string> the risky phrases found
     */
    public static function screen(string $text, string $channel, string $action = 'blocked', ?string $where = null): array
    {
        $flags = self::flags($text);
        if (mb_strlen(trim($text)) < 3 || ! isset(self::CHANNELS[$channel])) {
            return $flags;
        }
        try {
            $tenant = app(TenantContext::class)->id();
            $db = DB::connection(Connections::cell());
            $day = CarbonImmutable::now()->toDateString();
            $key = ['tenant_id' => $tenant, 'day' => $day, 'channel' => $channel];
            $db->table('crm_fair_housing_checks')->insertOrIgnore($key + ['checked' => 0, 'flagged' => 0]);
            $db->table('crm_fair_housing_checks')->where($key)->update(['checked' => DB::raw('checked + 1'), 'flagged' => DB::raw('flagged + '.($flags !== [] ? 1 : 0))]);
            if ($flags !== []) {
                $db->table('crm_fair_housing_log')->insert(['tenant_id' => $tenant, 'channel' => $channel, 'action' => $action, 'phrases' => json_encode($flags),
                    'excerpt' => self::excerpt($text, $flags[0]), 'where' => $where !== null ? mb_substr($where, 0, 120) : null, 'user_id' => auth()->id(),
                    'created_at' => CarbonImmutable::now()]);
            }
        } catch (\Throwable $e) {
            report($e); // the check itself never fails a send
        }

        return $flags;
    }

    /** About 280 characters around the first risky phrase. */
    private static function excerpt(string $text, string $phrase): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
        $at = mb_stripos($text, $phrase);
        $start = max(0, ($at === false ? 0 : $at) - 120);

        return mb_substr(($start > 0 ? '…' : '').mb_substr($text, $start, 280), 0, 300);
    }
}
