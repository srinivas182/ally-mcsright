# 07 — Sierra Interactive and GoHighLevel, and CRMs that need a partner agreement (Sprint C2c, Team A)

Two more "paste your key" CRMs follow the shared import rules (`Crm\Connect\Importer`, specs/connect/06). The other
four planned CRMs only open their API to approved partners, so their HQ cards say exactly what to apply for.

## Sierra Interactive

- **Key:** in Sierra, click the gear icon → Integrations → Sierra Interactive. Each site has its own key, so
  there is nothing for HQ to register.
- **API:** `https://api.sierrainteractivedev.com`, with the headers `Sierra-ApiKey` and
  `Sierra-OriginatingSystemName: RightAlly`.
- **Leads:** `GET /leads/find?includeTags=true&sortColumn=Id&sortOrder=asc&pageSize=100&pageNumber=…`, three pages
  a run.
    - Fields read: `id`, `firstName`, `lastName`, `email`, `phone`, `tags`, `leadStatus` (kept as the
      "Sierra status: …" tag) and `source`.
    - Each lead's agent comes from `assignedTo.agentUserId` and `agentUserEmail`, and is matched to a RightAlly
      person by email.
- **Notes:** `GET /notes/{leadId}`; system items are skipped.
- **After the first pass:** each run asks only for leads changed since the last pass (`leadUpdateDateFrom`, with
  a minute of overlap).
- **Partner (optional):** to appear in Sierra's own integrations directory, email support@sierrainteractive.com.

## GoHighLevel

- **What the person enters:**
    - a **Private Integration token** (sub-account → Settings → Private Integrations, with "View Contacts", plus
      "View Users" so contacts keep their agent);
    - the **Location ID** (Settings → Business Profile).
- **Checks:** both are checked straight away. A badly formed Location ID and a refused token each get their own
  plain message.
- **Contacts:** `POST https://services.leadconnectorhq.com/contacts/search` (`Version: v3`), 100 a page, three
  pages a run.
    - The first pass reads oldest first (`dateAdded` ascending) and moves on with `searchAfter` from the last
      contact on each page.
    - After that, each run reads by most recent change (`dateUpdated` descending) and stops at the last run.
    - Fields read: `id`, `firstName`, `lastName`, `email`, `phone`, `tags`, `source` and `assignedTo`.
- **Agents:** `GET /users/?locationId=…` (`Version: 2021-07-28`), matched by email. This is best effort.
- **Notes:** `GET /contacts/{id}/notes` (`Version: 2021-07-28`). HTML is stripped.
- **Partner (optional):** a GoHighLevel Marketplace app would allow one-click connect instead of a token.

## CRMs that need partner access (shown on HQ's cards as "Needs partner access")

| App          | Why                                                        | What HQ applies for                                                           |
| ------------ | ---------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Real Geeks   | Leads are shared only with approved partners (Lead Router) | Real Geeks partner support                                                    |
| BoomTown     | API only for approved partners                             | BoomTown (Inside Real Estate) partner programme                               |
| Top Producer | No public API                                              | Partner access from Constellation Real Estate Group                           |
| Wise Agent   | OAuth credentials only for partner integrations            | wiseagent.com/docs/api.asp: app name, logo, redirect domain, `contacts` scope |

When a partner agreement comes through, the app is built like Follow Up Boss (one-click OAuth) or Lofty (a key),
using the same import rules. People can already press "Ask for it", so HQ sees the demand.

## Tests

`tests/Feature/Connect/SierraGoHighLevelTest.php`:

- Sierra brings leads with agent, tags, status and notes (system notes skipped), then asks only for changed leads;
- GoHighLevel checks the Location ID and token;
- GoHighLevel reads page by page with `searchAfter` and notes, then reads the most recent changes;
- the partner notes and the steps are shown.
