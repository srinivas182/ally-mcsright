# 08 — HubSpot and Pipedrive, and the remaining CRMs (Sprint C2d-1, Team A)

Two more "paste your token" CRMs follow the shared import rules (`Crm\Connect\Importer`, specs/connect/06). The rest
of the planned CRMs are marked on HQ's cards with what is needed: partner access, or the next build.

## HubSpot

- **Token:** a private app access token. In HubSpot, Settings → Integrations → Private Apps → create one called
  "RightAlly" with the scopes `crm.objects.contacts.read` and `crm.objects.owners.read`. The connect form shows
  these steps, and the token is checked straight away.
- **API:** `https://api.hubapi.com/crm/v3/`, with a Bearer token.
- **First pass:**
    - owners (`/owners`), matched to RightAlly people by email;
    - contacts (`/objects/contacts`, 100 a page, `paging.next.after`), five pages a run;
    - notes (`/objects/notes?associations=contacts`), each linked to its first contact.
- **Fields read:**
    - `firstname`, `lastname`, `email`, `mobilephone` or `phone`;
    - `hs_lead_status` (the "HubSpot lead status: …" tag);
    - `lifecyclestage` (a "HubSpot lifecycle: …" tag);
    - `hubspot_owner_id` (the agent);
    - `hs_analytics_source`.
- **Changes:** each later run searches contacts by `lastmodifieddate` and notes by `hs_lastmodifieddate`, from the
  last run (a minute of overlap). Notes found by search get their contact through the v4 associations batch read.
- **Partner (optional):** a HubSpot public app would allow one-click connect.

## Pipedrive

- **Token:** the person's own API token, from avatar → Personal preferences → API. It is sent as the header
  `x-api-token`. The connection is labelled with the company name (`/api/v1/users/me`).
- **First pass:**
    - users (`/api/v1/users`), matched by email;
    - people (`/api/v2/persons`, cursor paging);
    - notes (`/api/v1/notes`, start paging).
- **Fields read:**
    - `first_name` and `last_name`, or `name` split in two;
    - the primary email and phone;
    - `owner_id` (the agent).
- **Changes:** each later run reads people with `updated_since` set to the last run, plus the notes of those
  people.
- **Partner (optional):** a Pipedrive Marketplace app would allow one-click connect.

## The remaining CRMs (HQ cards)

| App          | Status                                                                                      |
| ------------ | ------------------------------------------------------------------------------------------- |
| Salesforce   | Next build (C2d-2): RightAlly's own Salesforce connected app, set up by HQ; one-click OAuth |
| Propertybase | Runs on Salesforce; built with the Salesforce connector (C2d-2)                             |
| Cloze        | Public API with a personal key; next build (C2d-2)                                          |
| CINC         | Partner access needed (CINC / Fathom)                                                       |
| Brivity      | Partner access needed                                                                       |
| LionDesk     | Partner access needed (Lone Wolf partner programme)                                         |
| Rechat       | Partner access needed                                                                       |

## Tests

`tests/Feature/Connect/HubSpotPipedriveTest.php`:

- HubSpot contacts with owner, status, lifecycle and notes, then searches for changes;
- a bad HubSpot token is refused;
- Pipedrive people with owner and notes, then `updated_since`;
- the partner and next-build notes are shown.
