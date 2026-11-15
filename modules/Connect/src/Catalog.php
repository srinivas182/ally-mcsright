<?php

declare(strict_types=1);

namespace Modules\Connect;

use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Connect\BoldTrail;
use Modules\Crm\Connect\FollowUpBoss;
use Modules\Crm\Connect\GoHighLevel;
use Modules\Crm\Connect\Lofty;
use Modules\Crm\Connect\Sierra;

/**
 * The apps Connect itself offers, and the apps on the roadmap (specs/connect/00). Apps that sync a module's data are
 * declared by that module when their connector ships; until then they are listed here as "soon" so brokerages can
 * ask for them.
 */
final class Catalog
{
    /** Apps on the roadmap whose API is only open to partners: what HQ must apply for (shown as "Needs partner access"). */
    private const PARTNERS = [
        'real_geeks' => 'Real Geeks shares leads only with approved partners (Lead Router). Apply through Real Geeks partner support.',
        'boomtown' => 'BoomTown\'s API is for approved partners only. Apply through BoomTown (Inside Real Estate) partner programme.',
        'top_producer' => 'Top Producer has no public API. Ask Constellation Real Estate Group for partner access.',
        'wise_agent' => 'Wise Agent issues API credentials (OAuth) only to partner integrations, not to agents. Request them at wiseagent.com/docs/api.asp (app name, logo, redirect domain, contacts scope).',
    ];

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

        // AI assistants (MCP, specs/connect/03). "ai_assistants" holds the brokerage's settings and HQ's master switch;
        // each AI app has its own tile with its own steps, and HQ can switch each one off.
        $catalog->add(new App('ai_assistants', 'AI assistant settings', 'ai',
            'Who can connect an AI assistant, whether assistants may add records, and every connected assistant.',
            scope: 'both', auth: 'link', syncs: ['Contacts you can see', 'Your tasks and Today\'s 10', 'Your deals and key dates', 'Your open times'], link: '/apps/ai_assistants'));
        $ask = 'Then ask things like "Who should I call today?" or "When does the inspection period end on 12 Oak St?"';
        foreach ([
            ['ai_claude', 'Claude', 'Any Claude plan (the free plan includes one custom connector).', ['claude.ai', 'claude.com'], 'https://claude.ai/settings/connectors', 'Open Claude settings', false, [
                'Press "Open Claude settings" (or in Claude, go to Settings → Connectors).', 'Press "Add custom connector", name it RightAlly and paste your address below.',
                'Press Connect, sign in to RightAlly and press Allow.']],
            ['ai_chatgpt', 'ChatGPT', 'ChatGPT Plus, Pro, Business, Enterprise or Edu (not the free plan). On Business and Enterprise, an admin may need to allow connectors first.', ['chatgpt.com', 'chat.openai.com'], 'https://chatgpt.com/', 'Open ChatGPT', false, [
                'In ChatGPT, open Settings → Apps & Connectors → Advanced settings, and turn on Developer mode.', 'Go back to Apps & Connectors and press Create. Name it RightAlly, paste your address below, and choose OAuth.',
                'Press Create, sign in to RightAlly and press Allow. ChatGPT asks you before it makes any change.']],
            ['ai_gemini', 'Gemini', 'The Gemini app with a personal Google account in the US (Google limits custom apps to this for now). Gemini Enterprise: your Google admin adds it.', ['gemini.google.com'], 'https://gemini.google.com/', 'Open Gemini', false, [
                'In Gemini, open Settings → Apps (or Extensions) and choose to add an app by its address.', 'Paste your address below and continue.', 'Sign in to RightAlly and press Allow.']],
            ['ai_copilot', 'Microsoft 365 Copilot', 'Microsoft 365 Copilot at work, added once by your IT admin in Copilot Studio (the free Copilot app cannot connect to other apps).', ['copilot.microsoft.com', 'copilotstudio.microsoft.com'], 'https://copilotstudio.microsoft.com/', 'Open Copilot Studio (admins)', true, [
                'Your IT admin opens Copilot Studio and creates an agent (or opens your company\'s agent).', 'They add a tool → Model Context Protocol, paste the address below and choose OAuth.',
                'Each person then signs in to RightAlly once and presses Allow.']],
            ['ai_perplexity', 'Perplexity', 'Perplexity Pro or Max.', ['perplexity.ai', 'www.perplexity.ai'], 'https://www.perplexity.ai/', 'Open Perplexity', false, [
                'In Perplexity, open Settings → Connectors and add a custom connector.', 'Paste your address below.', 'Sign in to RightAlly and press Allow.']],
            ['ai_cursor', 'Cursor and desktop apps', 'Cursor, Claude Desktop and other desktop apps that accept an MCP server address.', ['localhost', '127.0.0.1'], null, null, false, [
                'In the app\'s settings, add an MCP server.', 'Paste your address below (it connects over the web; nothing to install).', 'Sign in to RightAlly in the browser window that opens and press Allow.']],
        ] as [$key, $name, $plans, $hosts, $url, $label, $admin, $steps]) {
            $catalog->add(new App($key, $name, 'ai', 'Ask '.$name.' about your contacts, tasks, deals and calendar. It sees and changes only what you can in RightAlly.',
                scope: 'both', auth: 'link', syncs: ['Contacts you can see', 'Your tasks and Today\'s 10', 'Your deals and key dates', 'Your open times'], link: '/apps/'.$key,
                guide: ['plans' => $plans, 'steps' => [...$steps, $ask], 'open_url' => $url, 'open_label' => $label, 'hosts' => $hosts, 'admin' => $admin]));
        }

