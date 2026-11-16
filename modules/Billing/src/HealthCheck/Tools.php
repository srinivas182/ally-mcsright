<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

/**
 * The tools brokers tell the Health Check they pay for, and what RightAlly does about each one today
 * (specs/billing/06). Only "replaces" counts towards savings; "partly" and "keeps" stay in the after-switch total,
 * so the savings figure is never more than RightAlly can honestly deliver. Update this list when a sprint lands.
 */
final class Tools
{
    public const REPLACES = 'replaces';

    public const PARTLY = 'partly';

    public const KEEPS = 'keeps';

    /** @var array<string, array{name: string, kind: string, rightally: string, note: string}> */
    private const LIST = [
        'follow_up_boss' => ['name' => 'Follow Up Boss', 'kind' => 'CRM', 'rightally' => self::REPLACES, 'note' => 'RightAlly CRM, with your contacts and notes brought over.'],
        'boldtrail' => ['name' => 'BoldTrail / kvCORE', 'kind' => 'CRM and website', 'rightally' => self::REPLACES, 'note' => 'RightAlly CRM and IDX websites.'],
        'lofty' => ['name' => 'Lofty', 'kind' => 'CRM and website', 'rightally' => self::REPLACES, 'note' => 'RightAlly CRM and IDX websites.'],
        'sierra' => ['name' => 'Sierra Interactive', 'kind' => 'CRM and website', 'rightally' => self::REPLACES, 'note' => 'RightAlly CRM and IDX websites.'],
        'real_geeks' => ['name' => 'Real Geeks', 'kind' => 'CRM and website', 'rightally' => self::REPLACES, 'note' => 'RightAlly CRM and IDX websites.'],
        'cinc' => ['name' => 'CINC', 'kind' => 'CRM and leads', 'rightally' => self::PARTLY, 'note' => 'RightAlly covers the CRM; keep paid lead buying until you are happy with RightAlly ads.'],
        'website' => ['name' => 'Other website or IDX', 'kind' => 'Website', 'rightally' => self::REPLACES, 'note' => 'RightAlly IDX websites for the brokerage and every agent.'],
        'dotloop' => ['name' => 'dotloop', 'kind' => 'Transactions', 'rightally' => self::REPLACES, 'note' => 'RightAlly deals, checklists and Deal-IQ file review.'],
        'skyslope' => ['name' => 'SkySlope', 'kind' => 'Transactions', 'rightally' => self::REPLACES, 'note' => 'RightAlly deals, checklists and Deal-IQ file review.'],
        'docusign' => ['name' => 'DocuSign or other e-signature', 'kind' => 'E-signature', 'rightally' => self::REPLACES, 'note' => 'RightAlly e-signature is included.'],
        'lone_wolf' => ['name' => 'Lone Wolf back office', 'kind' => 'Back office', 'rightally' => self::REPLACES, 'note' => 'RightAlly commission plans, CDAs and agent payouts.'],
        'brokermint' => ['name' => 'Brokermint', 'kind' => 'Back office', 'rightally' => self::REPLACES, 'note' => 'RightAlly commission plans, CDAs and agent payouts.'],
        'canva' => ['name' => 'Canva or design tools', 'kind' => 'Marketing design', 'rightally' => self::PARTLY, 'note' => 'Ally Studio makes branded flyers and posts; some teams keep Canva for other work.'],
        'email_marketing' => ['name' => 'Mailchimp or email marketing', 'kind' => 'Email marketing', 'rightally' => self::PARTLY, 'note' => 'RightAlly sends follow-up emails and alerts; full campaign tools are coming.'],
        'isa' => ['name' => 'ISA or lead-response service', 'kind' => 'Lead response', 'rightally' => self::PARTLY, 'note' => 'RightAlly answers new leads by text within a minute; AI calling is coming.'],
        'dialer' => ['name' => 'Dialer', 'kind' => 'Calling', 'rightally' => self::PARTLY, 'note' => 'RightAlly has click-to-call; a power dialer is coming.'],
        'showings' => ['name' => 'ShowingTime or showing service', 'kind' => 'Showings', 'rightally' => self::KEEPS, 'note' => 'Keep it for now; RightAlly showings are planned.'],
        'recruiting' => ['name' => 'Recruiting tool', 'kind' => 'Recruiting', 'rightally' => self::KEEPS, 'note' => 'Keep it for now; RightAlly recruiting is planned.'],
        'accounting' => ['name' => 'QuickBooks or accounting', 'kind' => 'Accounting', 'rightally' => self::KEEPS, 'note' => 'Keep your accounting software; RightAlly sends it the commission entries.'],
        'paid_leads' => ['name' => 'Zillow, Realtor.com or other paid leads', 'kind' => 'Paid leads', 'rightally' => self::KEEPS, 'note' => 'Keep buying leads if they pay off; RightAlly shows which ones do.'],
        'mls' => ['name' => 'MLS and association dues', 'kind' => 'Dues', 'rightally' => self::KEEPS, 'note' => 'Dues stay the same.'],
        'other' => ['name' => 'Something else', 'kind' => 'Other', 'rightally' => self::KEEPS, 'note' => 'Counted as kept, so the savings are not overstated.'],
    ];

    /** @return array<string, array{name: string, kind: string, rightally: string, note: string}> */
    public static function all(): array
    {
        return self::LIST;
    }

    /** @return array{name: string, kind: string, rightally: string, note: string}|null */
    public static function find(string $key): ?array
    {
        return self::LIST[$key] ?? null;
    }
}
