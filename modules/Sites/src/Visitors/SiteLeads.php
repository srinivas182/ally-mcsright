<?php

declare(strict_types=1);

namespace Modules\Sites\Visitors;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Crm\Consent;
use Modules\Crm\Enums\Source;
use Modules\Crm\Leads\LeadIntake;
use Modules\Crm\Models\Lead;
use Modules\Crm\Models\LeadSource;
use Modules\Sites\Models\Site;
use Modules\Sites\Rendering\SiteContext;

/**
 * Every website form becomes a CRM lead the same way (specs/websites/05, 07): a named website source with the TCPA
 * consent wording, the visitor's first-touch referrer and landing page, and the journey tied to the new lead.
 */
final class SiteLeads
{
    public function __construct(private readonly LeadIntake $intake, private readonly Visitors $visitors) {}

    public static function source(string $name, string $heading): LeadSource
    {
        return LeadSource::query()->firstOrCreate(['kind' => 'form', 'name' => $name],
            ['source' => Source::Website, 'token' => Str::random(40), 'consent_wording' => Consent::DEFAULT_SMS_WORDING, 'heading' => $heading, 'active' => true]);
    }

    /**
     * @param  array<string, mixed>  $in  LeadIntake fields
     * @param  array<string, mixed>  $data  facts for the journey event
     */
    public function capture(Site $site, LeadSource $source, array $in, Request $request, string $type, ?string $listingUlid = null, array $data = []): Lead
    {
        $vid = $request->input('vid');
        $visitor = Visitors::validVid($vid) ? $this->visitors->resolve($site, (string) $vid) : null;
        if ($visitor !== null) {
            $in['referrer'] = ($in['referrer'] ?? '') !== '' ? $in['referrer'] : (string) $visitor->first_referrer;
            $in['landing_url'] = ($in['landing_url'] ?? '') !== '' ? $in['landing_url'] : (string) $visitor->landing_url;
        }
        // On an agent's website the lead is theirs (unless the person already has an agent).
        $agentSite = app(SiteContext::class)->agent();
        if ($agentSite === null && is_string($request->input('agent')) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $request->input('agent')) === 1) {
            $agentSite = Site::query()->where('ulid', $request->input('agent'))->where('kind', 'agent')->where('status', 'live')->first();
        }
        $agent = $agentSite !== null ? User::query()->find($agentSite->user_id) : null;
        $agent = $agent instanceof User && $agent->isActive() ? $agent : null;
        if ($agent !== null && $source->name === 'Website') {
            $source = self::source('Agent website', 'Contact the agent');
        }
        $lead = $this->intake->receive($source, $in + ['consent_text' => (string) $source->consent_wording], $request, $agent);
        if ($visitor !== null) {
            $this->visitors->linkLead($visitor, $lead, $type, $listingUlid, $data);
        }

        return $lead;
    }
}
