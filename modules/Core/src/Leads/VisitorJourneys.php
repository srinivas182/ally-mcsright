<?php

declare(strict_types=1);

namespace Modules\Core\Leads;

/**
 * What people did on the brokerage's websites before and after they became contacts (specs/websites/07):
 * searches, homes viewed, favourites, comparisons, payment estimates, and an AI summary. Implemented by the Sites
 * module so the CRM never reads website tables; without it, leads show no journey.
 */
interface VisitorJourneys
{
    /**
     * Short journey facts for a list of contacts (only contacts that have a journey are returned).
     *
     * @param  list<int>  $contactIds
     * @return array<int, array{summary: string, homes_viewed: int, searches: int, favourites: int, visits: int, last_seen: string|null}>
     */
    public function summaries(array $contactIds): array;

    /**
     * The full journey for one contact, newest first (null when there is none).
     *
     * The closing brief (what they want, budget, timeline, financing, homes they care about, homes to send next, an
     * opening line and a checklist) and their chat with the website assistant come with it when there are any.
     *
     * @return array{summary: string, summary_at: string|null, ai: bool, stats: array{homes_viewed: int, searches: int, favourites: int, visits: int}, events: list<array{at: string, type: string, text: string, url: string|null}>, brief: array<string, mixed>|null, chat: string}|null
     */
    public function forContact(int $contactId): ?array;
}
