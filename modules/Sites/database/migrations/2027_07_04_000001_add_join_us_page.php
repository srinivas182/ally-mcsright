<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Support\Connections;
use Modules\Sites\Content\StarterSite;

/**
 * AG8: every brokerage website gets the "Join us" page, in the menu just before Contact. A website that already has
 * a page at /join-us keeps it. Agents' own websites are not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(Connections::cell());
        foreach ($db->table('sites')->where('kind', 'brokerage')->get(['id', 'tenant_id', 'info']) as $site) {
            if ($db->table('site_pages')->where('site_id', $site->id)->where('path', '/join-us')->exists()) {
                continue;
            }
            $info = (array) json_decode((string) $site->info, true);
            $page = StarterSite::joinPage(trim((string) ($info['name'] ?? '')) ?: 'Our brokerage', trim((string) ($info['area'] ?? '')) ?: 'your area');
            $contact = $db->table('site_pages')->where('site_id', $site->id)->where('path', '/contact')->value('position');
            $position = $contact !== null ? (int) $contact : (int) $db->table('site_pages')->where('site_id', $site->id)->max('position') + 1;
            if ($contact !== null) {
                $db->table('site_pages')->where('site_id', $site->id)->where('position', '>=', $position)->increment('position');
            }
            $db->table('site_pages')->insert(['ulid' => (string) Str::ulid(), 'tenant_id' => $site->tenant_id, 'site_id' => $site->id, 'path' => $page['path'],
                'title' => $page['title'], 'meta_description' => $page['meta'], 'sections' => json_encode($page['sections']), 'in_menu' => true,
                'position' => $position, 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The page is left in place: the brokerage may have edited it, and it can be removed in the website builder.
    }
};
