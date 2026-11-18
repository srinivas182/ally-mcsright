<?php

declare(strict_types=1);

namespace Modules\Outreach;

/**
 * Live coaching cards for calls (LN4, E20, specs/crm/20): while calling from the call queue, the agent taps what the
 * person just said and sees a short, honest way to answer. Plain words; no pressure tactics; Fair Housing safe (never
 * about who lives in an area). Brokerages can add their own in a later release.
 *
 * @phpstan-type Card array{key: string, they_say: string, you_say: string, tip: string}
 */
final class CallCards
{
    /** @return list<array{key: string, they_say: string, you_say: string, tip: string}> */
    public static function all(): array
    {
        return [
            ['key' => 'busy', 'they_say' => '"I\'m busy right now."',
                'you_say' => 'No problem, I\'ll be quick or call back. When is a better time, later today or tomorrow morning?', 'tip' => 'Offer two times, then put it in as a task.'],
            ['key' => 'not_ready', 'they_say' => '"We\'re not ready yet."',
                'you_say' => 'That makes sense. What would need to happen for the timing to feel right? I can send you homes or prices in the meantime so you\'re ready when you are.',
                'tip' => 'Ask about timing, then start a follow-up plan.'],
            ['key' => 'other_agent', 'they_say' => '"We already have an agent."',
                'you_say' => 'Great, I\'m glad you have help. If anything changes or you\'d like a second opinion on price, I\'m happy to help.', 'tip' => 'Respect it. Note it on the contact and move on.'],
            ['key' => 'price', 'they_say' => '"Your commission is too high."',
                'you_say' => 'Commissions are negotiable and not set by law. Let me show you exactly what you get for it and what you\'d likely walk away with.',
                'tip' => 'Show the net sheet. Never say a rate is standard.'],
            ['key' => 'zillow', 'they_say' => '"Zillow says my home is worth more."',
                'you_say' => 'Online estimates don\'t see inside your home. Let me look at what similar homes nearby actually sold for and walk you through it.',
                'tip' => 'Offer a market analysis with recent sales.'],
            ['key' => 'wait_rates', 'they_say' => '"We\'ll wait for rates to drop."',
                'you_say' => 'That\'s a fair thought. Would it help to see the monthly payment at today\'s rate and at a lower one, so you can decide with real numbers?',
                'tip' => 'Connect them with a lender; never promise where rates go.'],
            ['key' => 'just_looking', 'they_say' => '"I\'m just looking."',
                'you_say' => 'Perfect, looking is the right first step. What would make a home a must-see for you? I\'ll set up a search so you only see those.',
                'tip' => 'Set up a saved search before you hang up.'],
            ['key' => 'stop', 'they_say' => '"Please don\'t call me again."',
                'you_say' => 'Of course. I\'ll take you off my call list right now. Sorry to bother you.', 'tip' => 'Mark Do Not Call on the contact straight away.'],
        ];
    }
}
