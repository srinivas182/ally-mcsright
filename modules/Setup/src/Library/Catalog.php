<?php

declare(strict_types=1);

namespace Modules\Setup\Library;

/**
 * The ready-made library (SU3, specs/setup/03): proven commission plans, follow-up plans and message templates a
 * brokerage installs in one click. Each item has a version; when RightAlly improves an item, brokerages that haven't
 * changed their copy can take the update, and nobody's own changes are ever overwritten.
 *
 * Messages use the CRM's merge fields: {first_name}, {last_name}, {agent_first_name}, {agent_name}, {agent_phone},
 * {brokerage}, {property}. Every item passes the Fair Housing screen when installed.
 */
final class Catalog
{
    public const KINDS = ['plan' => 'Commission plans', 'follow_up' => 'Follow-up plans', 'message' => 'Message templates'];

    /**
     * @var array<string, array{kind: string, version: int, title: string, description: string, language: string, content: array<string, mixed>}>
     */
    public const ITEMS = [
        // Commission plans: installed as drafts; the owner checks and publishes them.
        'plan_80_20_cap' => ['kind' => 'plan', 'version' => 1, 'title' => '80/20 with an $18,000 cap', 'language' => 'en',
            'description' => 'The most common split: 80% to the agent until the brokerage has kept $18,000 in the agent\'s year, then 100%, with a $395 fee per deal.',
            'content' => ['agent_percent' => 80, 'cap_dollars' => 18000, 'post_split' => [['name' => 'Transaction fee', 'kind' => 'flat', 'amount' => 395, 'payable_to' => 'brokerage']]]],
        'plan_70_30_cap' => ['kind' => 'plan', 'version' => 1, 'title' => '70/30 with a $16,000 cap (new agents)', 'language' => 'en',
            'description' => 'For newer agents who use more of your support: 70% to the agent until a $16,000 cap.',
            'content' => ['agent_percent' => 70, 'cap_dollars' => 16000]],
        'plan_90_10' => ['kind' => 'plan', 'version' => 1, 'title' => '90/10, no cap', 'language' => 'en',
            'description' => 'For experienced, self-sufficient agents: 90% on every deal.',
            'content' => ['agent_percent' => 90]],
        'plan_flat_395' => ['kind' => 'plan', 'version' => 1, 'title' => 'Flat $395 per deal', 'language' => 'en',
            'description' => 'Agents keep everything and pay a flat $395 on each closing.',
            'content' => ['flat_fee_dollars' => 395]],

        // Follow-up plans (the CRM already has the everyday ones; these add more situations).
        'fu_investor' => ['kind' => 'follow_up', 'version' => 1, 'title' => 'Investor lead (30 days)', 'language' => 'en',
            'description' => 'For people buying to rent or flip: numbers first, then off-market and new listings.',
            'content' => ['steps' => [
                [0, 'call', 'Call: what do they buy, where, and how do they pay?', null],
                [0, 'email', 'Send recent sales and rents in their area', 'Hi {first_name}, here are recent sales and rents in the areas you mentioned. Tell me your buy box and I\'ll send matches the day they list. {agent_first_name}'],
                [7, 'text', 'Check in on the numbers', 'Hi {first_name}, did any of those numbers work for you? Happy to run a property for you. {agent_first_name}'],
                [14, 'call', 'Second call: any properties to run?', null],
                [30, 'email', 'Monthly investor update', 'Hi {first_name}, here\'s what sold and rented this month near your target areas. {agent_first_name}'],
            ]]],
        'fu_renter' => ['kind' => 'follow_up', 'version' => 1, 'title' => 'Renter thinking about buying (90 days)', 'language' => 'en',
            'description' => 'Gentle steps for renters: what buying could look like, and a lender introduction when they\'re ready.',
            'content' => ['steps' => [
                [0, 'text', 'Friendly hello', 'Hi {first_name}, it\'s {agent_first_name}. Happy to show you what owning could look like whenever you\'re curious. No pressure!'],
                [14, 'email', 'Renting vs buying, in plain numbers', 'Hi {first_name}, here is a simple look at renting compared with buying in this area. {agent_first_name}'],
                [45, 'call', 'Call: any questions? Offer a lender chat', null],
                [90, 'email', 'Homes in their price range', 'Hi {first_name}, a few homes in the range we talked about. Want to see any? {agent_first_name}'],
            ]]],
        'fu_relocation' => ['kind' => 'follow_up', 'version' => 1, 'title' => 'Moving here from out of town (21 days)', 'language' => 'en',
            'description' => 'For people relocating: areas, commute and a video tour before they fly in.',
            'content' => ['steps' => [
                [0, 'call', 'Call: when do they move, what matters most (commute, space, budget)?', null],
                [1, 'email', 'Area guide with commute times', 'Hi {first_name}, here is a short guide to the areas we talked about, with commute times. {agent_first_name}'],
                [7, 'text', 'Offer a video tour', 'Hi {first_name}, would a live video tour of a few homes help before your trip? {agent_first_name}'],
                [21, 'call', 'Plan the visit', null],
            ]]],
        'fu_seller_es' => ['kind' => 'follow_up', 'version' => 1, 'title' => 'Vendedor nuevo (14 días, en español)', 'language' => 'es',
            'description' => 'Para dueños que preguntaron cuánto vale su casa y prefieren español.',
            'content' => ['steps' => [
                [0, 'call', 'Llamar: agradecer y preguntar por sus planes', null],
                [0, 'email', 'Enviar el valor estimado de su casa', 'Hola {first_name}, gracias por preguntar por el valor de su casa. Aquí tiene lo que se vendió cerca y qué puede cambiar el precio. {agent_first_name}'],
                [3, 'text', 'Ofrecer una visita sin compromiso', 'Hola {first_name}, con gusto visito su casa para darle un precio exacto, sin compromiso. ¿Le funciona esta semana? {agent_first_name}'],
                [14, 'call', 'Llamada de seguimiento', null],
            ]]],

        // Message templates (texts and emails), shared with the whole brokerage.
        'msg_showing_confirm' => ['kind' => 'message', 'version' => 1, 'title' => 'Showing confirmed (text)', 'language' => 'en', 'description' => 'Confirms a showing time and address.',
            'content' => ['channel' => 'sms', 'body' => 'Hi {first_name}, you\'re all set to see {property}. I\'ll meet you there. Text me if anything changes. {agent_first_name}']],
        'msg_offer_sent' => ['kind' => 'message', 'version' => 1, 'title' => 'Offer sent (text)', 'language' => 'en', 'description' => 'Lets a buyer know their offer went in and what happens next.',
            'content' => ['channel' => 'sms', 'body' => 'Hi {first_name}, your offer on {property} has been sent. I\'ll let you know as soon as I hear back. {agent_first_name}']],
        'msg_under_contract' => ['kind' => 'message', 'version' => 1, 'title' => 'Under contract (email)', 'language' => 'en', 'description' => 'Congratulates the client and explains the next steps.',
            'content' => ['channel' => 'email', 'subject' => 'You\'re under contract on {property}!', 'body' => "Hi {first_name},\n\nCongratulations, {property} is under contract! Next come the inspection, the appraisal and the loan. I'll keep you posted at every step, and you can always see where things stand in your deal page.\n\n{agent_name}\n{agent_phone}"]],
        'msg_closing_day' => ['kind' => 'message', 'version' => 1, 'title' => 'Closing day (text)', 'language' => 'en', 'description' => 'A short note on closing day.',
            'content' => ['channel' => 'sms', 'body' => 'Happy closing day, {first_name}! It has been a pleasure working with you. {agent_first_name}']],
        'msg_review_request' => ['kind' => 'message', 'version' => 1, 'title' => 'Ask for a review (email)', 'language' => 'en', 'description' => 'Asks a happy client for a review a few days after closing.',
            'content' => ['channel' => 'email', 'subject' => 'A quick favour?', 'body' => "Hi {first_name},\n\nI hope you're settling in! If you were happy with how things went, a short review would mean a lot and helps other people find me.\n\nThank you,\n{agent_name}"]],
        'msg_price_drop' => ['kind' => 'message', 'version' => 1, 'title' => 'Price drop on a home they liked (text)', 'language' => 'en', 'description' => 'Lets someone know a home they looked at has a new price.',
            'content' => ['channel' => 'sms', 'body' => 'Hi {first_name}, good news: {property} has a new, lower price. Want to take another look? {agent_first_name}']],
        'msg_showing_confirm_es' => ['kind' => 'message', 'version' => 1, 'title' => 'Visita confirmada (texto)', 'language' => 'es', 'description' => 'Confirma una visita, en español.',
            'content' => ['channel' => 'sms', 'body' => 'Hola {first_name}, quedó confirmada la visita a {property}. Nos vemos allí. Escríbame si algo cambia. {agent_first_name}']],
        'msg_under_contract_es' => ['kind' => 'message', 'version' => 1, 'title' => 'Bajo contrato (correo)', 'language' => 'es', 'description' => 'Felicita al cliente y explica los siguientes pasos, en español.',
            'content' => ['channel' => 'email', 'subject' => '¡{property} está bajo contrato!', 'body' => "Hola {first_name},\n\n¡Felicidades! {property} está bajo contrato. Ahora siguen la inspección, el avalúo y el préstamo. Le avisaré en cada paso.\n\n{agent_name}\n{agent_phone}"]],
    ];
}
