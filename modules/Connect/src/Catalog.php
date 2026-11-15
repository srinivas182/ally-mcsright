<?php

declare(strict_types=1);

namespace Modules\Connect;

use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;

/**
 * The apps Connect itself offers, and the apps on the roadmap (specs/connect/00). Apps that sync a module's data are
 * declared by that module when their connector ships; until then they are listed here as "soon" so brokerages can
 * ask for them.
 */
final class Catalog
{
    public static function declare(AppCatalog $catalog): void
    {
        // Available now.
        $catalog->add(new App('csv_import', 'CSV import', 'import', 'Bring in contacts from any CRM or spreadsheet export. Columns are matched for you; nothing is texted without consent on record.',
            scope: 'both', auth: 'link', syncs: ['Contacts', 'Tags', 'Notes', 'Birthdays', 'Last contact date'], link: '/apps/csv_import'));
        $catalog->add(new App('webhooks', 'Outgoing webhooks', 'import', 'Send new leads, contacts, stage changes, bookings and completed tasks to any app, signed so it knows they came from you.',
            auth: 'webhook', syncs: ['New leads', 'New contacts', 'Stage changes', 'Bookings', 'Completed tasks'], multiple: true,
            fields: [['key' => 'url', 'label' => 'Endpoint URL', 'type' => 'url', 'help' => 'An https:// address that accepts JSON (POST).'],
                ['key' => 'events', 'label' => 'Events', 'type' => 'events']]));
        $catalog->add(new App('zapier', 'Zapier and Make', 'import', 'Connect thousands of apps without code: send leads into RightAlly with the Lead API, and send RightAlly events out with webhooks.',
            auth: 'link', syncs: ['Leads in', 'Events out'], link: '/apps/zapier'));
        $catalog->add(new App('lead_api', 'Lead API', 'import', 'Post leads from your own forms, landing pages or tools. Each source gets its own key and is tracked in lead reports.',
            auth: 'link', syncs: ['Leads in'], link: '/leads/settings'));
        $catalog->add(new App('lead_email', 'Lead forwarding email', 'leads', 'Forward lead emails from Zillow, Realtor.com, Homes.com and other portals to your RightAlly address: each becomes a lead, routed and followed up.',
            auth: 'email', syncs: ['Leads in'], link: '/leads/settings'));

        // On the roadmap (people can ask for them; HQ sees the demand).
        foreach ([
            ['google_calendar', 'Google Calendar', 'calendar', 'Two-way calendar: website bookings avoid your busy times and appear in your calendar.', 'agent', null],
            ['microsoft_calendar', 'Microsoft 365 Calendar', 'calendar', 'Two-way Outlook calendar: website bookings avoid your busy times and appear in your calendar.', 'agent', null],
            ['follow_up_boss', 'Follow Up Boss', 'crm', 'Bring over people, notes, tags and stages, then keep them in sync while you move.', 'both', 'Your Follow Up Boss plan'],
            ['boldtrail', 'BoldTrail (kvCORE)', 'crm', 'Bring over contacts, notes and lead history from BoldTrail.', 'both', 'Your BoldTrail plan'],
            ['lofty', 'Lofty', 'crm', 'Bring over contacts, notes and lead history from Lofty (formerly Chime).', 'both', 'Your Lofty plan'],
            ['sierra', 'Sierra Interactive', 'crm', 'Bring over leads, notes and saved searches from Sierra Interactive.', 'both', 'Your Sierra plan'],
            ['real_geeks', 'Real Geeks', 'crm', 'Bring over leads and their activity from Real Geeks.', 'both', 'Your Real Geeks plan'],
            ['boomtown', 'BoomTown', 'crm', 'Bring over leads and notes from BoomTown.', 'both', 'Your BoomTown plan'],
            ['wise_agent', 'Wise Agent', 'crm', 'Bring over contacts and notes from Wise Agent.', 'agent', 'Your Wise Agent plan'],
            ['top_producer', 'Top Producer', 'crm', 'Bring over contacts and notes from Top Producer.', 'agent', 'Your Top Producer plan'],
            ['hubspot', 'HubSpot', 'crm', 'Bring over contacts, companies and notes from HubSpot.', 'brokerage', null],
            ['salesforce', 'Salesforce', 'crm', 'Bring over contacts, accounts and notes from Salesforce.', 'brokerage', null],
            ['realtor_com', 'Realtor.com', 'leads', 'Realtor.com leads (including ReadyConnect) arrive as leads, routed and followed up.', 'both', 'Paid to Realtor.com'],
            ['facebook_leads', 'Facebook Lead Ads', 'leads', 'Every Facebook and Instagram lead form arrives as a lead within seconds.', 'both', null],
            ['zillow', 'Zillow Premier Agent', 'leads', 'Two-way sync of Zillow leads, notes and status (RightAlly is applying as a Zillow partner; lead forwarding email works today).', 'both', 'Paid to Zillow'],
            ['homes_com', 'Homes.com', 'leads', 'Homes.com leads arrive as leads.', 'both', 'Paid to Homes.com'],
            ['ylopo', 'Ylopo', 'leads', 'Ylopo leads and their activity arrive as leads.', 'both', 'Paid to Ylopo'],
            ['google_lsa', 'Google Local Services Ads', 'leads', 'Calls and messages from Google Local Services Ads arrive as leads.', 'both', 'Paid to Google'],
            ['redx', 'REDX', 'leads', 'Expired, FSBO and pre-foreclosure leads from REDX in your CRM and dialer.', 'agent', 'Paid to REDX'],
            ['vulcan7', 'Vulcan7', 'leads', 'Expired and FSBO leads from Vulcan7 in your CRM.', 'agent', 'Paid to Vulcan7'],
            ['meta_ads', 'Meta Ads (Facebook and Instagram)', 'ads', 'Create listing ads with AI from your MLS listings and publish them without leaving RightAlly. Use your own ad account or RightAlly Boost.', 'both', 'Ad spend: your Meta account, or RightAlly Boost credits'],
            ['google_ads', 'Google Ads', 'ads', 'Search and display ads for your listings and your area, created and published from RightAlly.', 'both', 'Ad spend: your Google account, or RightAlly Boost credits'],
            ['skyslope', 'SkySlope', 'transactions', 'Bring deals, dates, parties and documents into RightAlly transactions and commissions.', 'brokerage', 'Your SkySlope plan'],
            ['dotloop', 'dotloop', 'transactions', 'Bring loops, dates, parties and documents into RightAlly transactions.', 'both', 'Your dotloop plan'],
            ['lone_wolf', 'Lone Wolf', 'transactions', 'TransactionDesk and back-office data into RightAlly transactions and commissions.', 'brokerage', 'Your Lone Wolf plan'],
            ['brokermint', 'Brokermint', 'transactions', 'Bring transactions and commission data from Brokermint.', 'brokerage', 'Your Brokermint plan'],
            ['paperless_pipeline', 'Paperless Pipeline', 'transactions', 'Bring transactions and documents from Paperless Pipeline.', 'brokerage', 'Your Paperless Pipeline plan'],
            ['quickbooks', 'QuickBooks Online', 'accounting', 'Commissions, fees and payouts post to your books automatically.', 'brokerage', 'Your QuickBooks plan'],
            ['xero', 'Xero', 'accounting', 'Commissions, fees and payouts post to Xero automatically.', 'brokerage', 'Your Xero plan'],
            ['showingtime', 'ShowingTime', 'showings', 'Showing requests and feedback on your listings, in RightAlly.', 'agent', 'Your ShowingTime plan'],
            ['canva', 'Canva', 'marketing', 'Design with your brand kit and listing photos, then use the designs in posts and ads.', 'agent', null],
            ['social_posting', 'Social posting', 'marketing', 'Post listings and market updates to Facebook, Instagram, LinkedIn and your Google Business Profile.', 'agent', null],
            ['mailchimp', 'Mailchimp', 'marketing', 'Keep audiences in sync with your contacts and tags.', 'both', 'Your Mailchimp plan'],
            ['direct_mail', 'Direct mail', 'marketing', 'Just listed, just sold and farming postcards, printed and mailed from RightAlly.', 'agent', 'Printing and postage'],
            ['ringcentral', 'RingCentral', 'phones', 'Calls and texts from your RingCentral number logged on contacts.', 'both', 'Your RingCentral plan'],
            ['mojo', 'Mojo Dialer', 'phones', 'Dial your RightAlly lists and log every call.', 'agent', 'Your Mojo plan'],
            ['property_data', 'Property data (ATTOM)', 'data', 'Owner, sale history and likely-to-sell signals for better home values and prospecting lists.', 'brokerage', 'Usage, paid with credits'],
            ['lenders', 'Lender partners', 'partners', 'Share pre-approval status and payments with your preferred lenders.', 'both', null],
            ['title_escrow', 'Title and escrow', 'partners', 'Open title orders and track closing milestones with your title partner.', 'brokerage', null],
            ['reviews', 'Reviews (Google and Zillow)', 'reviews', 'Show your reviews on your agent and brokerage websites.', 'agent', null],
        ] as [$key, $name, $category, $summary, $scope, $vendorCost]) {
            $catalog->add(new App($key, $name, $category, $summary, scope: $scope, auth: 'oauth', status: 'soon', vendorCost: $vendorCost));
        }
    }
}
