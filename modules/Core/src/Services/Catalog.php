<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * The outside services RightAlly can use (FL1, specs/core/16): for each, three providers (the recommended one first),
 * where the keys live (HQ's own account, each brokerage's, or either), and the fields each provider needs.
 *
 * Levels: "hq" = RightAlly holds one account and brokerages simply use the feature (charged in credits);
 * "brokerage" = each brokerage connects its own account; "both" = HQ's account by default, a brokerage may use its own.
 *
 * Field kinds: "secret" (encrypted, write-only), "text" (shown, e.g. a host or a site name).
 */
final class Catalog
{
    /**
     * @return array<string, array{label: string, about: string, level: string, sprint: string, contacts: bool, providers: array<string, array{label: string, fields: array<string, array{label: string, kind: string, hint?: string, optional?: bool}>, note?: string}>}>
     */
    public static function all(): array
    {
        $key = ['api_key' => ['label' => 'API key', 'kind' => 'secret']];
        // VO1: the voice agent set up in the provider's account, and the calling number registered there.
        $voice = ['api_key' => ['label' => 'API key', 'kind' => 'secret'], 'agent_id' => ['label' => 'AI agent ID (from your provider account)', 'kind' => 'text'],
            'from_number' => ['label' => 'Calling number (+1…), or Vapi phone number ID', 'kind' => 'text']];

        return [
            'voice_calls' => ['label' => 'AI voice calls', 'about' => 'An AI voice calls new leads who opted in, within a minute, 8am to 9pm their time, and hands warm leads to the agent.',
                'level' => 'both', 'sprint' => 'VO1', 'contacts' => true, 'providers' => [
                    'retell' => ['label' => 'Retell AI (recommended)', 'fields' => $voice, 'note' => 'SOC 2 Type 1 and 2, HIPAA; outbound campaigns built in. Calls go out through our Twilio.'],
                    'vapi' => ['label' => 'Vapi', 'fields' => $voice],
                    'bland' => ['label' => 'Bland AI', 'fields' => $voice],
                ]],
            'transcripts' => ['label' => 'Call and appointment transcripts', 'about' => 'Turns recorded calls and appointments (with everyone\'s consent) into text and notes, in English and Spanish.',
                'level' => 'hq', 'sprint' => 'VO1, VO2', 'contacts' => false, 'providers' => [
                    'deepgram' => ['label' => 'Deepgram (recommended)', 'fields' => $key, 'note' => 'Recording stays on our Twilio; Deepgram only writes the transcript.'],
                    'assemblyai' => ['label' => 'AssemblyAI', 'fields' => $key],
                    'elevenlabs' => ['label' => 'ElevenLabs Scribe', 'fields' => $key],
                ]],
            'bank_feed' => ['label' => 'Live bank feed', 'about' => 'Brings bank transactions in for reconciliation. Read-only: money never moves through it.',
                'level' => 'hq', 'sprint' => 'BK1', 'contacts' => false, 'providers' => [
                    'plaid' => ['label' => 'Plaid (recommended)', 'fields' => ['client_id' => ['label' => 'Client ID', 'kind' => 'text'], 'secret' => ['label' => 'Secret', 'kind' => 'secret']],
                        'note' => 'Test mode uses Plaid Sandbox (pretend banks); Live uses Production.'],
                    'mx' => ['label' => 'MX', 'fields' => ['client_id' => ['label' => 'Client ID', 'kind' => 'text'], 'api_key' => ['label' => 'API key', 'kind' => 'secret']],
                        'note' => 'Stronger for small banks and credit unions. Prices are agreed with MX sales.'],
                    'teller' => ['label' => 'Teller', 'fields' => ['application_id' => ['label' => 'Application ID', 'kind' => 'text'], 'certificate' => ['label' => 'Certificate and private key (PEM)', 'kind' => 'secret']],
                        'note' => 'Teller signs requests with a certificate; it is checked on the first bank connection.'],
                ]],
            'search' => ['label' => 'Fast listing search', 'about' => 'A search server for map, radius and typo-tolerant listing search. Test mode runs beside today\'s search and compares results.',
                'level' => 'hq', 'sprint' => 'SR1', 'contacts' => false, 'providers' => [
                    'typesense' => ['label' => 'Typesense (recommended, on our servers)', 'fields' => ['host' => ['label' => 'Server address (https://…)', 'kind' => 'text'], 'api_key' => ['label' => 'Admin API key', 'kind' => 'secret']]],
                    'meilisearch' => ['label' => 'Meilisearch', 'fields' => ['host' => ['label' => 'Server address (https://…)', 'kind' => 'text'], 'api_key' => ['label' => 'Master or admin key', 'kind' => 'secret']]],
                    'opensearch' => ['label' => 'Amazon OpenSearch', 'fields' => ['host' => ['label' => 'Domain endpoint (https://…)', 'kind' => 'text'], 'username' => ['label' => 'User name', 'kind' => 'text'], 'password' => ['label' => 'Password', 'kind' => 'secret']]],
                ]],
            'staging' => ['label' => 'Virtual staging and room visualization', 'about' => 'Furnishes empty rooms in listing photos. Every staged photo is labelled "AI staged", as MLS rules expect.',
                'level' => 'hq', 'sprint' => 'MD1', 'contacts' => false, 'providers' => [
                    'collov' => ['label' => 'Collov AI (recommended)', 'fields' => $key, 'note' => 'Public developer API, 50+ styles. Confirm the price per photo with Collov before Live.'],
                    'reimaginehome' => ['label' => 'REimagineHome', 'fields' => $key],
                    'virtualstagingai' => ['label' => 'Virtual Staging AI', 'fields' => $key],
                ]],
            'floor_plans' => ['label' => 'Floor plans and 360-degree tours', 'about' => 'Floor plans from a phone scan, and 3D or 360-degree walk-throughs on listing pages.',
                'level' => 'both', 'sprint' => 'C13, MD1', 'contacts' => false, 'providers' => [
                    'cubicasa' => ['label' => 'CubiCasa (recommended)', 'fields' => $key, 'note' => 'Scan with a phone; the floor plan comes back by itself.'],
                    'matterport' => ['label' => 'Matterport', 'fields' => ['token_id' => ['label' => 'API token ID', 'kind' => 'text'], 'token_secret' => ['label' => 'API token secret', 'kind' => 'secret']]],
                    'iguide' => ['label' => 'iGUIDE', 'fields' => $key],
                ]],
            'photo_tags' => ['label' => 'Photo tagging', 'about' => 'Names the room in each listing photo and spots features (pool, fireplace, kitchen island…), for photo alt text and "Has" search filters. Only the home is described, never people.',
                'level' => 'hq', 'sprint' => 'C03', 'contacts' => false, 'providers' => [
                    'restb' => ['label' => 'Restb.ai (recommended, made for real estate)', 'fields' => ['api_key' => ['label' => 'Client key', 'kind' => 'secret']],
                        'note' => 'Room types and 100+ home features. Priced per photo; confirm the price with Restb.ai before Live.'],
                    'azure_vision' => ['label' => 'Azure AI Vision', 'fields' => ['endpoint' => ['label' => 'Endpoint (https://<name>.cognitiveservices.azure.com)', 'kind' => 'text'], 'api_key' => ['label' => 'Key', 'kind' => 'secret']],
                        'note' => 'General picture tags, matched to home features.'],
                    'google_vision' => ['label' => 'Google Cloud Vision', 'fields' => $key, 'note' => 'General picture labels, matched to home features.'],
                ]],
            'nearby_places' => ['label' => 'Nearby schools and transit', 'about' => 'Finds the schools, bus stops, stations, grocery stores and coffee shops near each listing once, and shows straight-line distances on listing pages. No school ratings.',
                'level' => 'hq', 'sprint' => 'C03', 'contacts' => false, 'providers' => [
                    'osm' => ['label' => 'OpenStreetMap (recommended, free)', 'fields' => ['host' => ['label' => 'Overpass server address (https://…)', 'kind' => 'text',
                        'hint' => 'https://overpass-api.de/api/interpreter is fine for testing; use your own or a paid Overpass server for many listings.']],
                        'note' => 'Results may be kept, with the credit "© OpenStreetMap contributors" on the page.'],
                    'geoapify' => ['label' => 'Geoapify Places', 'fields' => $key, 'note' => 'OpenStreetMap data on Geoapify\'s servers, with a daily free allowance. Results may be kept, with credit.'],
                    'google_places' => ['label' => 'Google Places', 'fields' => $key,
                        'note' => 'Google\'s terms limit how long results are kept: RightAlly looks them up again every 30 days. Have this checked before Live.'],
                ]],
            'email_check' => ['label' => 'Email checks', 'about' => 'Checks that contacts\' email addresses can receive mail, before campaigns go out.',
                'level' => 'hq', 'sprint' => 'LN4', 'contacts' => false, 'providers' => [
                    'zerobounce' => ['label' => 'ZeroBounce (recommended)', 'fields' => $key, 'note' => 'Has test addresses with known answers, used in Test mode.'],
                    'neverbounce' => ['label' => 'NeverBounce', 'fields' => $key],
                    'kickbox' => ['label' => 'Kickbox', 'fields' => $key],
                ]],
            'phone_check' => ['label' => 'Phone checks', 'about' => 'Tells mobile from landline and spots dead numbers; mobile matters for texting consent.',
                'level' => 'hq', 'sprint' => 'LN4', 'contacts' => false, 'providers' => [
                    'twilio_lookup' => ['label' => 'Twilio Lookup (recommended)', 'fields' => ['sid' => ['label' => 'Account SID', 'kind' => 'text'], 'token' => ['label' => 'Auth token', 'kind' => 'secret']],
                        'note' => 'Can be the same Twilio account as texts and calls.'],
                    'telnyx' => ['label' => 'Telnyx Number Lookup', 'fields' => $key],
                    'numverify' => ['label' => 'Numverify', 'fields' => $key],
                ]],
            'payments' => ['label' => 'Sign-up payments', 'about' => 'Takes the first payment when a brokerage or agent signs up online.',
                'level' => 'hq', 'sprint' => 'SG1', 'contacts' => false, 'providers' => [
                    'stripe' => ['label' => 'Stripe (recommended, already used for billing)', 'fields' => ['secret_key' => ['label' => 'Secret key (sk_test_… or sk_live_…)', 'kind' => 'secret'], 'publishable_key' => ['label' => 'Publishable key (pk_…)', 'kind' => 'text']]],
                    'paddle' => ['label' => 'Paddle (also handles US sales tax)', 'fields' => ['api_key' => ['label' => 'API key', 'kind' => 'secret'], 'client_token' => ['label' => 'Client-side token', 'kind' => 'text']]],
                    'chargebee' => ['label' => 'Chargebee', 'fields' => ['site' => ['label' => 'Site name (yoursite)', 'kind' => 'text'], 'api_key' => ['label' => 'API key', 'kind' => 'secret']]],
                ]],
            // MK3: the AI content desk's reach — sharing new pages, and Search Console numbers that set the posting level.
            'social_share' => ['label' => 'Share new pages on social media', 'about' => 'Posts each newly published content desk page to RightAlly\'s LinkedIn and Facebook pages.',
                'level' => 'hq', 'sprint' => 'MK3', 'contacts' => false, 'providers' => [
                    'zapier' => ['label' => 'Zapier webhook (recommended)', 'fields' => ['webhook_url' => ['label' => 'Zapier "Catch Hook" address', 'kind' => 'secret']],
                        'note' => 'In Zapier: Catch Hook → LinkedIn Page post and Facebook Page post. RightAlly sends title, url and summary.'],
                    'make' => ['label' => 'Make webhook', 'fields' => ['webhook_url' => ['label' => 'Make webhook address', 'kind' => 'secret']]],
                    'buffer' => ['label' => 'Buffer', 'fields' => ['access_token' => ['label' => 'Access token', 'kind' => 'secret'], 'profile_ids' => ['label' => 'Profile IDs (comma-separated)', 'kind' => 'text']]],
                ]],
            'search_console' => ['label' => 'Search Console numbers', 'about' => 'How many content desk pages search engines have indexed, and any warnings; this decides how much the desk may write.',
                'level' => 'both', 'sprint' => 'MK3', 'contacts' => false, 'providers' => [
                    'google' => ['label' => 'Google Search Console (recommended)', 'fields' => ['site_url' => ['label' => 'Property (for example sc-domain:rightally.io)', 'kind' => 'text'],
                        'service_account' => ['label' => 'Service account key (JSON)', 'kind' => 'secret']], 'note' => 'Add the service account as a user of the property, with read access.'],
                    'bing' => ['label' => 'Bing Webmaster Tools', 'fields' => ['site_url' => ['label' => 'Site address', 'kind' => 'text'], 'api_key' => ['label' => 'API key', 'kind' => 'secret']]],
                    'manual' => ['label' => 'Typed in by hand', 'fields' => [], 'note' => 'HQ types the indexed and submitted counts each week.'],
                ]],
            'bot_protection' => ['label' => 'Bot protection', 'about' => 'Stops robots on sign-up, contact and lead forms, without picture puzzles for people.',
                'level' => 'both', 'sprint' => 'TR1', 'contacts' => false, 'providers' => [
                    'turnstile' => ['label' => 'Cloudflare Turnstile (recommended, free)', 'fields' => ['site_key' => ['label' => 'Site key', 'kind' => 'text'], 'secret_key' => ['label' => 'Secret key', 'kind' => 'secret']],
                        'note' => 'Cloudflare publishes test keys that always pass, for Test mode.'],
                    'hcaptcha' => ['label' => 'hCaptcha', 'fields' => ['site_key' => ['label' => 'Site key', 'kind' => 'text'], 'secret_key' => ['label' => 'Secret key', 'kind' => 'secret']]],
                    'recaptcha' => ['label' => 'Google reCAPTCHA', 'fields' => ['site_key' => ['label' => 'Site key', 'kind' => 'text'], 'secret_key' => ['label' => 'Secret key', 'kind' => 'secret']]],
                ]],
            'inbound_email' => ['label' => 'Forward-paperwork email address', 'about' => 'Receives paperwork agents forward to their Deal-IQ address. Only what is forwarded is read, never a mailbox.',
                'level' => 'hq', 'sprint' => 'DQ4', 'contacts' => false, 'providers' => [
                    'postmark' => ['label' => 'Postmark inbound (recommended)', 'fields' => ['server_token' => ['label' => 'Server API token', 'kind' => 'secret']],
                        'note' => 'Inbound webhook: https://<HQ address>/webhooks/inbound/lead-email, with the inbound secret as the basic-auth password.'],
                    'sendgrid' => ['label' => 'SendGrid Inbound Parse', 'fields' => $key, 'note' => 'Parse URL: https://<HQ address>/webhooks/inbound/sendgrid/<inbound secret>. Tick "POST the raw, full MIME message" off.'],
                    'mailgun' => ['label' => 'Mailgun Routes', 'fields' => ['api_key' => ['label' => 'API key', 'kind' => 'secret'], 'domain' => ['label' => 'Receiving domain', 'kind' => 'text'],
                        'signing_key' => ['label' => 'HTTP webhook signing key', 'kind' => 'secret']],
                        'note' => 'Route action: forward("https://<HQ address>/webhooks/inbound/mailgun"). Each post is checked with the webhook signing key.'],
                ]],
            // IDX2: MLS listings through Spark (Flexmls boards such as MLS United). The key is RightAlly's vendor key;
            // each MLS board's feed is set in HQ › MLS connections with the provider "Spark API".
            'idx_spark' => ['label' => 'MLS listings: Spark API', 'about' => 'Copies listings from Flexmls boards on Spark (such as MLS United) into RightAlly, every 10 minutes. Test uses Spark\'s demo data; Live uses the boards that approved RightAlly.',
                'level' => 'hq', 'sprint' => 'IDX2', 'contacts' => false, 'providers' => [
                    'spark' => ['label' => 'Spark API (recommended for MLS United)', 'fields' => ['access_token' => ['label' => 'Access token (from your Spark API key)', 'kind' => 'secret'],
                        'endpoint' => ['label' => 'Address (leave empty for the replication address)', 'kind' => 'text', 'hint' => 'https://replication.sparkapi.com/Reso/OData', 'optional' => true]],
                        'note' => 'For Spark\'s demo data use the address https://sparkapi.com/Reso/OData in Test. Live needs a key with replication permission (ask api-support@sparkplatform.com).'],
                ]],
            'platform_email' => ['label' => 'Platform email', 'about' => 'Sends RightAlly\'s own email: invitations, sign-in codes, notices and Watchtower alerts. Test sends everything only to your first test contact. Off uses the server\'s mail settings.',
                'level' => 'hq', 'sprint' => 'EM1', 'contacts' => false, 'providers' => [
                    'brevo' => ['label' => 'Brevo (recommended)', 'fields' => ['smtp_login' => ['label' => 'SMTP login (Settings › SMTP & API › SMTP)', 'kind' => 'text'],
                        'smtp_key' => ['label' => 'SMTP key', 'kind' => 'secret'], 'from_address' => ['label' => 'Send from (an address on a domain verified in Brevo)', 'kind' => 'text'],
                        'from_name' => ['label' => 'Sender name', 'kind' => 'text']],
                        'note' => 'Free plan sends 300 emails a day. Verify your domain in Brevo (Senders, Domains) and add its DNS records first.'],
                    'postmark_send' => ['label' => 'Postmark', 'fields' => ['server_token' => ['label' => 'Server API token', 'kind' => 'secret'],
                        'from_address' => ['label' => 'Send from (a verified sender signature or domain)', 'kind' => 'text'], 'from_name' => ['label' => 'Sender name', 'kind' => 'text']],
                        'note' => 'Best inbox delivery for sign-in codes and alerts.'],
                    'ses' => ['label' => 'Amazon SES', 'fields' => ['region' => ['label' => 'Region (us-east-1)', 'kind' => 'text'], 'smtp_username' => ['label' => 'SMTP username', 'kind' => 'text'],
                        'smtp_password' => ['label' => 'SMTP password', 'kind' => 'secret'], 'from_address' => ['label' => 'Send from (a verified identity)', 'kind' => 'text'],
                        'from_name' => ['label' => 'Sender name', 'kind' => 'text']],
                        'note' => 'Lowest price at volume. SES starts in its sandbox: ask AWS for production access.'],
                ]],
            'error_tracking' => ['label' => 'Error tracking', 'about' => 'Also sends each problem Watchtower finds to an error-tracking service, with personal data removed. Watchtower keeps working without it.',
                'level' => 'hq', 'sprint' => 'OB1', 'contacts' => false, 'providers' => [
                    'sentry' => ['label' => 'Sentry (recommended)', 'fields' => ['dsn' => ['label' => 'DSN (Settings › Projects › Client Keys)', 'kind' => 'secret']],
                        'note' => 'Test sends to Sentry with the environment "test"; Live with the server\'s environment. The check sends one test event.'],
                    'bugsnag' => ['label' => 'Bugsnag', 'fields' => ['api_key' => ['label' => 'Project notifier API key', 'kind' => 'secret']]],
                    'honeybadger' => ['label' => 'Honeybadger', 'fields' => ['api_key' => ['label' => 'Project API key', 'kind' => 'secret']]],
                ]],
            'video' => ['label' => 'Video intros, outros and reels', 'about' => 'Makes branded videos and listing reels.',
                'level' => 'hq', 'sprint' => 'MK3, MD1', 'contacts' => false, 'providers' => [
                    'own' => ['label' => 'RightAlly\'s own renderer (recommended)', 'fields' => [], 'note' => 'Already built for ads. No extra account or fee.'],
                    'shotstack' => ['label' => 'Shotstack', 'fields' => $key, 'note' => 'Test mode uses Shotstack\'s free "stage" environment (watermarked).'],
                    'creatomate' => ['label' => 'Creatomate', 'fields' => $key],
                ]],
            'licence_lookup' => ['label' => 'Agent licence look-up', 'about' => 'Fills in and checks agents\' licence details.',
                'level' => 'hq', 'sprint' => 'LN4', 'contacts' => false, 'providers' => [
                    'state_data' => ['label' => 'State public licence data (recommended, free)', 'fields' => [], 'note' => 'Covers the states that publish their licence lists.'],
                    'arello' => ['label' => 'ARELLO licence verification', 'fields' => $key, 'note' => 'Paid; covers every state.'],
                    'manual' => ['label' => 'Typed in by hand', 'fields' => []],
                ]],
        ];
    }

    /** @return array{label: string, about: string, level: string, sprint: string, contacts: bool, providers: array<string, array{label: string, fields: array<string, array{label: string, kind: string, hint?: string, optional?: bool}>, note?: string}>}|null */
    public static function get(string $service): ?array
    {
        return self::all()[$service] ?? null;
    }

    /** The recommended (first) provider. */
    public static function recommended(string $service): ?string
    {
        $providers = array_keys(self::get($service)['providers'] ?? []);

        return $providers[0] ?? null;
    }
}
