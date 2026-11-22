<?php

declare(strict_types=1);

namespace Modules\Core\Services;

/**
 * HQ4: where to get each outside provider's keys, in plain words for people who are not technical: the provider's
 * website and the steps to find the keys there. Shown on Outside services (HQ and brokerage) under the chosen provider.
 * Keyed "service.provider" because a provider can serve more than one service.
 */
final class ProviderGuides
{
    /** @var array<string, array{site: string, steps: list<string>}> */
    public const ALL = [
        'voice_calls.retell' => ['site' => 'https://www.retellai.com', 'steps' => ['Sign in to Retell AI and open API Keys in the menu; copy the key.', 'Create your calling agent in Retell and copy its Agent ID.', 'Copy the phone number the calls should come from (+1…).']],
        'voice_calls.vapi' => ['site' => 'https://vapi.ai', 'steps' => ['Sign in to Vapi and open API Keys; copy the private key (not the public one).', 'Create an assistant in Vapi and copy its ID.', 'Copy the phone number ID or the number calls come from.']],
        'voice_calls.bland' => ['site' => 'https://www.bland.ai', 'steps' => ['Sign in to Bland AI and copy your API key from your account settings.', 'Create the calling agent (pathway) and copy its ID.', 'Copy the number calls come from (+1…).']],
        'transcripts.deepgram' => ['site' => 'https://deepgram.com', 'steps' => ['Sign in to the Deepgram console and open API Keys.', 'Click Create a New API Key, give it a name such as RightAlly, and copy it.']],
        'transcripts.assemblyai' => ['site' => 'https://www.assemblyai.com', 'steps' => ['Sign in to AssemblyAI; your API key is on the dashboard home page. Copy it.']],
        'transcripts.elevenlabs' => ['site' => 'https://elevenlabs.io', 'steps' => ['Sign in to ElevenLabs, open Developers, then API Keys.', 'Create a key and copy it.']],
        'bank_feed.plaid' => ['site' => 'https://dashboard.plaid.com', 'steps' => ['Sign in to the Plaid dashboard and open Developers, then Keys.', 'Copy the client_id, and the Sandbox secret for Test (pretend banks) and the Production secret for Live.']],
        'bank_feed.mx' => ['site' => 'https://www.mx.com', 'steps' => ['MX gives you a Client ID and API key after you agree terms with their sales team.']],
        'bank_feed.teller' => ['site' => 'https://teller.io', 'steps' => ['Sign in to Teller, copy your Application ID, and download your certificate and private key.', 'Paste the certificate and the private key together into the box.']],
        'search.typesense' => ['site' => 'https://typesense.org', 'steps' => ['Use the address of your Typesense server and the admin API key set when it was installed (ask whoever runs your servers).']],
        'search.meilisearch' => ['site' => 'https://www.meilisearch.com', 'steps' => ['Open your Meilisearch project, copy its address, and copy the admin (or master) key from API keys.']],
        'search.opensearch' => ['site' => 'https://aws.amazon.com/opensearch-service/', 'steps' => ['In the AWS console, open your OpenSearch domain and copy its domain endpoint.', 'Use the master user name and password set when the domain was created.']],
        'staging.collov' => ['site' => 'https://collov.ai', 'steps' => ['Ask Collov AI for developer API access; they send you an API key. Copy it here.']],
        'staging.reimaginehome' => ['site' => 'https://www.reimaginehome.ai', 'steps' => ['Ask REimagineHome for API access; copy the API key they give you.']],
        'staging.virtualstagingai' => ['site' => 'https://www.virtualstagingai.app', 'steps' => ['Sign in to Virtual Staging AI, open the API section of your account, and copy the key.']],
        'floor_plans.cubicasa' => ['site' => 'https://www.cubi.casa', 'steps' => ['Ask CubiCasa for API access for your account; copy the API key they give you.']],
        'floor_plans.matterport' => ['site' => 'https://matterport.com', 'steps' => ['In Matterport, open Settings, then Developer Tools, and create an API token. Copy it.']],
        'floor_plans.iguide' => ['site' => 'https://goiguide.com', 'steps' => ['Ask iGUIDE for API access for your account; copy the key they give you.']],
        'photo_tags.restb' => ['site' => 'https://restb.ai', 'steps' => ['Sign up with Restb.ai; they send you a client key. Copy it here.']],
        'photo_tags.azure_vision' => ['site' => 'https://portal.azure.com', 'steps' => ['In the Azure portal, create a Computer Vision resource.', 'Open it, then Keys and Endpoint; copy Key 1 and the endpoint.']],
        'photo_tags.google_vision' => ['site' => 'https://console.cloud.google.com', 'steps' => ['In Google Cloud, turn on the Cloud Vision API for your project.', 'Open APIs and Services, then Credentials, click Create credentials, then API key. Copy it.']],
        'nearby_places.osm' => ['site' => 'https://www.openstreetmap.org', 'steps' => ['Free: nothing to sign up for. Leave the address as it is unless you run your own server.']],
        'nearby_places.geoapify' => ['site' => 'https://www.geoapify.com', 'steps' => ['Sign in to Geoapify, open My Projects, create a project, and copy its API key.']],
        'nearby_places.google_places' => ['site' => 'https://console.cloud.google.com', 'steps' => ['In Google Cloud, turn on the Places API for your project.', 'Open APIs and Services, then Credentials, click Create credentials, then API key. Copy it.']],
        'email_check.zerobounce' => ['site' => 'https://www.zerobounce.net', 'steps' => ['Sign in to ZeroBounce, open API, then API Keys, and copy a key.']],
        'email_check.neverbounce' => ['site' => 'https://neverbounce.com', 'steps' => ['Sign in to NeverBounce, open Apps, create a Custom Integration, and copy its API key.']],
        'email_check.kickbox' => ['site' => 'https://kickbox.com', 'steps' => ['Sign in to Kickbox, open Verification, then API Keys, and create a key.']],
        'phone_check.twilio_lookup' => ['site' => 'https://console.twilio.com', 'steps' => ['Sign in to Twilio. On the Console home page, under Account Info, copy the Account SID and the Auth Token.']],
        'phone_check.telnyx' => ['site' => 'https://portal.telnyx.com', 'steps' => ['Sign in to Telnyx, open API Keys, create a key, and copy it.']],
        'phone_check.numverify' => ['site' => 'https://numverify.com', 'steps' => ['Sign in to numverify; your API access key is on the dashboard. Copy it.']],
        'payments.stripe' => ['site' => 'https://dashboard.stripe.com', 'steps' => ['Open Developers, then API keys, in Stripe. Use the test keys (pk_test_, sk_test_) for Test and the live keys for Live.', 'HQ › Payments has a full step-by-step guide for Stripe.']],
        'payments.paddle' => ['site' => 'https://www.paddle.com', 'steps' => ['In Paddle, open Developer tools, then Authentication, and create an API key. Use the sandbox account for Test.']],
        'payments.chargebee' => ['site' => 'https://www.chargebee.com', 'steps' => ['In Chargebee, open Settings, then API Keys, and create a full-access key. Use your test site for Test.']],
        'social_share.zapier' => ['site' => 'https://zapier.com', 'steps' => ['In Zapier, create a Zap that starts with Webhooks by Zapier, then Catch Hook.', 'Copy the webhook address Zapier shows, paste it here, then finish the Zap with the social account you want.']],
        'social_share.make' => ['site' => 'https://www.make.com', 'steps' => ['In Make, create a scenario that starts with Webhooks, then Custom webhook.', 'Copy the webhook address, paste it here, then add the social account you want.']],
        'social_share.buffer' => ['site' => 'https://buffer.com', 'steps' => ['Connect your social accounts in Buffer, then copy the access token and the profile IDs from your Buffer developer account.']],
        'search_console.google' => ['site' => 'https://search.google.com/search-console', 'steps' => ['In Google Cloud, create a service account and download its key as JSON. Paste the whole file here.', 'In Search Console, open Settings, then Users and permissions, and add the service account\'s email address as a user.']],
        'search_console.bing' => ['site' => 'https://www.bing.com/webmasters', 'steps' => ['In Bing Webmaster Tools, open Settings, then API access, and generate an API key. Copy it.']],
        'search_console.manual' => ['site' => '', 'steps' => ['Nothing to connect: you type the numbers in yourself.']],
        'bot_protection.turnstile' => ['site' => 'https://dash.cloudflare.com', 'steps' => ['In Cloudflare, open Turnstile and click Add site. Add your website\'s address.', 'Copy the Site key and the Secret key.']],
        'bot_protection.hcaptcha' => ['site' => 'https://dashboard.hcaptcha.com', 'steps' => ['In hCaptcha, add a new site and copy its site key; copy your secret key from Settings.']],
        'bot_protection.recaptcha' => ['site' => 'https://www.google.com/recaptcha/admin', 'steps' => ['Create a new site in the reCAPTCHA admin page, add your website\'s address, and copy the site key and secret key.']],
        'inbound_email.postmark' => ['site' => 'https://account.postmarkapp.com', 'steps' => ['In Postmark, open your server, then API Tokens, and copy the Server API token.', 'Open the Default Inbound Stream and set its webhook to the address in the note above.']],
        'inbound_email.sendgrid' => ['site' => 'https://app.sendgrid.com', 'steps' => ['In SendGrid, open Settings, then API Keys, and create a key. Copy it.', 'Open Settings, then Inbound Parse, and add your receiving domain with the Parse URL in the note above.']],
        'inbound_email.mailgun' => ['site' => 'https://app.mailgun.com', 'steps' => ['In Mailgun, copy your API key and your receiving domain.', 'Open Receiving, then Routes, and create a route that forwards to https://<HQ address>/webhooks/inbound/mailgun.', 'Copy the HTTP webhook signing key from the webhooks settings.']],
        'idx_spark.spark' => ['site' => 'https://sparkplatform.com', 'steps' => ['Spark sends an access token once your MLS approves the data agreement. Copy it here.', 'Leave the address empty unless Spark gave you a different one.']],
        'idx_repliers.repliers' => ['site' => 'https://repliers.com', 'steps' => ['Sign in to Repliers; your API key is in your account dashboard once your MLS access is approved. Copy it.']],
        'platform_email.brevo' => ['site' => 'https://app.brevo.com', 'steps' => ['In Brevo, open your name (top right), then SMTP and API, then the API keys tab.', 'Click Generate a new API key, name it RightAlly, and copy it (it starts xkeysib-).', 'Under Senders, Domains and dedicated IPs, add the address you send from and click the link Brevo emails you (or verify your whole domain).', 'If Brevo blocks unknown IP addresses for API keys, add your server\'s IP address under Security, then Authorised IPs.']],
        'platform_email.postmark_send' => ['site' => 'https://account.postmarkapp.com', 'steps' => ['In Postmark, open your server, then API Tokens, and copy the Server API token.', 'Verify the address or domain you send from under Sender Signatures.']],
        'platform_email.ses' => ['site' => 'https://console.aws.amazon.com/ses/', 'steps' => ['In Amazon SES, verify your sending domain.', 'Open SMTP settings, click Create SMTP credentials, and copy the user name and password.']],
        'error_tracking.sentry' => ['site' => 'https://sentry.io', 'steps' => ['In Sentry, open your project, then Settings, then Client Keys (DSN). Copy the DSN.']],
        'error_tracking.bugsnag' => ['site' => 'https://www.bugsnag.com', 'steps' => ['In Bugsnag, open your project settings and copy the Notifier API key.']],
        'error_tracking.honeybadger' => ['site' => 'https://www.honeybadger.io', 'steps' => ['In Honeybadger, open your project, then Settings, then API Key. Copy it.']],
        'video.own' => ['site' => '', 'steps' => ['Nothing to connect: RightAlly makes the videos on its own servers.']],
        'video.shotstack' => ['site' => 'https://shotstack.io', 'steps' => ['Sign in to Shotstack and open API Keys. Use the Sandbox key for Test and the Production key for Live.']],
        'video.creatomate' => ['site' => 'https://creatomate.com', 'steps' => ['In Creatomate, open your project settings and copy the API key.']],
        'licence_lookup.state_data' => ['site' => '', 'steps' => ['Free: RightAlly reads each state\'s public licence data. Nothing to connect.']],
        'licence_lookup.arello' => ['site' => 'https://www.arello.org', 'steps' => ['Ask ARELLO for licence verification access; copy the key they give you.']],
        'licence_lookup.manual' => ['site' => '', 'steps' => ['Nothing to connect: licences are typed in by hand.']],
    ];

    /**
     * @return array{site: string, steps: list<string>}|null
     */
    public static function for(string $service, string $provider): ?array
    {
        return self::ALL[$service.'.'.$provider] ?? null;
    }
}
