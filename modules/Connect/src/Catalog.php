<?php

declare(strict_types=1);

namespace Modules\Connect;

use Modules\Ads\Google\GoogleAdsConnector;
use Modules\Ads\Meta\MetaAdsConnector;
use Modules\Ads\Social\FacebookPages;
use Modules\Ads\Social\GoogleBusiness;
use Modules\Ads\Social\LinkedIn;
use Modules\Ads\Social\YouTube;
use Modules\Ads\Studio\Canva\CanvaConnector;
use Modules\Core\Connect\App;
use Modules\Core\Connect\AppCatalog;
use Modules\Crm\Calls\Aircall;
use Modules\Crm\Calls\Dialpad;
use Modules\Crm\Calls\RingCentral;
use Modules\Crm\Connect\BoldTrail;
use Modules\Crm\Connect\ConstantContact;
use Modules\Crm\Connect\FacebookLeads;
use Modules\Crm\Connect\FollowUpBoss;
use Modules\Crm\Connect\GoHighLevel;
use Modules\Crm\Connect\GoogleLsa;
use Modules\Crm\Connect\HubSpot;
use Modules\Crm\Connect\Lofty;
use Modules\Crm\Connect\Mailchimp;
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
        'corefact' => 'Corefact and ProspectsPLUS print for agents through their own sites; ask them for partner (API) access. RightAlly Postcards (Lob) works today.',
        'mojo' => 'Mojo Dialer has no public API. Ask Mojo (mojosells.com) for partner access; meanwhile agents can export a smart list to CSV and import it into Mojo.',
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
        $catalog->add(new App('gohighlevel', 'GoHighLevel', 'crm', 'Bring over your contacts, tags, source, agents and notes from a GoHighLevel sub-account, and put RightAlly contacts into your GoHighLevel campaigns.',
            scope: 'both', auth: 'api_key', syncs: ['Contacts (names, emails, phones)', 'Tags and source', 'Which user each contact is assigned to', 'Notes', 'Do not disturb, as opt-outs', 'Changed contacts, hourly',
                'RightAlly tags or smart lists into your workflows, campaigns or trigger tags (consent checked), hourly'], link: '/settings/ghl-campaigns',
            fields: [['key' => 'api_key', 'label' => 'Private Integration token', 'type' => 'text', 'secret' => true, 'help' => 'It stays encrypted. Only RightAlly\'s import uses it.'],
                ['key' => 'location_id', 'label' => 'Location ID', 'type' => 'text', 'help' => 'Settings → Business Profile in the same sub-account.']],
            driver: GoHighLevel::class, vendorCost: 'Your GoHighLevel plan',
            setup: ['steps' => ['Nothing to set up: each sub-account uses its own Private Integration token.', 'Switch GoHighLevel on.'],
                'partner' => 'One-click connect (instead of a token) needs a GoHighLevel Marketplace app: optional, apply at marketplace.gohighlevel.com.',
                'docs' => 'https://marketplace.gohighlevel.com/docs/'],
            guide: ['steps' => ['In your GoHighLevel sub-account, open Settings → Private Integrations and create one.', 'Tick "View Contacts" (and "View Users", so contacts keep their agent). To use campaigns, also tick "Edit Contacts", "View Workflows" and "View Campaigns". Then copy the token.',
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

        // Google search and YouTube listing ads (specs/ads/05): one click with Google; RightAlly's developer token.
        $catalog->add(new App('google_ads', 'Google Ads (search and YouTube)', 'ads', 'Make listing ads for Google search and YouTube with AI and publish them from your own Google Ads account. Results come back here.',
            scope: 'agent', auth: 'oauth', syncs: ['Your Google Ads accounts', 'Ads you publish from RightAlly', 'Spend, clicks and results, daily'], link: '/ads',
            driver: GoogleAdsConnector::class, vendorCost: 'Your ad spend, billed by Google to your Google Ads account',
            oauth: ['provider' => 'google', 'scopes' => ['https://www.googleapis.com/auth/adwords']],
            setup: ['needs' => 'provider', 'provider' => 'google', 'fields' => [['key' => 'developer_token', 'label' => 'Google Ads developer token', 'secret' => true]],
                'steps' => ['Set up the Google sign-in app at the top of this page, and add the Google Ads API to it.',
                    'In RightAlly\'s Google Ads manager account, open API Center and apply for Standard access for the developer token (creating campaigns needs it).',
                    'Add the "adwords" scope to the Google app\'s verification (a sensitive scope: Google reviews it, free).', 'Paste the developer token here and switch Google Ads on.'],
                'partner' => 'Google Ads API developer token with Standard access (free review) and Google OAuth verification for the adwords scope. Ads follow Google\'s housing ads policy: place only.',
                'docs' => 'https://developers.google.com/google-ads/api/docs/start'],
            guide: ['steps' => ['Press Connect and sign in with the Google account that runs your Google Ads.', 'Press Allow.', 'Then open Ads and choose Google search or YouTube.']]));

        $catalog->add(new App('rightally_boost_google', 'RightAlly Boost on Google', 'ads', 'Boost a listing on Google search and YouTube and pay with RightAlly credits: no Google Ads account needed.',
            scope: 'both', auth: 'link', syncs: ['Google and YouTube ads paid with credits'], link: '/ads',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'customer_id', 'label' => 'RightAlly\'s Google Ads customer ID (digits)'],
                ['key' => 'login_customer_id', 'label' => 'Manager account ID it is reached through (digits; the same ID if none)'],
                ['key' => 'refresh_token', 'label' => 'Refresh token for RightAlly\'s Google Ads login (adwords scope)', 'secret' => true]],
                'steps' => ['Set up RightAlly Boost (Meta) and Google Ads first: Boost on Google uses the same margin and Google Ads\' developer token.',
                    'In RightAlly\'s Google Ads manager account, make (or choose) the account Boosts run from, with billing set up.',
                    'Sign in once with that Google login through the OAuth playground (or a script) using the Google sign-in app, with the adwords scope and offline access, and copy the refresh token.',
                    'Paste the IDs and the refresh token here and switch it on.'],
                'partner' => 'Uses the Google Ads developer token (Google Ads card). RightAlly pays Google for Boost spend.',
                'docs' => 'https://developers.google.com/google-ads/api/docs/oauth/overview']));

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
                    'Switch Xero on, and choose the Xero developer tier as brokerages connect (see below).'],
                'partner' => 'Xero charges per app by connected organisations (2026, AUD a month): Starter free up to 5, Core A$35 up to 50, Plus A$245 up to 1,000. RightAlly uses one app; listing on the Xero App Store needs App Partner certification.',
                'docs' => 'https://developer.xero.com/documentation/api/accounting/manualjournals'],
            guide: ['steps' => ['Press Connect, sign in to Xero, and choose your organisation.', 'Press Allow access.', 'Then map your accounts once under Finance → Books. Journals start posting straight away.']]));

        // Email marketing (specs/connect/15, C7a): consented contacts out, unsubscribes back.
        $catalog->add(new App('mailchimp', 'Mailchimp', 'marketing', 'Contacts who agreed to email go to your Mailchimp audience with their tags; unsubscribes come back and stop email here.',
            scope: 'both', auth: 'oauth', syncs: ['Contacts who agreed to email (or, if you choose, everyone not opted out), every hour', 'Their tags', 'Unsubscribes and cleaned addresses back, within seconds'],
            driver: Mailchimp::class, vendorCost: 'Your Mailchimp plan', link: '/settings/email-marketing',
            oauth: ['provider' => 'mailchimp', 'scopes' => [], 'authorize' => 'https://login.mailchimp.com/oauth2/authorize', 'token' => 'https://login.mailchimp.com/oauth2/token',
                'userinfo' => '', 'response_type' => 'code', 'pkce' => false, 'token_auth' => 'post'],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Client ID'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['In Mailchimp, go to Account & billing → Extras → Registered apps and register an app called RightAlly.', 'Use the return address shown above as its redirect URI.',
                    'Copy the client ID and secret here and switch Mailchimp on.'],
                'partner' => 'Free. Listing in the Mailchimp Marketplace is optional and reviewed by Mailchimp.', 'docs' => 'https://mailchimp.com/developer/marketing/guides/access-user-data-oauth-2/'],
            guide: ['steps' => ['Press Connect and sign in to Mailchimp.', 'Choose your audience and who goes under Settings → Email marketing.', 'Contacts start going on the next run (hourly).']]));
        $catalog->add(new App('constant_contact', 'Constant Contact', 'marketing', 'Contacts who agreed to email go to your Constant Contact list; unsubscribes come back and stop email here.',
            scope: 'both', auth: 'oauth', syncs: ['Contacts who agreed to email (or, if you choose, everyone not opted out), every hour', 'Unsubscribes back, every hour'],
            driver: ConstantContact::class, vendorCost: 'Your Constant Contact plan', link: '/settings/email-marketing',
            oauth: ['provider' => 'constant_contact', 'scopes' => ['contact_data', 'offline_access'], 'authorize' => 'https://authz.constantcontact.com/oauth2/default/v1/authorize',
                'token' => 'https://authz.constantcontact.com/oauth2/default/v1/token', 'userinfo' => '', 'response_type' => 'code', 'pkce' => true, 'token_auth' => 'basic'],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'API key (client ID)'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['At developer.constantcontact.com, create an application called RightAlly using the authorization code flow with PKCE and rotating refresh tokens.',
                    'Add the return address shown above as its redirect URI.', 'Generate a client secret, copy the API key and secret here, and switch Constant Contact on.'],
                'partner' => 'Free for your own app. Constant Contact\'s Technology Partner program is optional.', 'docs' => 'https://developer.constantcontact.com/api_guide/auth_overview.html'],
            guide: ['steps' => ['Press Connect and sign in to Constant Contact.', 'Choose your list and who goes under Settings → Email marketing.', 'Contacts start going on the next run (hourly).']]));

        // Phones (specs/connect/15, C7b): calls and texts on the contact's timeline, hourly. Unknown numbers create nothing.
        $catalog->add(new App('ringcentral', 'RingCentral', 'phones', 'Calls and texts from RingCentral logged on the matching contacts; a call to a new lead counts as your response.',
            scope: 'both', auth: 'oauth', syncs: ['Calls (direction, length, outcome), hourly', 'Texts, hourly', 'Recording links, if you switch them on'],
            driver: RingCentral::class, vendorCost: 'Your RingCentral plan',
            oauth: ['provider' => 'ringcentral', 'scopes' => [], 'authorize' => 'https://platform.ringcentral.com/restapi/oauth/authorize', 'token' => 'https://platform.ringcentral.com/restapi/oauth/token',
                'userinfo' => '', 'response_type' => 'code', 'pkce' => true, 'token_auth' => 'basic'],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Client ID'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['At developers.ringcentral.com, create a REST API app called RightAlly (server-side web app, 3-legged OAuth).', 'Give it the Read Call Log and Read Messages permissions, and the return address shown above as its redirect URI.',
                    'Apply for production (RingCentral reviews it), then copy the client ID and secret here and switch RingCentral on.'],
                'partner' => 'Free. RingCentral reviews the app before production; listing in the RingCentral App Gallery is optional.', 'docs' => 'https://developers.ringcentral.com/api-reference/Call-Log/readUserCallLog'],
            guide: ['steps' => ['Press Connect and sign in to RingCentral. For the brokerage\'s whole account, a RingCentral admin signs in.', 'Calls and texts start appearing on contacts within the hour.']]));
        $catalog->add(new App('dialpad', 'Dialpad', 'phones', 'Calls from Dialpad logged on the matching contacts; a call to a new lead counts as your response.',
            scope: 'both', auth: 'api_key', syncs: ['Calls (direction, length, who took it), hourly', 'Recording links, if you switch them on'],
            fields: [['key' => 'api_key', 'label' => 'API key', 'type' => 'text', 'secret' => true, 'help' => 'Admin settings → My company → Authentication → API keys.'], ['key' => 'recordings', 'label' => 'Show recording links on contacts', 'type' => 'checkbox', 'help' => 'Only if every call plays a recording notice (some states, like California and Florida, require all parties to agree). Recordings stay in your phone app; nothing is transcribed.']],
            driver: Dialpad::class, vendorCost: 'Your Dialpad plan',
            setup: ['steps' => ['Nothing to set up: each company uses its own Dialpad API key.', 'Switch Dialpad on.'], 'docs' => 'https://developers.dialpad.com/docs/call-events'],
            guide: ['steps' => ['A Dialpad admin makes an API key (Admin settings → My company → Authentication → API keys).', 'Paste it below and press Connect.']]));
        $catalog->add(new App('aircall', 'Aircall', 'phones', 'Calls from Aircall logged on the matching contacts, with recording links where allowed.',
            scope: 'both', auth: 'api_key', syncs: ['Calls (direction, length, who took it, voicemail), hourly', 'Recording links, if you switch them on'],
            fields: [['key' => 'api_id', 'label' => 'API ID', 'type' => 'text', 'secret' => true, 'help' => 'Integrations → API Keys in Aircall (admin).'],
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'text', 'secret' => true, 'help' => 'Shown once when you make the key.'], ['key' => 'recordings', 'label' => 'Show recording links on contacts', 'type' => 'checkbox', 'help' => 'Only if every call plays a recording notice (some states, like California and Florida, require all parties to agree). Recordings stay in your phone app; nothing is transcribed.']],
            driver: Aircall::class, vendorCost: 'Your Aircall plan',
            setup: ['steps' => ['Nothing to set up: each company uses its own Aircall API key.', 'Switch Aircall on.'], 'docs' => 'https://developer.aircall.io/docs/work-with-call-data'],
            guide: ['steps' => ['An Aircall admin makes an API key under Integrations → API Keys.', 'Paste the API ID and token below and press Connect.']]));

        // Direct mail (specs/connect/18, C7c): postcards through Lob on RightAlly's account, paid with credits.
        $catalog->add(new App('direct_mail', 'Postcards', 'marketing', 'Just listed, just sold, open house and neighbourhood postcards to a smart list, printed and mailed for you, paid with RightAlly Credits.',
            scope: 'both', auth: 'link', syncs: ['Addresses checked before printing', 'Price shown first; undeliverable and cancelled cards refunded', 'QR code to the listing or funnel'],
            link: '/postcards', vendorCost: 'Printing and postage, paid with credits',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'api_key', 'label' => 'Lob live secret API key', 'secret' => true],
                ['key' => 'price_4x6_cents', 'label' => 'Price per 4x6 card, in cents (printing, postage and margin)'], ['key' => 'price_6x9_cents', 'label' => 'Price per 6x9 card, in cents']],
                'steps' => ['Open a Lob account for RightAlly (lob.com), add a payment method and verify it for live mail.', 'Copy the live secret API key here (use a test key first: test mail is never printed).',
                    'Set the price per card (Lob\'s price plus your margin) and switch Postcards on.'],
                'partner' => 'Lob bills RightAlly for printing and postage; brokerages pay RightAlly in credits.', 'docs' => 'https://docs.lob.com/#tag/Postcards']));

        // Social posting (specs/connect/19, C7d-1): write once under Grow → Social posts, post now or scheduled.
        $catalog->add(new App('facebook_pages', 'Facebook Pages and Instagram', 'marketing', 'Post listings and updates to your Facebook Pages and the Instagram business accounts linked to them, now or scheduled.',
            scope: 'both', auth: 'oauth', syncs: ['Your Pages and linked Instagram accounts, daily', 'Posts you send from Grow → Social posts, with links to each'], link: '/social',
            driver: FacebookPages::class, vendorCost: 'Free',
            // Studio replies (SA5c) also need comments and messages: pages_manage_engagement, pages_read_user_content, pages_messaging,
            // instagram_manage_comments, instagram_manage_messages. People connected before must connect again to allow them.
            oauth: ['provider' => 'meta', 'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'instagram_basic', 'instagram_content_publish', 'business_management',
                'pages_manage_metadata', 'pages_manage_engagement', 'pages_read_user_content', 'pages_messaging', 'instagram_manage_comments', 'instagram_manage_messages'],
                'authorize' => 'https://www.facebook.com/v21.0/dialog/oauth', 'token' => 'https://graph.facebook.com/v21.0/oauth/access_token',
                'userinfo' => 'https://graph.facebook.com/v21.0/me?fields=name,email', 'pkce' => false, 'keys_from' => 'facebook_leads'],
            setup: ['needs' => 'app', 'app' => 'facebook_leads', 'steps' => ['Facebook Pages uses the same Meta app as Facebook Lead Ads: set that card up first.',
                'In the Meta app, add the Instagram Graph API product and request Advanced Access (App Review) for pages_manage_posts, instagram_basic, instagram_content_publish and business_management.',
                'For Ally Studio replies, also request pages_manage_metadata, pages_manage_engagement, pages_read_user_content, pages_messaging, instagram_manage_comments and instagram_manage_messages, and add the Messenger and Instagram webhooks: the "page" object (feed, messages) and the "instagram" object (comments, messages), all to the Facebook Lead Ads address.',
                'Switch Facebook Pages and Instagram on.'],
                'partner' => 'Meta App Review for the posting, comment and messaging permissions (free).', 'docs' => 'https://developers.facebook.com/docs/instagram-platform/content-publishing/'],
            guide: ['steps' => ['Press Connect and sign in to Facebook.', 'Choose the Pages (and their Instagram accounts) RightAlly may post to, and press Allow.',
                'Instagram must be a business or creator account linked to the Page.', 'Then open Grow → Social posts.']]));
        $catalog->add(new App('linkedin', 'LinkedIn', 'marketing', 'Post listings and market updates to your LinkedIn profile, now or scheduled.',
            scope: 'agent', auth: 'oauth', syncs: ['Your profile, daily', 'Posts you send from Grow → Social posts'], link: '/social',
            driver: LinkedIn::class, vendorCost: 'Free',
            oauth: ['provider' => 'linkedin', 'scopes' => ['openid', 'profile', 'w_member_social'], 'authorize' => 'https://www.linkedin.com/oauth/v2/authorization',
                'token' => 'https://www.linkedin.com/oauth/v2/accessToken', 'userinfo' => '', 'pkce' => false],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Client ID'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['At linkedin.com/developers, create an app called RightAlly (it needs RightAlly\'s LinkedIn company page).', 'Add the products "Sign In with LinkedIn using OpenID Connect" and "Share on LinkedIn".',
                    'Under Auth, add the return address shown above as an authorized redirect URL.', 'Copy the client ID and secret here and switch LinkedIn on.'],
                'partner' => 'Free. Posting to company pages needs LinkedIn\'s Community Management API (a separate review), planned later.', 'docs' => 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api'],
            guide: ['steps' => ['Press Connect and sign in to LinkedIn.', 'LinkedIn sign-ins last 60 days; RightAlly reminds you to connect again.', 'Then open Grow → Social posts.']]));
        $catalog->add(new App('google_business', 'Google Business Profile', 'marketing', 'Post listings and updates to your Google Business Profile, with a Learn more button, now or scheduled.',
            scope: 'both', auth: 'oauth', syncs: ['Your business locations, daily', 'Posts you send from Grow → Social posts'], link: '/social',
            driver: GoogleBusiness::class, vendorCost: 'Free',
            oauth: ['provider' => 'google', 'scopes' => ['https://www.googleapis.com/auth/business.manage']],
            setup: ['needs' => 'provider', 'provider' => 'google', 'steps' => ['Set up the Google sign-in app at the top of this page.',
                'In the Google Cloud project, enable the My Business Account Management and Business Information APIs, and request access to the Business Profile APIs (Google grants it on request).',
                'Add the business.manage scope to the consent screen (Google verifies it), then switch Google Business Profile on.'],
                'docs' => 'https://developers.google.com/my-business/content/posts-data'],
            guide: ['steps' => ['Press Connect and sign in with the Google account that manages your Business Profile.', 'Then open Grow → Social posts.']]));

        $catalog->add(new App('youtube', 'YouTube', 'marketing', 'Upload Ally Studio Reels as YouTube Shorts and listing tours as videos with chapters, now or scheduled.',
            scope: 'both', auth: 'oauth', syncs: ['Reels as Shorts and listing tours you post from Ally Studio'], link: '/studio/reels',
            driver: YouTube::class, vendorCost: 'Free',
            oauth: ['provider' => 'google', 'scopes' => ['https://www.googleapis.com/auth/youtube.upload']],
            setup: ['needs' => 'provider', 'provider' => 'google', 'steps' => ['Set up the Google sign-in app at the top of this page.',
                'In the Google Cloud project, enable the YouTube Data API v3 and add the youtube.upload scope to the consent screen (Google verifies it).',
                'Uploads stay private until the project passes YouTube\'s API compliance audit; then set STUDIO_YOUTUBE_PRIVACY=public.'],
                'docs' => 'https://developers.google.com/youtube/v3/guides/uploading_a_video'],
            guide: ['steps' => ['Press Connect and sign in with the Google account that owns your YouTube channel.', 'Then post a Reel or a listing tour from Ally Studio.']]));

        // Ally Studio (specs/grow/13): your AI social team, three monthly packages (HQ prices them; unpriced means not on sale).
        foreach ([
            ['studio_essentials', 'Ally Studio Essentials', 'agent', 'Designed posts with your photo and brand, a content calendar and Autopilot, every month.',
                ['Your brand kit and every designer collection', 'Designed posts and Reels/Shorts each month', 'Calendar, Approve first or Autopilot']],
            ['studio_luxury', 'Ally Studio Luxury', 'agent', 'Everything in Essentials, more posts and Reels/Shorts, YouTube listing tours, listing launch campaigns, Spanish and photo enhancement.',
                ['More posts and Reels/Shorts each month', 'YouTube listing tours and launch campaigns', 'Spanish captions, photo enhancement, voice narration']],
            ['studio_brokerage', 'Ally Studio for the brokerage', 'brokerage', 'Ally Studio Luxury for every agent, plus the brokerage\'s own Pages, recruiting posts, approval rules and team reporting.',
                ['Luxury for every agent', 'Brokerage Pages and recruiting posts', 'Approval rules and team reporting']],
        ] as [$key, $name, $scope, $summary, $syncs]) {
            $catalog->add(new App($key, $name, 'marketing', $summary, scope: $scope, auth: 'link', syncs: $syncs, link: '/studio', vendorCost: 'Monthly add-on, paid with credits or card',
                guide: ['steps' => ['Add the package (it renews monthly; cancel any time).', 'Open Grow → Ally Studio and fill in your brand kit.', 'Connect where you post: Facebook Pages and Instagram, LinkedIn, Google Business Profile.']]));
        }
        $catalog->add(new App('studio_cutout', 'Headshot cut-out', 'marketing', 'Removes the background from headshots, so your photo sits beautifully on every Ally Studio design.',
            scope: 'both', auth: 'link', syncs: ['Headshots with the background removed, on upload'], link: '/studio/brand', vendorCost: 'Included with Ally Studio',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'api_key', 'label' => 'remove.bg API key', 'secret' => true]],
                'steps' => ['Open a remove.bg account for RightAlly and buy a subscription or credits.', 'Copy the API key here and switch Headshot cut-out on.'],
                'partner' => 'remove.bg bills RightAlly per image.', 'docs' => 'https://www.remove.bg/api']));

        $catalog->add(new App('studio_enhance', 'Photo enhancement', 'marketing', 'AI enhancement of listing photos for Ally Studio: light, colour, straight lines, a blue sky, a green lawn, and virtual twilight.',
            scope: 'both', auth: 'link', syncs: ['Enhanced listing photos (the MLS originals are never changed)', 'Virtual twilight of the cover photo'], link: '/studio/photos',
            vendorCost: 'Included with Ally Studio packages',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'api_key', 'label' => 'Autoenhance.ai API key', 'secret' => true]],
                'steps' => ['Open an Autoenhance.ai account for RightAlly and choose pay-per-image or a plan with API access.', 'Copy the API key here and switch Photo enhancement on.',
                    'Enter the real cost per photo under HQ → Costs (studio.enhance) so Studio package margins stay right.'],
                'partner' => 'Autoenhance.ai bills RightAlly per downloaded photo.', 'docs' => 'https://docs.autoenhance.ai']));
        // Canva (specs/grow/13, C7d-3): open a design with the brand kit and listing photos, bring the finished one back.
        $catalog->add(new App('canva', 'Canva', 'marketing', 'Open a design in Canva with your brand kit and listing photos, then bring the finished design back to post, advertise or download.',
            scope: 'agent', auth: 'oauth', syncs: ['Your logo, headshot and listing photos, to your Canva uploads', 'Finished designs, back in Ally Studio'], link: '/studio/create',
            driver: CanvaConnector::class, vendorCost: 'Free with any Canva plan',
            oauth: ['provider' => 'canva', 'scopes' => ['asset:read', 'asset:write', 'design:content:read', 'design:content:write', 'design:meta:read', 'profile:read'],
                'authorize' => 'https://www.canva.com/api/oauth/authorize', 'token' => 'https://api.canva.com/rest/v1/oauth/token', 'userinfo' => '',
                'response_type' => 'code', 'pkce' => true, 'token_auth' => 'basic'],
            setup: ['needs' => 'keys', 'fields' => [['key' => 'client_id', 'label' => 'Client ID'], ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true]],
                'steps' => ['Make a Canva Connect integration for RightAlly in the Canva Developer Portal, with the scopes asset:read/write, design:content:read/write, design:meta:read and profile:read.',
                    'Add the return address shown above as its redirect URL. Under Return navigation, switch it on and add https://{sso host}/connect/canva/return.',
                    'Submit it for Canva\'s review (public integrations are reviewed before anyone outside RightAlly can connect).', 'Paste the client ID and secret here and switch Canva on.'],
                'partner' => 'Canva reviews Connect integrations before they are public (free).', 'docs' => 'https://www.canva.dev/docs/connect/'],
            guide: ['steps' => ['Press Connect and sign in to Canva.', 'Press Allow.', 'In Ally Studio, choose "Open in Canva" on a listing or a design.']]));
        $catalog->add(new App('studio_voice', 'AI voice-overs', 'marketing', 'Voice-overs for Ally Studio Reels and tours: a professional AI voice, or the agent\'s own voice with their recorded consent.',
            scope: 'both', auth: 'link', syncs: ['Narrated Reels and YouTube tours', 'Agents\' own voices, only with their consent; deleted when they withdraw, leave or stop using it'],
            link: '/studio/voice', vendorCost: 'Included with Ally Studio packages',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'api_key', 'label' => 'ElevenLabs API key', 'secret' => true]],
                'steps' => ['Open an ElevenLabs account for RightAlly on a plan with commercial use and voice cloning through the API.',
                    'Read ElevenLabs\' terms for voice cloning: RightAlly collects each agent\'s written and spoken consent before their voice is made, and deletes it when they withdraw.',
                    'Copy the API key here and switch AI voice-overs on. Enter the real cost per narration under HQ → Costs (studio.voice).'],
                'partner' => 'ElevenLabs bills RightAlly per character spoken and per plan.', 'docs' => 'https://elevenlabs.io/docs/api-reference']));
        $catalog->add(new App('studio_upscale', 'Photo upscaling', 'marketing', 'Doubles the size of enhanced photos with AI, so posts and Stories stay razor sharp.',
            scope: 'both', auth: 'link', syncs: ['Enhanced photos at twice the size'], link: '/studio/photos', vendorCost: 'Included with Ally Studio packages',
            setup: ['needs' => 'keys', 'fields' => [['key' => 'api_token', 'label' => 'Replicate API token', 'secret' => true],
                ['key' => 'model_version', 'label' => 'Real-ESRGAN model version id']],
                'steps' => ['Open a Replicate account for RightAlly and add billing.', 'Copy an API token, and the version id of the Real-ESRGAN model you have tested.',
                    'Switch Photo upscaling on; enter the real cost per photo under HQ → Costs (studio.upscale).'],
                'partner' => 'Replicate bills RightAlly per second of compute.', 'docs' => 'https://replicate.com/docs']));

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
            ['paperless_pipeline', 'Paperless Pipeline', 'transactions', 'Bring transactions and documents from Paperless Pipeline.', 'brokerage', 'Your Paperless Pipeline plan'],
            ['authentisign', 'Authentisign', 'transactions', 'Signed Authentisign documents filed on the right deal.', 'both', 'Your Authentisign plan'],
            ['zipform', 'zipForm', 'transactions', 'zipForm transactions and forms into RightAlly deals.', 'agent', 'Your zipForm plan'],
            ['form_simplicity', 'Form Simplicity', 'transactions', 'Form Simplicity transactions and forms into RightAlly deals.', 'agent', 'Your Form Simplicity plan'],
            ['showingtime', 'ShowingTime', 'showings', 'Showing requests and feedback on your listings, in RightAlly.', 'agent', 'Your ShowingTime plan'],
            ['brokerbay', 'BrokerBay', 'showings', 'Showing requests and feedback from BrokerBay on your listings.', 'both', 'Your BrokerBay plan'],
            ['aligned_showings', 'Aligned Showings', 'showings', 'Showing requests and feedback from Aligned Showings.', 'both', 'Your Aligned Showings plan'],
            ['spacio', 'Spacio', 'showings', 'Open house sign-ins become contacts with consent recorded.', 'agent', 'Your Spacio plan'],
            ['bombbomb', 'BombBomb', 'marketing', 'Video emails logged on contacts, with views and replies.', 'agent', 'Your BombBomb plan'],
            ['corefact', 'Corefact', 'marketing', 'Printed farming and listing mail from your RightAlly lists.', 'agent', 'Printing and postage'],
            ['mojo', 'Mojo Dialer', 'phones', 'Dial your RightAlly lists and log every call.', 'agent', 'Your Mojo plan'],
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
