# 06 — Lofty and BoldTrail (kvCORE) (Sprint C2b, Team A)

Two of the biggest brokerage CRMs after Follow Up Boss. Both give each account its own API key, so connecting is
"paste your key": nothing for HQ to register. Both follow the same import rules as every CRM.

## Shared import rules (`Crm\Connect\Importer`)

Follow Up Boss now uses the same class, so every CRM behaves the same way:

- **Safe to repeat:** people and notes are remembered by the other app's id (`connect_external_ids`).
- **No duplicates:** the same email or phone links to the existing contact, which is not overwritten.
- **Visibility:** a contact the connecting person can't see is left alone.
- **Ownership:**
    - a brokerage connection brings company contacts, each with their agent, matched by email;
    - an agent's own connection brings personal contacts.
- **Stage and tags:** the stage is kept as one tag, "{App} stage: …" (one at a time). Tags come across, up to 20
  per person.
- **Updates:** names of known people follow the other app.
- **Consent:** no texting consent comes across (TCPA).

## For the broker or agent

On the app's page, the connect form shows plain steps:

- **Lofty:** Settings → Integrations → API. Copy the key, paste it, press Connect.
- **BoldTrail:** Lead Engine → Lead Dropbox → My API Tokens. Choose "Contacts", press Generate, then copy, paste
  and Connect.

The key is checked straight away. A wrong key gets a plain message that says where to find the right one.

Keys are stored encrypted and are write-only, like every connection credential. The import starts as soon as the
app is connected.

## Lofty

- **API:** Lofty Open API, `https://api.lofty.com/v1.0/`, with the header `Authorization: token {API key}`.
- **Agents:** `GET /members` (`memberUserId`, `email`) are matched to RightAlly people by email.
- **Leads:** `GET /leads?sort=CreateTime&desc=false&limit=100&offset=…`, two pages a run.
    - Fields read: `leadId` (64-bit; kept as text), `firstName`, `lastName`, `emails[0]`, `phones[0]`,
      `tags[].tagName`, `stage`, `source` and `assignedUserId`.
    - Leads are read oldest first, so new leads come after the last one read. Each hourly run continues from that
      point and brings in only new leads.
    - Once a week the whole list is read again, to catch changes.
- **Notes:** `GET /notes?leadId=…` for each lead that is linked. System notes and deleted notes are skipped.
  Each note is saved as "From Lofty: …" at its original time (UTC).
- **Errors:** a 429 carries on in the next run. A 401 or 403 asks the person to connect again with a new key.

## BoldTrail (kvCORE)

- **API:** kvCORE Public API V2, `https://api.kvcore.com/v2/public/`, with `Authorization: Bearer {Contacts
  token}`.
- **Agents:** `GET /users`, best effort. A "Contacts" token may not see users; contacts then stay with the person
  who connected.
- **Contacts:** `GET /contacts?limit=100&page=…`, five pages a run, using `last_page` to know when to stop.
    - Field names differ between accounts, so each value is read from the names it may use:
        - `first_name`, `last_name`, `email`;
        - `cell_phone_1`, `cell_phone`, `phone`, `home_phone` or `work_phone`;
        - `hashtags` or `tags` (a leading # is removed);
        - `status_name` (a text status becomes the stage tag);
        - `source`;
        - `assigned_agent_id`, `agent_id` or `user_id`.
    - After a full pass, everything is read again daily. This is safe to repeat.
- **Not yet:** notes and two-way sync. They need BoldTrail's partner API from Inside Real Estate. HQ's card
  carries this as its partner note.
- **Before go-live:** check the field names against a live account (apidocs.kvcore.com). The driver already
  accepts the common variants.

## HQ

- Both apps need no RightAlly-level keys. HQ only switches them on.
- BoldTrail's card shows the partner note for notes and two-way sync.

## Tests

`tests/Feature/Connect/LoftyBoldTrailTest.php`:

- the steps are shown;
- Lofty leads come over with agent, tags, stage and notes (system notes skipped), then only new leads;
- a bad key is refused;
- BoldTrail is read page by page as the agent's own contacts (hashtags, status, phone);
- the daily re-read is held back within a day.