        // One click: sign in with Google or Microsoft and approve. Ready once HQ has set up the Google and Microsoft sign-in apps.
        $catalog->add(new App('google_calendar', 'Google Calendar', 'calendar', 'Bookings from your website only offer times you are free, and land in your Google Calendar.',
            scope: 'agent', auth: 'oauth', syncs: ['Busy times (not what the events are)', 'New bookings into your calendar'],
            oauth: ['provider' => 'google', 'scopes' => ['https://www.googleapis.com/auth/calendar.freebusy', 'https://www.googleapis.com/auth/calendar.events']],
            setup: ['needs' => 'provider', 'provider' => 'google', 'steps' => ['Set up the Google sign-in app at the top of this page (return addresses, Calendar API, client ID and secret).',
                'Submit the Google app for verification (calendar access is a free review). Until it is approved, only test users can connect.', 'Switch Google Calendar on.'],
                'docs' => 'https://developers.google.com/calendar/api/guides/overview']));
        $catalog->add(new App('microsoft_calendar', 'Microsoft 365 Calendar', 'calendar', 'Bookings from your website only offer times you are free, and land in your Outlook calendar.',
            scope: 'agent', auth: 'oauth', syncs: ['Busy times', 'New bookings into your calendar'],
            oauth: ['provider' => 'microsoft', 'scopes' => ['Calendars.ReadWrite']],
            setup: ['needs' => 'provider', 'provider' => 'microsoft', 'steps' => ['Set up the Microsoft sign-in app at the top of this page (return addresses, permissions, client ID and secret).',
                'Verify the publisher (free with a Microsoft partner ID), so people see "verified" when they approve.', 'Switch Microsoft 365 Calendar on.'],
                'docs' => 'https://learn.microsoft.com/graph/outlook-calendar-concept-overview']));

        // CRMs: one click (sign in to the other app and approve), then everything comes over (specs/connect/04).
        $catalog->add(new App('follow_up_boss', 'Follow Up Boss', 'crm', 'Bring over your people, tags, stages, agents and notes from Follow Up Boss with one click.',
            scope: 'both', auth: 'oauth', syncs: ['People (names, emails, phones)', 'Tags and stage', 'Which agent each person belongs to', 'Notes'],
            driver: FollowUpBoss::class, vendorCost: 'Your Follow Up Boss plan',
            oauth: ['provider' => 'follow_up_boss', 'scopes' => [], 'authorize' => 'https://app.followupboss.com/oauth/authorize', 'token' => 'https://app.followupboss.com/oauth/token',
                'userinfo' => 'https://api.followupboss.com/v1/me', 'response_type' => 'auth_code', 'pkce' => false, 'token_auth' => 'basic', 'token_state' => true,
                'params' => ['prompt' => 'login'], 'headers' => ['X-System' => 'x_system', 'X-System-Key' => 'x_system_key']],
            setup: ['needs' => 'keys', 'fields' => [
                ['key' => 'x_system', 'label' => 'System name (X-System)'], ['key' => 'x_system_key', 'label' => 'System key (X-System-Key)', 'secret' => true],
                ['key' => 'client_id', 'label' => 'OAuth client ID'], ['key' => 'client_secret', 'label' => 'OAuth client secret', 'secret' => true]],
                'steps' => ['Register RightAlly as a system at apps.followupboss.com/system-registration (free). Copy the system name and system key.',
                    'Ask Follow Up Boss (api@followupboss.com) to create an OAuth app for RightAlly, with the return address shown above as its redirect URL.',
                    'Paste the system name, system key, client ID and client secret here, and save.', 'Switch Follow Up Boss on.'],
                'partner' => 'Free system registration. Being listed in Follow Up Boss\'s integrations directory is optional (apply through their partner team).',
                'request' => ['to' => 'api@followupboss.com', 'subject' => 'OAuth app for RightAlly (registered system)',
                    'body' => "Hello Follow Up Boss team,\n\nRightAlly is an all-in-one platform for US real estate brokerages. We have registered RightAlly as a system and would like an OAuth app so brokers and agents can connect their Follow Up Boss accounts in one click (people, tags, stages, notes; two-way sync).\n\nPlease create an OAuth app for us with this redirect URL:\n{return_address}\n\nOnce it is ready, please send the client ID and client secret through a secure channel (not plain email).\n\nThank you,\nRightAlly HQ"],
                'docs' => 'https://docs.followupboss.com/docs']));

