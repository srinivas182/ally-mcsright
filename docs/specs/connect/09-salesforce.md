# 09 — Salesforce and Propertybase (Sprint C2d-2, Team A)

Many larger brokerages run on Salesforce, either directly or through Propertybase, which is built on Salesforce.
One connector serves both, with a one-click sign-in through RightAlly's own Salesforce connected app.

## For the broker (owner or staff)

1. Apps → Salesforce (or Propertybase) → **Connect**.
2. Sign in to Salesforce as an admin and press **Allow**.
3. Contacts and open leads start coming over straight away. After that, changes come over every hour.

**Scope:** brokerage only. One connection brings company contacts, and each contact keeps its owner (matched to
a RightAlly person by email).

**Vendor plan:** the org needs API access (Enterprise, Unlimited or Developer edition, or an API add-on).

## How it works

- **Sign-in:**
    - `https://login.salesforce.com/services/oauth2/authorize` with PKCE and the scopes `api refresh_token`;
    - the token comes from `/services/oauth2/token`, and the account label from `/services/oauth2/userinfo`.
- **The org's own API address:** the token response carries `instance_url`. It is stored with the encrypted
  tokens and kept on refresh, and every API call goes to `{instance_url}/services/data/v62.0/`.
- **First pass** (SOQL `query`, `nextRecordsUrl` paging, five pages a run):
    1. active users (`Id`, `Email`), matched by email;
    2. contacts: `Id, FirstName, LastName, Email, MobilePhone, Phone, LeadSource, OwnerId`;
    3. open leads (`IsConverted = false`), with the same fields plus `Status` (the "Salesforce status: …" tag) and
       a "Salesforce lead" tag.
- **Changes:** each later run adds `LastModifiedDate > {last run}` (a minute of overlap).
- **Limits:** when Salesforce's API limit is reached (429, or 403 `REQUEST_LIMIT_EXCEEDED`), the run carries on
  later. Any other 401 or 403 asks for the connection to be made again.
- **Import rules:** the same as every CRM (`Crm\Connect\Importer`).
- **Propertybase:** the same driver. Its records are labelled "Propertybase", and it uses the Salesforce card's keys
  (`oauth.keys_from = salesforce`; setup `needs: app`), so HQ sets up one connected app for both.
- **Not yet:** notes, which live in ContentNote and Task records in Salesforce, and Propertybase's listing and deal
  objects. Both come with the Transactions connectors (C5).

## HQ setup (shown on the Salesforce card)

1. In RightAlly's own Salesforce org (a free Developer Edition works), open Setup → App Manager → New Connected App
   (or External Client App).
2. Name it RightAlly, enable OAuth, and use the return address shown on the card as the callback URL.
3. Add the scopes "api" and "refresh_token, offline_access", and tick **Require PKCE**.
4. Paste the consumer key and secret (encrypted, write-only, password-confirmed). Then switch Salesforce on, and
   Propertybase too.

No partner agreement is needed. AppExchange and Propertybase marketplace listings are optional.

## Cloze

Cloze has a public API with a personal key. However, the reference for its people feed is only in its OpenAPI
file, which we couldn't read here. It is built once Cloze confirms that endpoint; its HQ card says so.

## Tests

`tests/Feature/Connect/SalesforceTest.php`:

- one-click connect stores `instance_url`;
- contacts across pages and open leads come over with their owner and status;
- later runs ask only for changes;
- Propertybase uses the Salesforce keys;
- the connection is for the brokerage only.
