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
     * @return array<string, array{label: string, about: string, level: string, sprint: string, contacts: bool, providers: array<string, array{label: string, fields: array<string, array{label: string, kind: string, hint?: string}>, note?: string}>}>
     */
    public static function all(): array
    {
        $key = ['api_key' => ['label' => 'API key', 'kind' => 'secret']];

        return [
            'voice_calls' => ['label' => 'AI voice calls', 'about' => 'An AI voice calls new leads who opted in, within a minute, 8am to 9pm their time, and hands warm leads to the agent.',
                'level' => 'both', 'sprint' => 'VO1', 'contacts' => true, 'providers' => [
                    'retell' => ['label' => 'Retell AI (recommended)', 'fields' => $key, 'note' => 'SOC 2 Type 1 and 2, HIPAA; outbound campaigns built in. Calls go out through our Twilio.'],
                    'vapi' => ['label' => 'Vapi', 'fields' => $key],
                    'bland' => ['label' => 'Bland AI', 'fields' => $key],
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
            'bot_protection' => ['label' => 'Bot protection', 'about' => 'Stops robots on sign-up, contact and lead forms, without picture puzzles for people.',
                'level' => 'both', 'sprint' => 'TR1', 'contacts' => false, 'providers' => [
                    'turnstile' => ['label' => 'Cloudflare Turnstile (recommended, free)', 'fields' => ['site_key' => ['label' => 'Site key', 'kind' => 'text'], 'secret_key' => ['label' => 'Secret key', 'kind' => 'secret']],
                        'note' => 'Cloudflare publishes test keys that always pass, for Test mode.'],
                    'hcaptcha' => ['label' => 'hCaptcha', 'fields' => ['site_key' => ['label' => 'Site key', 'kind' => 'text'], 'secret_key' => ['label' => 'Secret key', 'kind' => 'secret']]],
                    'recaptcha' => ['label' => 'Google reCAPTCHA', 'fields' => ['site_key' => ['label' => 'Site key', 'kind' => 'text'], 'secret_key' => ['label' => 'Secret key', 'kind' => 'secret']]],
                ]],
            'inbound_email' => ['label' => 'Forward-paperwork email address', 'about' => 'Receives paperwork agents forward to their Deal-IQ address. Only what is forwarded is read, never a mailbox.',
                'level' => 'hq', 'sprint' => 'DQ4', 'contacts' => false, 'providers' => [
                    'postmark' => ['label' => 'Postmark inbound (recommended)', 'fields' => ['server_token' => ['label' => 'Server API token', 'kind' => 'secret']]],
                    'sendgrid' => ['label' => 'SendGrid Inbound Parse', 'fields' => $key],
                    'mailgun' => ['label' => 'Mailgun Routes', 'fields' => ['api_key' => ['label' => 'API key', 'kind' => 'secret'], 'domain' => ['label' => 'Receiving domain', 'kind' => 'text']]],
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

    /** @return array{label: string, about: string, level: string, sprint: string, contacts: bool, providers: array<string, array{label: string, fields: array<string, array{label: string, kind: string, hint?: string}>, note?: string}>}|null */
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
