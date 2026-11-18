<?php

declare(strict_types=1);

namespace Modules\Sites\Privacy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Privacy\PrivacyRegistry;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;

/**
 * Website accounts, browsing and AIlly chats in privacy requests and retention (TR1, specs/core/18).
 * - Website accounts (people who saved homes or searches): found by email; the copy has their saved homes and the
 *   pages they viewed; deleting removes the account with its browsing, saved homes and AIlly chats.
 * - Retention: browsing older than 13 months and AIlly chats older than 24 months are removed by default.
 */
final class WebsitePrivacy
{
    public static function register(PrivacyRegistry $registry): void
    {
        $registry->source('website_accounts', 'Website accounts', self::find(...), self::export(...), self::erase(...), fn (int $id) => null);
        $registry->retention('website_activity', 'Website browsing', 'Which pages and homes website visitors viewed. Removed after this long.', 13, 3, self::purgeActivity(...));
        $registry->retention('ailly_chats', 'AIlly chats', 'Conversations with the website assistant. Removed after this long (leads they created stay in the CRM).', 24, 6,
            self::purgeChats(...));
    }

    /** @return list<array{id: int, label: string}> */
    public static function find(string $email, ?string $phone): array
    {
        return array_values(DB::connection(Connections::cell())->table('site_visitors')->where('tenant_id', app(TenantContext::class)->id())
            ->where('email', mb_strtolower(trim($email)))->limit(20)->get(['id', 'email', 'name'])
            ->map(fn (object $v): array => ['id' => (int) $v->id, 'label' => trim(($v->name ?? '').' ('.$v->email.')')])->all());
    }

    /** @return array<string, mixed> */
    public static function export(int $id): array
    {
        $db = DB::connection(Connections::cell());
        $v = $db->table('site_visitors')->where('id', $id)->first();

        return ['name' => $v->name ?? null, 'email' => $v->email ?? null, 'first_visit' => $v->first_seen_at ?? null, 'last_visit' => $v->last_seen_at ?? null,
            'saved_homes' => $db->table('site_favourites')->where('visitor_id', $id)->pluck('listing_ulid')->all(),
            'pages_viewed' => $db->table('site_events')->where('visitor_id', $id)->orderBy('id')->limit(2000)->get(['type', 'path', 'created_at'])->all()];
    }

    public static function erase(int $id): string
    {
        $db = DB::connection(Connections::cell());
        $chats = $db->table('ailly_conversations')->where('visitor_id', $id)->pluck('id');
        $db->table('ailly_messages')->whereIn('conversation_id', $chats)->delete();
        $db->table('ailly_conversations')->whereIn('id', $chats)->delete();
        $db->table('site_visitors')->where('merged_into', $id)->update(['merged_into' => null]);
        $db->table('site_visitors')->where('id', $id)->delete(); // browsing and saved homes go with it

        return 'deleted';
    }

    public static function purgeActivity(CarbonImmutable $cutoff): int
    {
        return DB::connection(Connections::cell())->table('site_events')->where('tenant_id', app(TenantContext::class)->id())->where('created_at', '<', $cutoff)->delete();
    }

    public static function purgeChats(CarbonImmutable $cutoff): int
    {
        $db = DB::connection(Connections::cell());
        $removed = 0;
        $db->table('ailly_conversations')->where('tenant_id', app(TenantContext::class)->id())->where('created_at', '<', $cutoff)->select('id')
            ->chunkById(500, function ($chunk) use ($db, &$removed): void {
                $ids = $chunk->pluck('id');
                $db->table('ailly_messages')->whereIn('conversation_id', $ids)->delete();
                $removed += $db->table('ailly_conversations')->whereIn('id', $ids)->delete();
            });

        return $removed;
    }
}
