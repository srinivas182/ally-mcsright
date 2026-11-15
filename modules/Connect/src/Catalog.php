<?php

declare(strict_types=1);

namespace Modules\Connect;

use Modules\Ads\Meta\MetaAdsConnector;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Connect\BoldTrail;
use Modules\Crm\Connect\FacebookLeads;
use Modules\Crm\Connect\FollowUpBoss;
use Modules\Crm\Connect\GoHighLevel;
use Modules\Crm\Connect\GoogleLsa;
use Modules\Crm\Connect\HubSpot;
use Modules\Crm\Connect\Lofty;
use Modules\Crm\Connect\Pipedrive;
use Modules\Crm\Connect\Salesforce;
use Modules\Crm\Connect\Sierra;
use Modules\Ledger\Books\QuickBooks;
use Modules\Ledger\Books\Xero;
use Modules\Transactions\Connect\Brokermint;
use Modules\Transactions\Connect\DocuSign;
use Modules\Transactions\Connect\Dotloop;
use Modules\Transactions\Connect\LoneWolf;
use Modules\Transactions\Connect\OpenToClose;
use Modules\Transactions\Connect\SkySlope;

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
        'cinc' => 'CINC\'s API is for approved partners only. Apply through CINC (Fathom Realty) partner support.',
        'brivity' => 'Brivity\'s API is for approved partners only. Ask Brivity for partner API access.',
        'liondesk' => 'LionDesk\'s API (Lone Wolf) needs a partner agreement. Apply through the Lone Wolf partner programme.',
        'rechat' => 'Rechat shares data with approved partners. Ask Rechat for partner API access.',
        'cloze' => 'Cloze has a public API with a personal API key, but its people-feed reference is only in its OpenAPI file (api.cloze.com/api-docs). Built once that is confirmed with Cloze; one-click connect needs a Cloze partner app.',
        'paperless_pipeline' => 'Paperless Pipeline offers no data API to other apps (only single sign-on and Zapier). Ask Paperless Pipeline for partner API access. Meanwhile its file export comes over with Deals → Switch from another app.',
        'authentisign' => 'Authentisign is part of Lone Wolf Transactions (zipForm Edition). Its Transact API (signings and signed documents) is for Lone Wolf partners: the same partner access as the Lone Wolf card.',
        'zipform' => 'zipForm (Lone Wolf Transactions, zipForm Edition) shares transactions and forms through Lone Wolf\'s Transact API, for Lone Wolf partners only: the same partner access as the Lone Wolf card.',
        'form_simplicity' => 'Form Simplicity (Florida Realtors) has no public API. Ask Form Simplicity for partner access. Meanwhile signed PDFs can be uploaded to the deal, or brought over by file export.',
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

        $catalog->add(new App('hubspot', 'HubSpot', 'crm', 'Bring over your contacts, lead status, owners and notes from HubSpot. Changes keep coming over every hour.',
            scope: 'both', auth: 'api_key', syncs: ['Contacts (names, emails, phones)', 'Lead status and lifecycle stage', 'Which owner each contact belongs to', 'Notes', 'Changes, hourly'],
            fields: [['key' => 'api_key', 'label' => 'Private app access token', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.']],
            driver: HubSpot::class,
            setup: ['steps' => ['Nothing to set up: each HubSpot account uses its own private app token.', 'Switch HubSpot on.'],
                'partner' => 'One-click connect (instead of a token) needs a HubSpot public app: optional, free at developers.hubspot.com.', 'docs' => 'https://developers.hubspot.com/docs/api/private-apps'],
            guide: ['steps' => ['In HubSpot, open Settings → Integrations → Private Apps and create a private app called RightAlly.',
                'Under Scopes, tick crm.objects.contacts.read and crm.objects.owners.read, then create the app.', 'Copy its access token, paste it below and press Connect.']]));
        $catalog->add(new App('pipedrive', 'Pipedrive', 'crm', 'Bring over your people, owners and notes from Pipedrive. Changes keep coming over every hour.',
            scope: 'both', auth: 'api_key', syncs: ['People (names, emails, phones)', 'Which owner each person belongs to', 'Notes', 'Changes, hourly'],
            fields: [['key' => 'api_key', 'label' => 'Pipedrive API token', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.']],
            driver: Pipedrive::class, vendorCost: 'Your Pipedrive plan',
            setup: ['steps' => ['Nothing to set up: each person uses their own Pipedrive API token.', 'Switch Pipedrive on.'],
                'partner' => 'One-click connect (instead of a token) needs a Pipedrive Marketplace app: optional, at developers.pipedrive.com.', 'docs' => 'https://developers.pipedrive.com/docs/api/v1'],
            guide: ['steps' => ['In Pipedrive, click your avatar → Personal preferences → API.', 'Copy your personal API token.', 'Paste it below and press Connect.']]));

        // Salesforce, and Propertybase on top of it: one click with RightAlly's connected app (specs/connect/09).
        $catalog->add(new App('salesforce', 'Salesforce', 'crm', 'Bring over your Salesforce contacts and open leads, with their owner, status and source, in one click. Changes keep coming over every hour.',
            scope: 'brokerage', auth: 'oauth', syncs: ['Contacts and open leads (names, emails, phones)', 'Lead status and source', 'Which owner each belongs to', 'Changes, hourly'],
            driver: Salesforce::class, vendorCost: 'Your Salesforce plan (needs API access: Enterprise, Unlimited or Developer edition)',
            oauth: ['provider' => 'salesforce', 'scopes' => ['api', 'refresh_token'], 'authorize' => 'https://login.salesforce.com/services/oauth2/authorize',
                'token' => 'https://login.salesforce.com/services/oauth2/token', 'userinfo' => 'https://login.salesforce.com/services/oauth2/userinfo', 'pkce' => true],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Consumer key'], ['key' => 'client_secret', 'label' => 'Consumer secret', 'secret' => true]],
                'steps' => ['In RightAlly\'s own Salesforce org (a free Developer Edition works), open Setup → App Manager → New Connected App (or External Client App).',
                    'Name it RightAlly, tick "Enable OAuth Settings", and use the return address shown above as the callback URL.',
                    'Add the scopes "Manage user data via APIs (api)" and "Perform requests at any time (refresh_token, offline_access)", and tick "Require PKCE".',
                    'Save, then copy the consumer key and consumer secret here. Salesforce can take up to 10 minutes to activate a new app.', 'Switch Salesforce on. Propertybase uses the same app.'],
                'partner' => 'No partner agreement needed. An AppExchange listing is optional.', 'docs' => 'https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/'],
            guide: ['steps' => ['Press Connect and sign in to Salesforce as an admin.', 'Press Allow. Your contacts and open leads start coming over straight away.']]));
        $catalog->add(new App('propertybase', 'Propertybase', 'crm', 'Bring over your Propertybase contacts and open leads, with their owner, status and source, in one click. Changes keep coming over every hour.',
            scope: 'brokerage', auth: 'oauth', syncs: ['Contacts and open leads (names, emails, phones)', 'Lead status and source', 'Which owner each belongs to', 'Changes, hourly'],
            driver: Salesforce::class, vendorCost: 'Your Propertybase plan',
            oauth: ['provider' => 'salesforce', 'scopes' => ['api', 'refresh_token'], 'authorize' => 'https://login.salesforce.com/services/oauth2/authorize',
                'token' => 'https://login.salesforce.com/services/oauth2/token', 'userinfo' => 'https://login.salesforce.com/services/oauth2/userinfo', 'pkce' => true, 'keys_from' => 'salesforce'],
            setup: ['needs' => 'app', 'app' => 'salesforce', 'steps' => ['Propertybase runs on Salesforce: set up the Salesforce card first (the same connected app works).', 'Switch Propertybase on.'],
                'partner' => 'A Propertybase (Lone Wolf) marketplace listing is optional.', 'docs' => 'https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/'],
            guide: ['steps' => ['Press Connect and sign in to Propertybase (your Salesforce login) as an admin.', 'Press Allow. Your contacts and open leads start coming over straight away.']]));

        // Lead sources (specs/connect/10).
        $catalog->add(new App('facebook_leads', 'Facebook Lead Ads', 'leads', 'Every Facebook and Instagram lead form arrives as a lead within seconds, routed and followed up. Connect once; all your Pages are included.',
            scope: 'both', auth: 'oauth', syncs: ['New leads from every Page you manage, within seconds', 'Form answers, ad and campaign names'],
            driver: FacebookLeads::class, vendorCost: 'Your ad spend with Meta',
            oauth: ['provider' => 'meta', 'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_metadata', 'pages_manage_ads', 'leads_retrieval'],
                'authorize' => 'https://www.facebook.com/v21.0/dialog/oauth', 'token' => 'https://graph.facebook.com/v21.0/oauth/access_token',
                'userinfo' => 'https://graph.facebook.com/v21.0/me?fields=name,email', 'pkce' => false],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Meta App ID'], ['key' => 'client_secret', 'label' => 'Meta App secret', 'secret' => true],
                ['key' => 'verify_token', 'label' => 'Webhook verify token (any long random text)', 'secret' => true]],
                'steps' => ['At developers.facebook.com, create a Business app called RightAlly, and verify the business (Business Verification).',
                    'Add "Facebook Login for Business" and put the return address shown above under Valid OAuth Redirect URIs.',
                    'Add "Webhooks", choose Page, use the webhook address shown above as the callback URL and your verify token, then subscribe to the "leadgen" field.',
                    'Request Advanced Access (App Review) for leads_retrieval, pages_manage_metadata, pages_manage_ads, pages_read_engagement and pages_show_list.',
                    'Paste the App ID, App secret and verify token here, then switch Facebook Lead Ads on.'],
                'partner' => 'Meta App Review and Business Verification are needed before brokers outside your test users can connect (free, usually a few days).',
                'docs' => 'https://developers.facebook.com/docs/marketing-api/guides/lead-ads/retrieving'],
            guide: ['steps' => ['Press Connect and sign in to Facebook.', 'Choose the Pages your lead ads run on, and press Allow.', 'That\'s all. New leads arrive here within seconds.']]));

        $catalog->add(new App('realtor_com', 'Realtor.com', 'leads', 'Realtor.com leads (including ReadyConnect Concierge) arrive as leads within a minute, routed and followed up.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the portal\'s email'], link: '/leads/settings', vendorCost: 'Paid to Realtor.com',
            setup: ['partner' => 'A direct Realtor.com lead feed (instead of email) needs Realtor.com partner access: optional, ask Move, Inc.'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In Realtor.com Professional, open Settings → Lead delivery and add that address as a lead recipient.', 'New Realtor.com leads now arrive here, read and routed automatically.']]));
        $catalog->add(new App('zillow', 'Zillow Premier Agent', 'leads', 'Zillow and Trulia leads arrive as leads within a minute, routed and followed up. Two-way sync comes with Zillow partner access.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the portal\'s email'], link: '/leads/settings', vendorCost: 'Paid to Zillow',
            setup: ['partner' => 'Two-way sync (notes and status back to Zillow) is only for Zillow CRM partners. RightAlly must apply as a Zillow partner.'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In Zillow Premier Agent, open Settings → Contact delivery (or your CRM settings) and add that address.', 'New Zillow leads now arrive here, read and routed automatically.']]));
        $catalog->add(new App('homes_com', 'Homes.com', 'leads', 'Homes.com leads arrive as leads within a minute, routed and followed up.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the portal\'s email'], link: '/leads/settings', vendorCost: 'Paid to Homes.com',
            setup: ['partner' => null],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In your Homes.com agent account, add that address where new leads are emailed.', 'New Homes.com leads now arrive here, read and routed automatically.']]));

        // Google Local Services Ads: one click with Google; leads every 15 minutes (specs/connect/11).
        $catalog->add(new App('google_lsa', 'Google Local Services Ads', 'leads', 'Every call, message and booking from your Google Guaranteed ads becomes a lead within 15 minutes, routed and followed up.',
            scope: 'both', auth: 'oauth', syncs: ['Calls, messages and bookings from Local Services Ads', 'Checked every 15 minutes'],
            driver: GoogleLsa::class, vendorCost: 'Your ad spend with Google',
            oauth: ['provider' => 'google', 'scopes' => ['https://www.googleapis.com/auth/adwords']],
            setup: ['needs' => 'provider', 'provider' => 'google', 'fields' => [['key' => 'developer_token', 'label' => 'Google Ads developer token', 'secret' => true]],
                'steps' => ['Set up the Google sign-in app at the top of this page (return addresses, client ID and secret), and add the Google Ads API to it.',
                    'In RightAlly\'s Google Ads manager account, open API Center and apply for a developer token (Basic access is enough).',
                    'Add the "adwords" scope to the Google app\'s verification (it is a sensitive scope: Google reviews it, free).', 'Paste the developer token here and switch Google Local Services Ads on.'],
                'partner' => 'Google Ads API developer token (free; Basic access review) and Google OAuth verification for the adwords scope.',
                'docs' => 'https://developers.google.com/google-ads/api/docs/local-services/leads'],
            guide: ['steps' => ['Press Connect and sign in with the Google account that runs your Local Services Ads.', 'Press Allow. New leads arrive here within 15 minutes.']]));

        // Other lead vendors: their leads come in through your lead address (email) or the Lead API (Zapier); a direct
        // feed needs a partner agreement with each vendor (shown to HQ).
        $catalog->add(new App('ylopo', 'Ylopo', 'leads', 'Ylopo leads (and the ones its AI texts with) arrive as leads within a minute.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the vendor\'s email'], link: '/leads/settings', vendorCost: 'Paid to Ylopo',
            setup: ['partner' => 'A direct Ylopo feed (with lead activity) is for Ylopo\'s integration partners. RightAlly can apply through Ylopo.'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In Ylopo, add that address where new leads are emailed (or ask Ylopo support to send new leads to it).',
                'New leads now arrive here, read and routed automatically. Using Zapier instead? Use the Lead API app.']]));
        $catalog->add(new App('redfin_partner', 'Redfin Partner Agent', 'leads', 'Redfin partner referrals arrive as leads within a minute, routed and followed up.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the vendor\'s email'], link: '/leads/settings', vendorCost: 'Referral fee to Redfin',
            setup: ['partner' => null],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In Redfin Partner Agent, add that address where new leads are emailed (or ask Redfin Partner Agent support to send new leads to it).',
                'New leads now arrive here, read and routed automatically. Using Zapier instead? Use the Lead API app.']]));
        $catalog->add(new App('market_leader', 'Market Leader', 'leads', 'Market Leader leads arrive as leads within a minute.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the vendor\'s email'], link: '/leads/settings', vendorCost: 'Paid to Market Leader',
            setup: ['partner' => 'A direct Market Leader feed needs a partner agreement with Market Leader (Constellation Real Estate Group).'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In Market Leader, add that address where new leads are emailed (or ask Market Leader support to send new leads to it).',
                'New leads now arrive here, read and routed automatically. Using Zapier instead? Use the Lead API app.']]));
        $catalog->add(new App('landvoice', 'Landvoice', 'leads', 'Expired, FSBO and pre-foreclosure leads from Landvoice arrive as leads.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the vendor\'s email'], link: '/leads/settings', vendorCost: 'Paid to Landvoice',
            setup: ['partner' => 'A direct Landvoice feed needs a partner agreement with Landvoice.'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In Landvoice, add that address where new leads are emailed (or ask Landvoice support to send new leads to it).',
                'New leads now arrive here, read and routed automatically. Using Zapier instead? Use the Lead API app.']]));
        $catalog->add(new App('smartzip', 'SmartZip', 'leads', 'Likely-seller leads from SmartZip arrive as leads within a minute.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the vendor\'s email'], link: '/leads/settings', vendorCost: 'Paid to SmartZip',
            setup: ['partner' => 'A direct SmartZip feed needs a partner agreement with SmartZip.'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In SmartZip, add that address where new leads are emailed (or ask SmartZip support to send new leads to it).',
                'New leads now arrive here, read and routed automatically. Using Zapier instead? Use the Lead API app.']]));
        $catalog->add(new App('redx', 'REDX', 'leads', 'Expired, FSBO and pre-foreclosure leads from REDX arrive as leads.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the vendor\'s email'], link: '/leads/settings', vendorCost: 'Paid to REDX',
            setup: ['partner' => 'A direct REDX feed needs a partner agreement with REDX.'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In REDX, add that address where new leads are emailed (or ask REDX support to send new leads to it).',
                'New leads now arrive here, read and routed automatically. Using Zapier instead? Use the Lead API app.']]));
        $catalog->add(new App('vulcan7', 'Vulcan7', 'leads', 'Expired and FSBO leads from Vulcan7 arrive as leads.',
            scope: 'both', auth: 'email', syncs: ['New leads, within a minute of the vendor\'s email'], link: '/leads/settings', vendorCost: 'Paid to Vulcan7',
            setup: ['partner' => 'A direct Vulcan7 feed needs a partner agreement with Vulcan7.'],
            guide: ['steps' => ['Copy your RightAlly lead address below (or make one under Leads → Settings).', 'In Vulcan7, add that address where new leads are emailed (or ask Vulcan7 support to send new leads to it).',
                'New leads now arrive here, read and routed automatically. Using Zapier instead? Use the Lead API app.']]));

        // Ads from the person's own ad account (specs/ads/01).
        $catalog->add(new App('meta_ads', 'Meta Ads (Facebook and Instagram)', 'ads', 'Make listing ads with AI and publish them to Facebook and Instagram from your own ad account, without leaving RightAlly. Results come back here.',
            scope: 'agent', auth: 'oauth', syncs: ['Your ad accounts and Pages', 'Ads you publish from RightAlly', 'Spend, clicks and results, hourly'], link: '/ads',
            driver: MetaAdsConnector::class, vendorCost: 'Your ad spend, billed by Meta to your ad account',
            oauth: ['provider' => 'meta', 'scopes' => ['ads_management', 'ads_read', 'pages_show_list', 'pages_read_engagement', 'pages_manage_ads'],
                'authorize' => 'https://www.facebook.com/v21.0/dialog/oauth', 'token' => 'https://graph.facebook.com/v21.0/oauth/access_token',
                'userinfo' => 'https://graph.facebook.com/v21.0/me?fields=name,email', 'pkce' => false, 'keys_from' => 'facebook_leads'],
            setup: ['needs' => 'app', 'app' => 'facebook_leads', 'steps' => ['Meta Ads uses the same Meta app as Facebook Lead Ads: set that card up first.',
                'In the Meta app, request Advanced Access (App Review) for ads_management and ads_read as well.', 'Switch Meta Ads on.'],
                'partner' => 'Meta App Review for ads_management and ads_read (free). Ads run as Housing ads, so Meta limits targeting to place.',
                'docs' => 'https://developers.facebook.com/docs/marketing-api/'],
            guide: ['steps' => ['Press Connect and sign in to Facebook.', 'Choose your ad account and Pages, and press Allow.', 'Then open Ads to make your first listing ad.']]));

        $catalog->add(new App('rightally_boost', 'RightAlly Boost', 'ads', 'Boost a listing on Facebook and Instagram and pay with RightAlly credits: no ad account needed. Unspent credits come back.',
            scope: 'both', auth: 'link', syncs: ['Ads paid with credits', 'Results with the leads and commission they brought'], link: '/ads',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'ad_account_id', 'label' => 'RightAlly\'s Meta ad account ID (digits)'], ['key' => 'page_id', 'label' => 'RightAlly\'s Facebook Page ID'],
                ['key' => 'access_token', 'label' => 'System user access token (ads_management)', 'secret' => true], ['key' => 'margin_percent', 'label' => 'RightAlly margin, % of ad spend (default 15)']],
                'steps' => ['In RightAlly\'s Meta Business Manager, make (or choose) the ad account Boosts run from, with a payment method, and the Facebook Page ads run as.',
                    'Make a system user with the ads_management permission on that ad account and Page, and generate its access token.',
                    'Paste the ad account ID, Page ID and token here. Set the margin (for example 15); you can change it any time and each boost keeps the margin it was bought with.',
                    'Switch RightAlly Boost on.'],
                'partner' => 'Uses the Meta app already approved for ads_management (Meta Ads card). RightAlly pays Meta for Boost spend.',
                'docs' => 'https://developers.facebook.com/docs/marketing-api/system-users/']));

        // Transaction management: switch in a weekend (specs/connect/12).
        $catalog->add(new App('dotloop', 'dotloop', 'transactions', 'Bring every loop over as a deal, with its people, contract dates and documents, in one click. Changes keep coming over within seconds.',
            scope: 'both', auth: 'oauth', syncs: ['Every loop as a deal: address, price, status, agent', 'Buyers, sellers, lender, title and other parties', 'Contract dates and deadlines', 'Documents (PDF)', 'Changes, within seconds'],
            driver: Dotloop::class, vendorCost: 'Your dotloop plan',
            oauth: ['provider' => 'dotloop', 'scopes' => [], // dotloop grants scopes to the app (account:read, profile:read, loop:read), not per request
                'authorize' => 'https://auth.dotloop.com/oauth/authorize',
                'token' => 'https://auth.dotloop.com/oauth/token', 'userinfo' => 'https://api-gateway.dotloop.com/public/v2/account', 'response_type' => 'code', 'pkce' => false,
                'token_auth' => 'basic', 'token_state' => true, 'params' => ['redirect_on_deny' => 'true']],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Client ID'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['Register RightAlly at info.dotloop.com/developers and accept the dotloop API License Agreement.',
                    'When dotloop approves the app, ask for the scopes account:read, profile:read and loop:read, and set its redirect URL to the return address shown above.',
                    'Paste the client ID and client secret here, and save.', 'Switch dotloop on. Live updates use the webhook address shown above automatically.'],
                'partner' => 'dotloop reviews each API app before issuing a client ID and secret (free). A listing in dotloop\'s integrations directory is optional.',
                'docs' => 'https://dotloop.github.io/public-api/'],
            guide: ['steps' => ['Press Connect and sign in to dotloop (a broker or admin login brings the whole office).', 'Press Allow.',
                'Your loops start coming over straight away, with their documents. Big offices take a few hours; you can keep working meanwhile.']]));

        $catalog->add(new App('skyslope', 'SkySlope', 'transactions', 'Bring every SkySlope sale and listing over as a deal, with its agent, parties, dates and commission. Changes keep coming over every hour.',
            scope: 'brokerage', auth: 'api_key', syncs: ['Every sale and listing as a deal: address, price, status, agent', 'Buyers, sellers, title, escrow, lender and attorney', 'Contract and closing dates, office commission', 'Changes, hourly', 'Documents: from SkySlope\'s file export (Deals → Switch from another app)'],
            fields: [['key' => 'access_key', 'label' => 'SkySlope access key', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.'],
                ['key' => 'access_secret', 'label' => 'SkySlope access secret', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted and is never shown again.']],
            driver: SkySlope::class, vendorCost: 'Your SkySlope plan',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'SkySlope client ID'], ['key' => 'client_secret', 'label' => 'SkySlope client secret', 'secret' => true]],
                'steps' => ['Ask SkySlope\'s integrations team to onboard RightAlly as an API partner. They issue a client ID and client secret.',
                    'Paste the client ID and secret here, and save.', 'Switch SkySlope on. Each brokerage then pastes its own access key and secret.'],
                'partner' => 'SkySlope issues API client credentials through partner onboarding (no listing fee known). Each brokerage still uses its own access key.',
                'request' => [// address: SkySlope's integrations contact (not published)
                    'subject' => 'API partner onboarding for RightAlly (Transaction Management API)',
                    'body' => "Hello SkySlope team,\n\nRightAlly is an all-in-one platform for US real estate brokerages. Brokerages moving to RightAlly want to bring their SkySlope sales, listings, parties and dates over with their own access keys (read only).\n\nPlease onboard RightAlly as a Transaction Management API partner and issue a client ID and client secret.\n\nPlease send the credentials through a secure channel (not plain email).\n\nThank you,\nRightAlly HQ"],
                'docs' => 'https://api.skyslope.com/api/docs/openid/SkySlopeApi.html'],
            guide: ['steps' => ['In SkySlope, open My Account → Integrations, and generate an access key (a broker or admin login brings the whole brokerage).',
                'Copy the access key and the access secret.', 'Paste both below and press Connect. Your deals start coming over straight away; big brokerages take a few hours.']]));
        $catalog->add(new App('lone_wolf', 'Lone Wolf', 'transactions', 'Bring every Lone Wolf deal (TransactionDesk and brokerWOLF) over, with its agent, contacts, dates and price. Changes keep coming over every hour.',
            scope: 'brokerage', auth: 'api_key', syncs: ['Every deal: address, price, status, agent', 'Client and business contacts, other-side agents', 'Offer, firm, closing and finalised dates', 'Your roster, to match agents', 'Changes, hourly', 'Documents: from Lone Wolf\'s file export (Deals → Switch from another app)'],
            fields: [['key' => 'email', 'label' => 'Email of the RightAlly user in Lone Wolf', 'type' => 'text'],
                ['key' => 'password', 'label' => 'That user\'s password', 'type' => 'text', 'secret' => true, 'help' => 'Use a separate, read-only Lone Wolf user made just for RightAlly, never a person\'s own login. It stays encrypted and is never shown again.']],
            driver: LoneWolf::class, vendorCost: 'Your Lone Wolf plan',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Lone Wolf client ID']],
                'steps' => ['Apply to become a Lone Wolf API partner (Lone Wolf partner team). They issue RightAlly a client ID for the Deals and Roster APIs.',
                    'Paste the client ID here, and save.', 'Switch Lone Wolf on. Each brokerage then adds a read-only user for RightAlly in Lone Wolf and enters its login.'],
                'partner' => 'Partner only: Lone Wolf must approve RightAlly as an API partner before any brokerage can connect.',
                'request' => [// address: from Lone Wolf's partner team (not published)
                    'subject' => 'API partner access for RightAlly (Deals and Roster APIs)',
                    'body' => "Hello Lone Wolf partner team,\n\nRightAlly is an all-in-one platform for US real estate brokerages. Brokerages that use TransactionDesk and brokerWOLF and are moving to RightAlly want to bring their deals, contacts and roster over (read only).\n\nWe would like to become a Lone Wolf API partner and receive a client ID for the Deals and Roster APIs, plus sandbox access to test.\n\nPlease send the credentials through a secure channel (not plain email).\n\nThank you,\nRightAlly HQ"],
                'docs' => 'https://apidocs.lwolf.com/'],
            guide: ['steps' => ['In Lone Wolf, add a new user just for RightAlly with read-only access to deals and the roster.',
                'Enter that user\'s email and password below and press Connect.', 'Your deals start coming over straight away; big brokerages take a few hours.']]));

        $catalog->add(new App('open_to_close', 'Open to Close', 'transactions', 'Bring every Open to Close transaction over as a deal, with its agent, parties and dates. Changes keep coming over every hour.',
            scope: 'brokerage', auth: 'api_key', syncs: ['Every transaction as a deal: address, price, status, agent', 'Buyers, sellers, lender, title and other contacts', 'Contract, closing and other dates', 'Changes, hourly'],
            fields: [['key' => 'api_token', 'label' => 'Open to Close API token', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.']],
            driver: OpenToClose::class, vendorCost: 'Your Open to Close plan',
            setup: ['steps' => ['Nothing to set up: each brokerage uses its own Open to Close API token.', 'Switch Open to Close on.'], 'partner' => null,
                'docs' => 'https://docs.opentoclose.com/'],
            guide: ['steps' => ['In Open to Close, open the Apps & API dashboard.', 'Create or copy your API token.', 'Paste it below and press Connect. Your deals start coming over straight away.']]));
        $catalog->add(new App('brokermint', 'Brokermint', 'transactions', 'Bring every Brokermint transaction over as a deal, with its agent, contacts, dates and commission. Changes keep coming over every hour.',
            scope: 'brokerage', auth: 'api_key', syncs: ['Every transaction as a deal: address, price, status, agent', 'Buyers, sellers, lender, title and other contacts', 'Acceptance and closing dates, gross commission', 'Changes, hourly'],
            fields: [['key' => 'api_key', 'label' => 'Brokermint API token', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.']],
            driver: Brokermint::class, vendorCost: 'Your Brokermint plan',
            setup: ['steps' => ['Nothing to set up: each brokerage asks Brokermint support for its own API token.', 'Switch Brokermint on.'],
                'partner' => 'Brokermint issues API tokens through its support team (no partner agreement known). A listing in Brokermint\'s integrations is optional.',
                'docs' => 'https://brokermint.com/api/'],
            guide: ['steps' => ['Email Brokermint support and ask for your brokerage\'s API token.', 'Paste the token below and press Connect.', 'Your deals start coming over straight away.']]));
        $catalog->add(new App('docusign', 'DocuSign', 'transactions', 'Every completed DocuSign envelope is filed on the right deal automatically, as one signed PDF with its certificate.',
            scope: 'both', auth: 'oauth', syncs: ['Completed envelopes, filed on the deal their subject names (or their "RightAlly deal" field)', 'One signed PDF with the certificate', 'New envelopes every 15 minutes'],
            driver: DocuSign::class, vendorCost: 'Your DocuSign plan',
            oauth: ['provider' => 'docusign', 'scopes' => ['signature', 'extended'], 'authorize' => 'https://account.docusign.com/oauth/auth',
                'token' => 'https://account.docusign.com/oauth/token', 'userinfo' => DocuSign::USERINFO, 'response_type' => 'code', 'pkce' => false, 'token_auth' => 'basic'],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Integration key'], ['key' => 'client_secret', 'label' => 'Secret key', 'secret' => true]],
                'steps' => ['In a DocuSign developer account, open Apps and Keys → Add App and Integration Key, and name it RightAlly.',
                    'Choose "Authorization Code Grant", add a secret key, and add the return address shown above as a redirect URI.',
                    'Test it, then request Go-Live for the integration key (DocuSign reviews 20 successful test calls).', 'Paste the integration key and secret key here, then switch DocuSign on.'],
                'partner' => 'DocuSign Go-Live review is needed before brokers outside the developer account can connect (free). Becoming a DocuSign partner is optional.',
                'docs' => 'https://developers.docusign.com/docs/esign-rest-api/'],
            guide: ['steps' => ['Press Connect and sign in to DocuSign.', 'Press Accept.', 'From now on, put the property address in each envelope\'s subject ("44 Pecan Dr purchase contract"); completed envelopes are filed on that deal.']]));

        // Accounting: RightAlly's journals posted to the brokerage's books (specs/connect/13).
        $catalog->add(new App('quickbooks', 'QuickBooks Online', 'accounting', 'Commissions, fees, revenue share and payouts post to your QuickBooks books automatically, as journal entries.',
            scope: 'brokerage', auth: 'oauth', syncs: ['Every RightAlly journal from the day you connect, as a journal entry', 'Your accounts mapped once under Finance → Books', 'New journals every hour'],
            driver: QuickBooks::class, vendorCost: 'Your QuickBooks plan', link: '/finance/books',
            oauth: ['provider' => 'quickbooks', 'scopes' => ['com.intuit.quickbooks.accounting', 'openid', 'email'], 'authorize' => 'https://appcenter.intuit.com/connect/oauth2',
                'token' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', 'userinfo' => 'https://accounts.platform.intuit.com/v1/openid_connect/userinfo',
                'response_type' => 'code', 'pkce' => false, 'token_auth' => 'basic'],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Client ID'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['At developer.intuit.com, create an app for QuickBooks Online and Payments with the Accounting scope.',
                    'Under Keys & credentials (Production), add the return address shown above as a redirect URI.',
                    'Complete Intuit\'s production questionnaire (app details, privacy policy and terms URLs), then copy the production client ID and secret here.', 'Switch QuickBooks Online on.'],
                'partner' => 'Intuit reviews the production questionnaire before live companies can connect (free). A QuickBooks App Store listing is optional.',
                'docs' => 'https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/journalentry'],
            guide: ['steps' => ['Press Connect, sign in to QuickBooks as an admin, and choose your company.', 'Press Connect again in QuickBooks.', 'Then map your accounts once under Finance → Books. Journals start posting straight away.']]));
        $catalog->add(new App('xero', 'Xero', 'accounting', 'Commissions, fees, revenue share and payouts post to your Xero books automatically, as manual journals.',
            scope: 'brokerage', auth: 'oauth', syncs: ['Every RightAlly journal from the day you connect, as a manual journal', 'Your accounts mapped once under Finance → Books', 'New journals every hour'],
            driver: Xero::class, vendorCost: 'Your Xero plan', link: '/finance/books',
            oauth: ['provider' => 'xero', 'scopes' => ['openid', 'profile', 'email', 'offline_access', 'accounting.manualjournals', 'accounting.settings'],
                'authorize' => 'https://login.xero.com/identity/connect/authorize', 'token' => 'https://identity.xero.com/connect/token',
                'userinfo' => 'https://identity.xero.com/connect/userinfo', 'response_type' => 'code', 'pkce' => true, 'token_auth' => 'basic'],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Client ID'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['At developer.xero.com, create a Web app called RightAlly, with the return address shown above as its redirect URI.',
                    'It uses Xero\'s granular scopes (accounting.manualjournals and accounting.settings).', 'Generate a client secret and paste the client ID and secret here.',
                    'Switch Xero on. Connecting more than 25 organisations needs a Xero app partnership (certification).'],
                'partner' => 'Free for up to 25 connected organisations; beyond that Xero requires its App Partner certification, and Xero\'s developer pricing applies.',
                'docs' => 'https://developer.xero.com/documentation/api/accounting/manualjournals'],
            guide: ['steps' => ['Press Connect, sign in to Xero, and choose your organisation.', 'Press Allow access.', 'Then map your accounts once under Finance → Books. Journals start posting straight away.']]));

        // On the roadmap (people can ask for them; HQ sees the demand). Some need a partner agreement first.
        foreach ([
            ['real_geeks', 'Real Geeks', 'crm', 'Bring over leads and their activity from Real Geeks.', 'both', 'Your Real Geeks plan'],
            ['boomtown', 'BoomTown', 'crm', 'Bring over leads and notes from BoomTown.', 'both', 'Your BoomTown plan'],
            ['wise_agent', 'Wise Agent', 'crm', 'Bring over contacts and notes from Wise Agent.', 'agent', 'Your Wise Agent plan'],
            ['top_producer', 'Top Producer', 'crm', 'Bring over contacts and notes from Top Producer.', 'agent', 'Your Top Producer plan'],
            ['cinc', 'CINC', 'crm', 'Bring over leads, notes and activity from CINC.', 'both', 'Your CINC plan'],
            ['brivity', 'Brivity', 'crm', 'Bring over contacts and transactions from Brivity.', 'both', 'Your Brivity plan'],
            ['liondesk', 'LionDesk', 'crm', 'Bring over contacts and notes from LionDesk.', 'agent', 'Your LionDesk plan'],
            ['rechat', 'Rechat', 'crm', 'Bring over contacts, deals and marketing history from Rechat.', 'both', 'Your Rechat plan'],
            ['cloze', 'Cloze', 'crm', 'Bring over contacts, notes and relationship history from Cloze.', 'agent', 'Your Cloze plan'],
            ['nextdoor_ads', 'Nextdoor Ads', 'ads', 'Neighbourhood ads for your listings and open houses.', 'both', 'Ad spend paid to Nextdoor'],
            ['google_ads', 'Google Ads', 'ads', 'Search, display and YouTube ads for your listings and your area, created and published from RightAlly.', 'both', 'Ad spend: your Google account, or RightAlly Boost credits'],
            ['paperless_pipeline', 'Paperless Pipeline', 'transactions', 'Bring transactions and documents from Paperless Pipeline.', 'brokerage', 'Your Paperless Pipeline plan'],
            ['authentisign', 'Authentisign', 'transactions', 'Signed Authentisign documents filed on the right deal.', 'both', 'Your Authentisign plan'],
            ['zipform', 'zipForm', 'transactions', 'zipForm transactions and forms into RightAlly deals.', 'agent', 'Your zipForm plan'],
            ['form_simplicity', 'Form Simplicity', 'transactions', 'Form Simplicity transactions and forms into RightAlly deals.', 'agent', 'Your Form Simplicity plan'],
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
