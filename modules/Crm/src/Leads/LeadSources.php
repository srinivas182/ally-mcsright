<?php

declare(strict_types=1);

namespace Modules\Crm\Leads;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Audit\AuditLogger;
use Modules\Core\Support\Connections;
use Modules\Core\Tenancy\TenantContext;
use Modules\Crm\Consent;
use Modules\Crm\CrmAccess;
use Modules\Crm\Enums\Source;
use Modules\Crm\Models\LeadSource;

/**
 * Lead sources (specs/grow/04): API keys (hashed, shown once), hosted/embedded website forms and portal-email
 * forwarding addresses. Owners and staff manage them.
 */
final class LeadSources
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $in
     * @return array{source: LeadSource, key: string|null}
     */
    public function create(array $in, User $by): array
    {
        self::guard($by);
        $kind = (string) ($in['kind'] ?? '');
        $name = trim(strip_tags((string) ($in['name'] ?? '')));
        $source = Source::tryFrom((string) ($in['source'] ?? '')) ?? null;
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name the source, for example "Zillow" or "Website contact form".';
        }
        if (! in_array($kind, LeadSource::KINDS, true)) {
            $errors['kind'] = 'Choose API, website form or forwarded emails.';
        }
        if ($source === null) {
            $errors['source'] = 'Choose where these leads come from.';
        }
        if ($errors !== [] || $source === null) {
            throw ValidationException::withMessages($errors);
        }

        $key = $kind === 'api' ? 'ra_live_'.Str::random(40) : null;
        $wording = trim((string) ($in['consent_wording'] ?? ''));
        $row = LeadSource::query()->create([
            'name' => mb_substr($name, 0, 80), 'kind' => $kind, 'source' => $source, 'token' => Str::random(40),
            'key_hash' => $key !== null ? hash('sha256', $key) : null, 'key_last4' => $key !== null ? substr($key, -4) : null,
            'consent_wording' => $kind === 'form' ? mb_substr($wording !== '' ? $wording : Consent::DEFAULT_SMS_WORDING, 0, 500) : null,
            'heading' => $kind === 'form' ? mb_substr(trim((string) ($in['heading'] ?? '')) ?: 'Ask us about a home', 0, 120) : null,
            'created_by' => $by->id,
        ]);
        if ($kind === 'email') {
            DB::connection(Connections::control())->table('crm_inbound_addresses')->insert(['token' => $row->token, 'tenant_id' => $this->tenants->id(), 'created_at' => now(), 'updated_at' => now()]);
        }
        app(AuditLogger::class)->record('crm.leads.source_created', $row, metadata: ['kind' => $kind], severity: 'notice');

        return ['source' => $row, 'key' => $key];
    }

    public function setActive(LeadSource $source, bool $active, User $by): void
    {
        self::guard($by);
        $source->forceFill(['active' => $active])->save();
        app(AuditLogger::class)->record($active ? 'crm.leads.source_enabled' : 'crm.leads.source_disabled', $source);
    }

    public function rotateKey(LeadSource $source, User $by): string
    {
        self::guard($by);
        if ($source->kind !== 'api') {
            throw ValidationException::withMessages(['source' => 'Only API sources have keys.']);
        }
        $key = 'ra_live_'.Str::random(40);
        $source->forceFill(['key_hash' => hash('sha256', $key), 'key_last4' => substr($key, -4)])->save();
        app(AuditLogger::class)->record('crm.leads.key_rotated', $source);

        return $key;
    }

    public static function forKey(string $key): ?LeadSource
    {
        return $key === '' ? null : LeadSource::query()->where('kind', 'api')->where('active', true)->where('key_hash', hash('sha256', $key))->first();
    }

    public static function address(LeadSource $source): string
    {
        return 'leads-'.$source->token.'@'.config('crm.inbound_email_domain', 'inbound.rightally.app');
    }

    public static function embed(LeadSource $source, string $action): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5);
        $consent = $e((string) $source->consent_wording);

        return <<<HTML
<form action="{$e($action)}" method="post" class="rightally-lead-form">
  <p><label>First name <input name="first_name" required autocomplete="given-name"></label></p>
  <p><label>Last name <input name="last_name" autocomplete="family-name"></label></p>
  <p><label>Email <input type="email" name="email" autocomplete="email"></label></p>
  <p><label>Phone <input type="tel" name="phone" autocomplete="tel"></label></p>
  <p><label>How can we help? <textarea name="message" rows="3"></textarea></label></p>
  <p><label><input type="checkbox" name="sms_consent" value="1"> {$consent}</label></p>
  <div style="position:absolute;left:-5000px" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
  <input type="hidden" name="first_referrer"><input type="hidden" name="landing_url">
  <p><button type="submit">Send</button></p>
</form>
<script>(function(){try{var s=window.localStorage,k='ra_first_touch',v=JSON.parse(s.getItem(k)||'null');if(!v){v={r:document.referrer,l:location.href};s.setItem(k,JSON.stringify(v));}document.querySelectorAll('form.rightally-lead-form').forEach(function(f){f.first_referrer.value=v.r;f.landing_url.value=v.l;});}catch(e){}})();</script>
HTML;
    }

    private static function guard(User $by): void
    {
        if (! CrmAccess::manager($by)) {
            throw ValidationException::withMessages(['source' => 'Only owners and staff can manage lead sources.']);
        }
    }
}
