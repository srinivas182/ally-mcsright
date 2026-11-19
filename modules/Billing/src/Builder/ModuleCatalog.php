<?php

declare(strict_types=1);

namespace Modules\Billing\Builder;

/**
 * PB1: every RightAlly module HQ can put in a package, in plain words, with what it costs RightAlly to run.
 *
 * - features: the plan features (Entitlements) the module switches on.
 * - usage: what is included, as [usage key, cost-list key, how much, "seat" (per person) or "package"]. AI credits use
 *   the cost-list key "ai.credit" and are priced from the real cost of credits (Credits\Economics).
 * - audience: who it is offered to (brokerage, solo agent or both).
 *
 * The amounts included are defaults; HQ changes them per package in the builder.
 */
final class ModuleCatalog
{
    public const AI_CREDIT = 'ai.credit';

    /** Usage the package columns already meter (Usage\UsageMeter): usage key => package column. */
    public const METERED = ['texts' => 'included_text_segments', 'call_minutes' => 'included_call_minutes', 'map_views' => 'included_map_views', 'ai_credits' => 'included_credits'];

    /**
     * @var array<string, array{label: string, about: string, audience: list<string>, features: list<string>, always?: bool,
     *     usage: list<array{0: string, 1: string, 2: int, 3: string}>}>
     */
    public const MODULES = [
        'back_office' => ['label' => 'Back office', 'about' => 'Profiles, deals and documents, commission statements, announcements and training.', 'audience' => ['brokerage', 'agent'],
            'features' => ['backoffice.minimal'], 'always' => true, 'usage' => [['emails', 'postmark.email', 200, 'seat'], ['storage_gb', 'storage.gb', 2, 'seat']]],
        'crm' => ['label' => 'CRM', 'about' => 'Contacts, tasks, pipelines and follow-up plans.', 'audience' => ['brokerage', 'agent'],
            'features' => ['backoffice.basic'], 'usage' => [['emails', 'postmark.email', 500, 'seat']]],
        'crm_ai' => ['label' => 'AI for contacts', 'about' => 'Contact briefs, "ask your database" and voice notes.', 'audience' => ['brokerage', 'agent'],
            'features' => ['crm.ai'], 'usage' => [['ai_credits', self::AI_CREDIT, 150, 'seat']]],
        'todays_ten' => ['label' => "Today's 10", 'about' => 'Who to call today and why, opportunity scores and alerts.', 'audience' => ['brokerage', 'agent'],
            'features' => ['crm.intelligence'], 'usage' => [['ai_credits', self::AI_CREDIT, 50, 'seat']]],
        'website' => ['label' => 'Website with AIlly', 'about' => 'A website with listings search, maps and the AIlly chat assistant.', 'audience' => ['brokerage', 'agent'],
            'features' => ['website'], 'usage' => [['map_views', 'mapbox.map_load', 2000, 'package'], ['ai_credits', self::AI_CREDIT, 100, 'package']]],
        'texting' => ['label' => 'Texting', 'about' => 'Two-way texts with clients who agreed to them.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['texts', 'twilio.sms_segment', 300, 'seat']]],
        'calling' => ['label' => 'Calling and transcripts', 'about' => 'Calls from RightAlly, recorded with consent and written down.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['call_minutes', 'twilio.call_minute', 100, 'seat'], ['transcript_minutes', 'transcripts.minute', 60, 'seat']]],
        'ai_voice' => ['label' => 'AI voice calls', 'about' => 'An AI assistant that calls new leads straight away and books appointments.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['ai_voice_minutes', 'ai_voice.minute', 30, 'seat']]],
        'transactions' => ['label' => 'Deals, forms and e-sign', 'about' => 'Deal checklists, smart forms, e-signatures and document checks.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['ai_credits', self::AI_CREDIT, 30, 'seat'], ['storage_gb', 'storage.gb', 3, 'seat']]],
        'compensation' => ['label' => 'Commissions and payouts', 'about' => 'Commission plans, splits, caps and agent payouts.', 'audience' => ['brokerage'],
            'features' => [], 'usage' => []],
        'accounting' => ['label' => 'Books and trust accounting', 'about' => 'Ledger, bank reconciliation and trust accounts.', 'audience' => ['brokerage'],
            'features' => [], 'usage' => []],
        'studio' => ['label' => 'Ally Studio', 'about' => 'Social posts, reels and tours made from listings.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['studio_posts', 'studio.post', 20, 'package'], ['studio_reels', 'studio.reel', 4, 'package']]],
        'marketing' => ['label' => 'Email marketing', 'about' => 'Newsletters and campaigns to clients who opted in.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['emails', 'postmark.email', 2000, 'package']]],
        'property_data' => ['label' => 'Property and owner look-ups', 'about' => 'Property details, sales history and owner look-ups.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['property_lookups', 'data.property', 10, 'package']]],
        'learn' => ['label' => 'Learn and coaching', 'about' => 'Courses, role-play practice and coaching.', 'audience' => ['brokerage', 'agent'],
            'features' => [], 'usage' => [['ai_credits', self::AI_CREDIT, 20, 'seat']]],
        'blog_writer' => ['label' => 'AI blog writer', 'about' => 'Unique local articles for your website under your name, checked and approved by you, to build your name in search.', 'audience' => ['brokerage', 'agent'],
            'features' => ['content.blog'], 'usage' => [['ai_credits', self::AI_CREDIT, 100, 'package']]],
        'recruiting' => ['label' => 'Recruiting', 'about' => 'Find and invite agents to join the brokerage.', 'audience' => ['brokerage'],
            'features' => [], 'usage' => [['emails', 'postmark.email', 500, 'package']]],
    ];

    /** @return array<string, array<string, mixed>> modules offered to this audience */
    public static function for(string $audience): array
    {
        return array_filter(self::MODULES, fn (array $m): bool => in_array($audience, $m['audience'], true));
    }

    /** Plain names for usage keys. */
    public static function usageLabel(string $key): string
    {
        return ['emails' => 'emails', 'storage_gb' => 'GB of file storage', 'ai_credits' => 'AI credits', 'map_views' => 'website map views', 'texts' => 'texts',
            'call_minutes' => 'call minutes', 'transcript_minutes' => 'transcript minutes', 'ai_voice_minutes' => 'AI call minutes', 'studio_posts' => 'Studio posts',
            'studio_reels' => 'Studio reels', 'property_lookups' => 'property look-ups'][$key] ?? str_replace('_', ' ', $key);
    }
}
