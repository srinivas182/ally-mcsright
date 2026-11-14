<?php

declare(strict_types=1);

namespace Modules\Sites;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;

/**
 * The "Powered by RightAlly" credit on websites and the assistant (HQ decides; brokers and agents cannot remove it).
 * On by default; HQ can change the wording and link, switch it off platform-wide, or per brokerage.
 */
final class PoweredBy
{
    /**
     * @return array{enabled: bool, text: string, url: string}
     */
    public function settings(): array
    {
        $r = DB::connection(Connections::control())->table('hq_site_branding')->first();

        return ['enabled' => (bool) ($r->powered_enabled ?? true), 'text' => (string) ($r->powered_text ?? 'Powered by RightAlly'), 'url' => (string) ($r->powered_url ?? 'https://rightally.io')];
    }

    /**
     * @return array{text: string, url: string}|null null when not shown for this brokerage
     */
    public function forTenant(int $tenantId): ?array
    {
        $s = $this->settings();
        $override = DB::connection(Connections::control())->table('hq_site_branding_tenants')->where('tenant_id', $tenantId)->value('powered_enabled');
        $on = $override !== null ? (bool) $override : $s['enabled'];

        return $on ? ['text' => $s['text'], 'url' => $s['url']] : null;
    }

    public function save(bool $enabled, string $text, string $url, User $by): void
    {
        $text = trim(strip_tags($text));
        if ($text === '' || mb_strlen($text) > 60) {
            throw ValidationException::withMessages(['text' => 'Keep the wording under 60 characters.']);
        }
        if (preg_match('#^https://[^\s"<>]{1,190}$#', $url) !== 1) {
            throw ValidationException::withMessages(['url' => 'Use an https link.']);
        }
        $db = DB::connection(Connections::control());
        $values = ['powered_enabled' => $enabled, 'powered_text' => $text, 'powered_url' => $url, 'updated_by' => $by->id, 'updated_at' => now()];
        $db->table('hq_site_branding')->exists() ? $db->table('hq_site_branding')->update($values) : $db->table('hq_site_branding')->insert($values + ['created_at' => now()]);
        app(AuditLogger::class)->record('hq.sites.powered_by', metadata: ['enabled' => $enabled, 'text' => $text, 'url' => $url], severity: 'notice');
    }

    /** null clears the brokerage's override (back to the platform setting). */
    public function setTenant(int $tenantId, ?bool $enabled): void
    {
        $db = DB::connection(Connections::control());
        $enabled === null ? $db->table('hq_site_branding_tenants')->where('tenant_id', $tenantId)->delete()
            : $db->table('hq_site_branding_tenants')->updateOrInsert(['tenant_id' => $tenantId], ['powered_enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()]);
        app(AuditLogger::class)->record('hq.sites.powered_by_tenant', metadata: ['tenant' => $tenantId, 'enabled' => $enabled], severity: 'notice');
    }
}
