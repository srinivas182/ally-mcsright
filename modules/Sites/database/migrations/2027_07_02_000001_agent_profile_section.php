<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\Connections;

/**
 * AG7: agents' pages made before the profile section open with it instead of the old big photo with a search box.
 * Only a home page that still starts with that untouched opening (headline = the agent's name) is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection(Connections::cell());
        $sites = $db->table('sites')->where('kind', 'agent')->get(['id', 'info']);
        foreach ($sites as $site) {
            $name = (string) (json_decode((string) $site->info, true)['name'] ?? '');
            $page = $db->table('site_pages')->where('site_id', $site->id)->where('path', '/')->first(['id', 'sections']);
            $sections = $page !== null ? json_decode((string) $page->sections, true) : null;
            if (! is_array($sections) || ($sections[0]['type'] ?? '') !== 'hero' || trim((string) ($sections[0]['props']['headline'] ?? '')) !== trim($name)) {
                continue;
            }
            $sections[0] = ['type' => 'agent', 'id' => 'profile', 'props' => ['home_value' => true, 'search' => true], 'hidden' => false];
            $db->table('site_pages')->where('id', $page->id)->update(['sections' => json_encode($sections), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // The old opening is not put back: the profile section can be removed in the page builder.
    }
};
