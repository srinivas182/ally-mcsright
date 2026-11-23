<?php

declare(strict_types=1);

namespace Modules\Sites\Console;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Models\Tenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Sites\Content\StarterSite;
use Modules\Sites\Models\Site;
use Modules\Sites\Models\SitePage;
use Throwable;

/**
 * QW1: replaces starter content RightAlly has since improved, on websites that still show it unchanged. Today: the
 * home page's number strip ("1 search", "Minutes", "Free", "24/7") becomes a "Why clients choose …" section. Anything
 * a brokerage has edited is left alone. Safe on every deploy.
 */
final class RefreshStarterContent extends Command
{
    protected $signature = 'sites:refresh-starter';

    protected $description = 'Replace unchanged starter content on websites with the current version';

    public function handle(TenantContext $tenants): int
    {
        $changed = 0;
        foreach (Tenant::query()->where('type', '!=', 'hq')->orderBy('id')->get() as $tenant) {
            try {
                $changed += $tenants->runAs($tenant, fn (): int => self::refresh());
            } catch (Throwable $e) {
                $this->warn("{$tenant->slug}: ".$e->getMessage());
            }
        }
        $this->info("Starter content refreshed on {$changed} page(s).");

        return self::SUCCESS;
    }

    /** Pages changed for the current brokerage. */
    public static function refresh(): int
    {
        $changed = 0;
        foreach (SitePage::query()->where('path', '/')->get() as $page) {
            $sections = (array) $page->sections;
            $dirty = false;
            foreach ($sections as $i => $s) {
                if (($s['type'] ?? '') === 'stats' && ($s['id'] ?? '') === 'promise' && (($s['props']['items'][0]['value'] ?? null) === '1 search')) {
                    $site = Site::query()->find($page->site_id);
                    $sections[$i] = StarterSite::promise($site?->displayName() ?? 'us');
                    $dirty = true;
                }
            }
            if ($dirty) {
                $page->forceFill(['sections' => $sections])->save();
                $changed++;
            }
        }

        return $changed;
    }
}
