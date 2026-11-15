# Connect 12 — Transaction management: switch in a weekend (dotloop, SkySlope, Lone Wolf)

Sprint **C5a** (Team A with Team B). Plan items: A53 (switch in a weekend), B41 (dotloop, SkySlope, Lone Wolf).
Split in two:

- **C5a-1:** the shared deal import, plus dotloop.
- **C5a-2:** SkySlope and Lone Wolf.

## Goal

A brokerage moving to RightAlly keeps its whole transaction history and its live deals. It connects the old
transaction app once. Every deal comes over with its address, price, status, agent, parties, contract dates and
files, and keeps coming over until the brokerage switches the old app off. Nobody re-types a deal.

## Shared rules: the deal import (all three apps)

`Modules\Transactions\Connect\DealImporter` is the one set of rules, whichever app the deals come from.

1. **Safe to repeat.** Each deal and file is remembered by the other app's id (`connect_external_ids`). Nothing is
   added twice.
2. **The other app leads while both are in use.** Its address, price, dates and status update the deal here. A deal
   that has since been **closed in RightAlly** (commission paid here) is never changed again.
3. **Agent:**
    - Matched by the other app's user id or email to a RightAlly person.
    - Everything in an agent's own account belongs to that agent.
    - Deals whose agent isn't on RightAlly yet go to the person who connected. They move to the agent after the
      agent joins, on the next full read. Full reads happen at least weekly.
4. **Open deals** (pre-contract and under contract) get the brokerage's checklist, like a deal started here.
   **Closed and fallen-through deals** are history: no checklist, and closed ones are locked.
5. **No commission is calculated** for an imported deal, because it was paid in the old system. The GCI is kept for
   reports.
6. **Parties:**
    - Buyers, sellers, the other side's agent, lender, title or escrow, attorneys and inspectors come over.
    - Admins and other vendors are left out.
    - Buyers and sellers are linked to existing CRM contacts by email or phone. No contacts are created; the CRM
      import does that.
7. **Dates:** contract dates other than the contract and closing dates become the deal's key dates (option period,
   inspection and so on). A date already marked done here is not moved.
8. **Files:**
    - Only real PDFs, JPEGs and PNGs are kept. The type is checked from the file's bytes, never from the other app's
      label.
    - The limit is 25 MB, and the same file is kept once per deal.
    - Files go into the deal's **unsorted documents** for the broker to file into the checklist. This is allowed even
      on a closed, locked deal, because they are the deal's record.
    - Imported files are **not read by AI automatically**, since that costs credits. They can be read on request.
9. **The deal page** says "Brought over from dotloop", and an audit entry `transactions.deal.imported` is written
   for each new deal.

## C5a-1: dotloop

| Item         | Detail                                                                                                                                                                                                  |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sign-in      | One click (OAuth 2, authorisation code). Token request uses HTTP Basic. Tokens last about 12 hours and refresh automatically                                                                            |
| Who          | Agents (their own loops) or a broker/admin login (every profile they can see, so the whole office)                                                                                                      |
| HQ setup     | Register RightAlly at info.dotloop.com/developers (free, reviewed by dotloop) and accept the API License Agreement. Scopes: `account:read`, `profile:read`, `loop:read`. Paste the client ID and secret |
| First import | Every loop in every profile, oldest change first, 25 loops a run (each with participants and files). Runs continue straight away until all loops are over                                               |
| Live         | After the first import, one webhook subscription per profile (`LOOP_CREATED`, `LOOP_UPDATED`, `LOOP_MERGED`) to the connection's own address                                                            |
| Safety net   | Every hour, loops changed since the last pass (`updated_min`). Everything again once a week                                                                                                             |
| Read only    | Nothing is written back to dotloop                                                                                                                                                                      |

**Mapping:**

- **Loop type:** listing for sale → listing; purchase offer → buyer; lease listing or lease offer → lease. "Other"
  loops are not property deals and are skipped.
- **Status:**
    - pre-offer, pre-listing, private listing and active listing → pre-contract;
    - under contract → under contract;
    - sold or leased → closed;
    - **archived** → closed when it has a price and a closing date in the past, otherwise fallen through ("Archived in
      dotloop before closing").
- **Details:**
    - Property Address (street number, street name, unit, city, State/Prov, Zip/Postal Code, MLS Number);
    - Financials (Purchase/Sale Price, Sale Commission Total);
    - Contract Dates (Contract Agreement Date, Closing Date, and the other dates as key dates).
- **Agent:** the loop's listing agent (for listings) or buying agent (for purchases), matched by email.
- **Files:** every document in every folder, downloaded as PDF.

**Webhooks:**

- Signed with HMAC-SHA1 over `timestamp.body`, using RightAlly's own signing key. The key is derived from the app key
  and never stored or shown.
- Anything unsigned, wrongly signed or more than a day old is refused (401).
- A valid event re-reads that one loop. A merged loop follows dotloop's redirect to the loop it became.

**Disconnect:** the webhook subscriptions are deleted and the sign-in is revoked.

**Engine change:** a read-only webhook contract, `ReceivesWebhooks`, is split out of `TwoWay`. TwoWay adds sending
changes back. Facebook Lead Ads and dotloop only receive.

## C5a-2: SkySlope and Lone Wolf (next)

| App                                      | Access                                                                                                                                    | What comes over                                                                                                                                                           | Limits                                                                                                                                                                                    |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SkySlope                                 | HQ: client ID and secret from SkySlope's partner onboarding. Broker: their access key and secret (My Account → Integrations), pasted once | Sales and listings, with stage, property, prices, contract and closing dates, buyers, sellers, title, escrow, lender and attorney, agent and co-agents; users and offices | No webhooks: changed files every hour (`modifiedAfter`). 100 requests a minute. The API documents uploads but **no document download**: files come from SkySlope's own export (see below) |
| Lone Wolf (TransactionDesk / brokerWOLF) | Partner only: Lone Wolf issues RightAlly's client ID. The brokerage creates a separate read-only Lone Wolf user for RightAlly             | Deals, with address, price, status and dates, client and business contacts, external agents and commission tiers; the roster (members, offices)                           | Login gives a 24-hour token. No webhooks: hourly. Documents are not in the Deals API                                                                                                      |

**Files for SkySlope and Lone Wolf:** the brokerage asks the vendor for its full file export, a ZIP of PDFs by
transaction, and uploads it once under **Transactions → Bring over files**. RightAlly matches each folder to the
imported deal by address and files the PDFs into it. This is part of C5a-2.

**Credentials:**

- SkySlope's access secret and the Lone Wolf user's password are encrypted and write-only, like every key.
- The Lone Wolf password belongs to a dedicated integration user, never a person's own login.

## Acceptance (C5a-1)

- Connecting dotloop as a broker brings every listing, purchase and lease loop over as a deal: address, price,
  status, agent (by email), parties, key dates and PDF files. "Other" loops and non-PDF files are left out.
- Closed loops arrive closed and locked, with no checklist and no commission calculation. Open loops get the
  checklist.
- Running again adds nothing twice. It reads only loops changed since the last pass, and downloads no file twice.
- A deal closed in RightAlly since is not changed.
- A signed dotloop webhook updates the deal within seconds. Unsigned, wrongly signed or stale ones are refused.
- An agent's own connection gives the agent every deal in it, and buyers and sellers are linked to the agent's
  existing contacts.
- Disconnecting deletes the subscriptions and revokes the sign-in.
