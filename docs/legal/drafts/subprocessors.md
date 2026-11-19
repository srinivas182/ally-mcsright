# DRAFT: RightAlly subprocessors (for attorney review)

RightAlly uses the outside companies below to run the service. Each brokerage's data is kept separate. HQ switches
each outside service on in Test before Live, and some can be chosen per brokerage (HQ → Outside services). **Only the
provider actually switched on is used**; the others in a row are the alternatives HQ can choose.

## Always used

| Company                                                                                                                 | What for                                                        | What it sees                                                         | Where         |
| ----------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | -------------------------------------------------------------------- | ------------- |
| Cloud hosting (to be confirmed: the provider chosen for production)                                                     | Servers, databases, file storage, backups                       | Everything, encrypted at rest                                        | United States |
| Email sending (Postmark, Amazon SES or Resend — whichever is configured)                                                | Sign-in emails, morning brief, client emails sent by agents     | Email addresses, message text                                        | United States |
| Twilio                                                                                                                  | Texts and calls (only when HQ switches texting or calling on)   | Phone numbers, message text, call audio when recording is consented  | United States |
| Stripe                                                                                                                  | Subscription billing for brokerages                             | Billing contact and card (card data stays at Stripe)                 | United States |
| AI providers through the RightAlly AI gateway (Anthropic, OpenAI-compatible providers, Google Gemini — as routed by HQ) | Your AI Assistant, writing help, summaries, voice notes to text | The records needed for the request, for the signed-in brokerage only | United States |

## Used only when switched on (HQ → Outside services)

| Service                          | Providers (first one recommended)                       | What it sees                                  |
| -------------------------------- | ------------------------------------------------------- | --------------------------------------------- |
| AI voice calls                   | Retell AI, Vapi, Bland AI                               | Lead name, number, call audio (with consent)  |
| Call and appointment transcripts | Deepgram, AssemblyAI, ElevenLabs Scribe                 | Recorded call audio (with everyone's consent) |
| Live bank feed (read-only)       | Plaid, MX, Teller                                       | Brokerage bank transactions                   |
| Fast listing search              | Typesense (our servers), Meilisearch, Amazon OpenSearch | Public listing data                           |
| Virtual staging                  | Collov AI, REimagineHome, Virtual Staging AI            | Listing photos                                |
| Floor plans and tours            | CubiCasa, Matterport, iGUIDE                            | Listing photos and scans                      |
| Photo tagging                    | Restb.ai, Azure AI Vision, Google Cloud Vision          | Listing photos                                |
| Nearby schools and transit       | OpenStreetMap, Geoapify, Google Places                  | Listing addresses                             |
| Email checks                     | ZeroBounce, NeverBounce, Kickbox                        | Email addresses being checked                 |
| Phone checks                     | Twilio Lookup, Telnyx, Numverify                        | Phone numbers being checked                   |
| Sign-up payments                 | Stripe, Paddle, Chargebee                               | Brokerage billing details                     |
| Bot protection                   | Cloudflare Turnstile, hCaptcha, Google reCAPTCHA        | Website visitors' browser signals             |
| Forward-paperwork email          | Postmark inbound, SendGrid, Mailgun                     | Emails forwarded to the brokerage's address   |
| Video                            | RightAlly's own renderer, Shotstack, Creatomate         | Listing photos and text                       |
| Agent licence look-up            | State public data, ARELLO                               | Agent licence numbers                         |
| Maps                             | Mapbox                                                  | Map views on websites and in the app          |

## Chosen by the brokerage itself

Apps a brokerage connects (for example its own Google, Microsoft, Zapier, Datadog or Splunk) are the brokerage's own
choice and its own processors, not RightAlly's.

## Questions for the attorney

1. Which of these must be named individually in the data processing agreement, and which can be listed by category?
2. How much notice must brokerages get before a new subprocessor is added? _(Default, flagged for review: 30 days by
   email, with this page updated.)_
3. Are any state-specific notices needed for call recording and transcripts (two-party consent states)?