        // CRMs with a personal API key: paste it once (specs/connect/06).
        $catalog->add(new App('lofty', 'Lofty', 'crm', 'Bring over your leads, tags, stages, agents and notes from Lofty (formerly Chime). New leads keep coming over every hour.',
            scope: 'both', auth: 'api_key', syncs: ['Leads (names, emails, phones)', 'Tags and stage', 'Which agent each lead belongs to', 'Notes', 'New leads, hourly'],
            fields: [['key' => 'api_key', 'label' => 'Lofty API key', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.']],
            driver: Lofty::class, vendorCost: 'Your Lofty plan',
            setup: ['steps' => ['Nothing to set up: each broker or agent uses their own Lofty API key.', 'Switch Lofty on.'],
                'partner' => null, 'docs' => 'https://developer.lofty.com/authentication/api-keys'],
            guide: ['steps' => ['In Lofty, open Settings → Integrations → API.', 'Create or copy your API key.', 'Paste it below and press Connect. Your leads start coming over straight away.']]));
        $catalog->add(new App('boldtrail', 'BoldTrail (kvCORE)', 'crm', 'Bring over your contacts, tags, status and agents from BoldTrail (kvCORE). New and changed contacts come over daily.',
            scope: 'both', auth: 'api_key', syncs: ['Contacts (names, emails, phones)', 'Hashtags and status', 'Which agent each contact belongs to', 'New and changed contacts, daily'],
            fields: [['key' => 'api_key', 'label' => 'BoldTrail "Contacts" API token', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.']],
            driver: BoldTrail::class, vendorCost: 'Your BoldTrail plan',
            setup: ['steps' => ['Nothing to set up: each broker or agent uses their own BoldTrail API token.', 'Switch BoldTrail on.'],
                'partner' => 'Notes and two-way sync need BoldTrail\'s partner API (Inside Real Estate). Apply through their marketplace to unlock them.',
                'docs' => 'https://apidocs.kvcore.com/'],
            guide: ['steps' => ['In BoldTrail, open Lead Engine → Lead Dropbox.', 'Under My API Tokens, choose "Contacts" and press Generate.', 'Copy the new token, paste it below and press Connect.']]));

        $catalog->add(new App('sierra', 'Sierra Interactive', 'crm', 'Bring over your leads, tags, status, agents and notes from Sierra Interactive. Changes keep coming over every hour.',
            scope: 'both', auth: 'api_key', syncs: ['Leads (names, emails, phones)', 'Tags and status', 'Which agent each lead belongs to', 'Notes', 'Changed leads, hourly'],
            fields: [['key' => 'api_key', 'label' => 'Sierra API key', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.']],
            driver: Sierra::class, vendorCost: 'Your Sierra plan (API access may depend on it)',
            setup: ['steps' => ['Nothing to set up: each site uses its own Sierra API key.', 'Switch Sierra Interactive on.'],
                'partner' => 'Listing RightAlly in Sierra\'s own integrations directory is optional: email support@sierrainteractive.com with a product summary.',
                'docs' => 'https://api.sierrainteractivedev.com/'],
            guide: ['steps' => ['In Sierra, click the gear icon, then Integrations → Sierra Interactive.', 'Copy your API key.', 'Paste it below and press Connect.']]));
        $catalog->add(new App('gohighlevel', 'GoHighLevel', 'crm', 'Bring over your contacts, tags, source, agents and notes from a GoHighLevel sub-account. Changes keep coming over every hour.',
            scope: 'both', auth: 'api_key', syncs: ['Contacts (names, emails, phones)', 'Tags and source', 'Which user each contact is assigned to', 'Notes', 'Changed contacts, hourly'],
            fields: [['key' => 'api_key', 'label' => 'Private Integration token', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.'],
                ['key' => 'location_id', 'label' => 'Location ID', 'type' => 'text', 'help' => 'Settings → Business Profile in the same sub-account.']],
            driver: GoHighLevel::class, vendorCost: 'Your GoHighLevel plan',
            setup: ['steps' => ['Nothing to set up: each sub-account uses its own Private Integration token.', 'Switch GoHighLevel on.'],
                'partner' => 'One-click connect (instead of a token) needs a GoHighLevel Marketplace app: optional, apply at marketplace.gohighlevel.com.',
                'docs' => 'https://marketplace.gohighlevel.com/docs/'],
            guide: ['steps' => ['In your GoHighLevel sub-account, open Settings → Private Integrations and create one.', 'Tick "View Contacts" (and "View Users", so contacts keep their agent), then copy the token.',
                'Copy the Location ID from Settings → Business Profile.', 'Paste both below and press Connect.']]));

        // On the roadmap (people can ask for them; HQ sees the demand). Some need a partner agreement first.
        foreach ([
            ['real_geeks', 'Real Geeks', 'crm', 'Bring over leads and their activity from Real Geeks.', 'both', 'Your Real Geeks plan'],
            ['boomtown', 'BoomTown', 'crm', 'Bring over leads and notes from BoomTown.', 'both', 'Your BoomTown plan'],
            ['wise_agent', 'Wise Agent', 'crm', 'Bring over contacts and notes from Wise Agent.', 'agent', 'Your Wise Agent plan'],
            ['top_producer', 'Top Producer', 'crm', 'Bring over contacts and notes from Top Producer.', 'agent', 'Your Top Producer plan'],
            ['cinc', 'CINC', 'crm', 'Bring over leads, notes and activity from CINC.', 'both', 'Your CINC plan'],
            ['brivity', 'Brivity', 'crm', 'Bring over contacts and transactions from Brivity.', 'both', 'Your Brivity plan'],
            ['propertybase', 'Propertybase', 'crm', 'Bring over contacts, listings and deals from Propertybase.', 'brokerage', 'Your Propertybase plan'],
            ['liondesk', 'LionDesk', 'crm', 'Bring over contacts and notes from LionDesk.', 'agent', 'Your LionDesk plan'],
            ['rechat', 'Rechat', 'crm', 'Bring over contacts, deals and marketing history from Rechat.', 'both', 'Your Rechat plan'],
            ['cloze', 'Cloze', 'crm', 'Bring over contacts, notes and relationship history from Cloze.', 'agent', 'Your Cloze plan'],
            ['pipedrive', 'Pipedrive', 'crm', 'Bring over people, deals and notes from Pipedrive.', 'both', 'Your Pipedrive plan'],
            ['hubspot', 'HubSpot', 'crm', 'Bring over contacts, companies and notes from HubSpot.', 'brokerage', null],
            ['salesforce', 'Salesforce', 'crm', 'Bring over contacts, accounts and notes from Salesforce.', 'brokerage', null],
            ['realtor_com', 'Realtor.com', 'leads', 'Realtor.com leads (including ReadyConnect) arrive as leads, routed and followed up.', 'both', 'Paid to Realtor.com'],
            ['facebook_leads', 'Facebook Lead Ads', 'leads', 'Every Facebook and Instagram lead form arrives as a lead within seconds.', 'both', null],
            ['zillow', 'Zillow Premier Agent', 'leads', 'Two-way sync of Zillow leads, notes and status (RightAlly is applying as a Zillow partner; lead forwarding email works today).', 'both', 'Paid to Zillow'],
            ['homes_com', 'Homes.com', 'leads', 'Homes.com leads arrive as leads.', 'both', 'Paid to Homes.com'],
            ['ylopo', 'Ylopo', 'leads', 'Ylopo leads and their activity arrive as leads.', 'both', 'Paid to Ylopo'],
            ['google_lsa', 'Google Local Services Ads', 'leads', 'Calls and messages from Google Local Services Ads arrive as leads.', 'both', 'Paid to Google'],
            ['redfin_partner', 'Redfin Partner Agent', 'leads', 'Redfin partner referrals arrive as leads.', 'agent', 'Referral fee to Redfin'],
            ['market_leader', 'Market Leader', 'leads', 'Market Leader leads arrive as leads.', 'both', 'Paid to Market Leader'],
            ['landvoice', 'Landvoice', 'leads', 'Expired, FSBO and pre-foreclosure leads from Landvoice in your CRM.', 'agent', 'Paid to Landvoice'],
            ['smartzip', 'SmartZip', 'leads', 'Likely-seller leads from SmartZip arrive as leads.', 'both', 'Paid to SmartZip'],
            ['redx', 'REDX', 'leads', 'Expired, FSBO and pre-foreclosure leads from REDX in your CRM and dialer.', 'agent', 'Paid to REDX'],
            ['vulcan7', 'Vulcan7', 'leads', 'Expired and FSBO leads from Vulcan7 in your CRM.', 'agent', 'Paid to Vulcan7'],
            ['meta_ads', 'Meta Ads (Facebook and Instagram)', 'ads', 'Create listing ads with AI from your MLS listings and publish them without leaving RightAlly. Use your own ad account or RightAlly Boost.', 'both', 'Ad spend: your Meta account, or RightAlly Boost credits'],
            ['nextdoor_ads', 'Nextdoor Ads', 'ads', 'Neighbourhood ads for your listings and open houses.', 'both', 'Ad spend paid to Nextdoor'],
            ['google_ads', 'Google Ads', 'ads', 'Search, display and YouTube ads for your listings and your area, created and published from RightAlly.', 'both', 'Ad spend: your Google account, or RightAlly Boost credits'],
            ['skyslope', 'SkySlope', 'transactions', 'Bring deals, dates, parties and documents into RightAlly transactions and commissions.', 'brokerage', 'Your SkySlope plan'],
            ['dotloop', 'dotloop', 'transactions', 'Bring loops, dates, parties and documents into RightAlly transactions.', 'both', 'Your dotloop plan'],
            ['lone_wolf', 'Lone Wolf', 'transactions', 'TransactionDesk and back-office data into RightAlly transactions and commissions.', 'brokerage', 'Your Lone Wolf plan'],
            ['brokermint', 'Brokermint', 'transactions', 'Bring transactions and commission data from Brokermint.', 'brokerage', 'Your Brokermint plan'],
            ['paperless_pipeline', 'Paperless Pipeline', 'transactions', 'Bring transactions and documents from Paperless Pipeline.', 'brokerage', 'Your Paperless Pipeline plan'],
            ['open_to_close', 'Open to Close', 'transactions', 'Bring transactions, tasks and documents from Open to Close.', 'brokerage', 'Your Open to Close plan'],
            ['docusign', 'DocuSign', 'transactions', 'Signed DocuSign envelopes filed on the right deal automatically.', 'both', 'Your DocuSign plan'],
            ['authentisign', 'Authentisign', 'transactions', 'Signed Authentisign documents filed on the right deal.', 'both', 'Your Authentisign plan'],
            ['zipform', 'zipForm', 'transactions', 'zipForm transactions and forms into RightAlly deals.', 'agent', 'Your zipForm plan'],
            ['form_simplicity', 'Form Simplicity', 'transactions', 'Form Simplicity transactions and forms into RightAlly deals.', 'agent', 'Your Form Simplicity plan'],
            ['quickbooks', 'QuickBooks Online', 'accounting', 'Commissions, fees and payouts post to your books automatically.', 'brokerage', 'Your QuickBooks plan'],
            ['xero', 'Xero', 'accounting', 'Commissions, fees and payouts post to Xero automatically.', 'brokerage', 'Your Xero plan'],
            ['showingtime', 'ShowingTime', 'showings', 'Showing requests and feedback on your listings, in RightAlly.', 'agent', 'Your ShowingTime plan'],
            ['brokerbay', 'BrokerBay', 'showings', 'Showing requests and feedback from BrokerBay on your listings.', 'both', 'Your BrokerBay plan'],
            ['aligned_showings', 'Aligned Showings', 'showings', 'Showing requests and feedback from Aligned Showings.', 'both', 'Your Aligned Showings plan'],
            ['spacio', 'Spacio', 'showings', 'Open house sign-ins become contacts with consent recorded.', 'agent', 'Your Spacio plan'],
            ['canva', 'Canva', 'marketing', 'Design with your brand kit and listing photos, then use the designs in posts and ads.', 'agent', null],
            ['social_posting', 'Social posting', 'marketing', 'Post listings and market updates to Facebook, Instagram, LinkedIn and your Google Business Profile.', 'agent', null],
            ['mailchimp', 'Mailchimp', 'marketing', 'Keep audiences in sync with your contacts and tags.', 'both', 'Your Mailchimp plan'],
            ['direct_mail', 'Direct mail', 'marketing', 'Just listed, just sold and farming postcards, printed and mailed from RightAlly.', 'agent', 'Printing and postage'],
            ['constant_contact', 'Constant Contact', 'marketing', 'Keep email lists in sync with your contacts and tags.', 'both', 'Your Constant Contact plan'],
            ['bombbomb', 'BombBomb', 'marketing', 'Video emails logged on contacts, with views and replies.', 'agent', 'Your BombBomb plan'],
            ['corefact', 'Corefact', 'marketing', 'Printed farming and listing mail from your RightAlly lists.', 'agent', 'Printing and postage'],
            ['ringcentral', 'RingCentral', 'phones', 'Calls and texts from your RingCentral number logged on contacts.', 'both', 'Your RingCentral plan'],
            ['mojo', 'Mojo Dialer', 'phones', 'Dial your RightAlly lists and log every call.', 'agent', 'Your Mojo plan'],
            ['dialpad', 'Dialpad', 'phones', 'Calls and texts from Dialpad logged on contacts.', 'both', 'Your Dialpad plan'],
            ['aircall', 'Aircall', 'phones', 'Calls from Aircall logged on contacts, with recordings where allowed.', 'both', 'Your Aircall plan'],
            ['property_data', 'Property data (ATTOM)', 'data', 'Owner, sale history and likely-to-sell signals for better home values and prospecting lists.', 'brokerage', 'Usage, paid with credits'],
            ['batchdata', 'BatchData', 'data', 'Owner contact and property data for prospecting lists (with consent rules).', 'brokerage', 'Usage, paid with credits'],
            ['propstream', 'PropStream', 'data', 'Property and owner data lists from PropStream into your CRM.', 'agent', 'Your PropStream plan'],
            ['lenders', 'Lender partners', 'partners', 'Share pre-approval status and payments with your preferred lenders.', 'both', null],
            ['title_escrow', 'Title and escrow', 'partners', 'Open title orders and track closing milestones with your title partner.', 'brokerage', null],
            ['homebot', 'Homebot', 'partners', 'Homeowner value reports and engagement from Homebot on your contacts.', 'both', 'Your Homebot plan'],
            ['total_expert', 'Total Expert', 'partners', 'Shared clients and pre-approval status with lenders on Total Expert.', 'brokerage', null],
            ['qualia', 'Qualia', 'partners', 'Title orders and closing milestones from Qualia on the deal.', 'brokerage', null],
            ['updater', 'Updater', 'partners', 'Help clients move (utilities, address changes) after closing.', 'brokerage', null],
            ['reviews', 'Reviews (Google and Zillow)', 'reviews', 'Show your reviews on your agent and brokerage websites.', 'agent', null],
            ['realsatisfied', 'RealSatisfied', 'reviews', 'Client satisfaction surveys and reviews on your websites.', 'agent', 'Your RealSatisfied plan'],
            ['testimonial_tree', 'Testimonial Tree', 'reviews', 'Testimonials collected after closing, shown on your websites.', 'agent', 'Your Testimonial Tree plan'],
            ['icloud_calendar', 'Apple iCloud Calendar', 'calendar', 'Bookings avoid your busy times in iCloud Calendar (with an app-specific password).', 'agent', null],
        ] as [$key, $name, $category, $summary, $scope, $vendorCost]) {
            $catalog->add(new App($key, $name, $category, $summary, scope: $scope, auth: 'oauth', status: 'soon', vendorCost: $vendorCost,
                setup: ['steps' => ['Not built yet. Its setup steps (where to register, the return address, keys and any partner agreement) appear here when it is built.'],
                    'partner' => self::PARTNERS[$key] ?? null]));
        }
    }
}
