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
5. **Commission:** deals that closed before the connection's "pay from" date are history, paid in the old system,
   so no commission is calculated for them. A deal that closes on or after that date is **paid in RightAlly
   automatically** (C5a-3, below).
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

## C5a-2: SkySlope and Lone Wolf

| App                                      | Access                                                                                                                                    | What comes over                                                                                                                                                           | Limits                                                                                                                                                                                    |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SkySlope                                 | HQ: client ID and secret from SkySlope's partner onboarding. Broker: their access key and secret (My Account → Integrations), pasted once | Sales and listings, with stage, property, prices, contract and closing dates, buyers, sellers, title, escrow, lender and attorney, agent and co-agents; users and offices | No webhooks: changed files every hour (`modifiedAfter`). 100 requests a minute. The API documents uploads but **no document download**: files come from SkySlope's own export (see below) |
| Lone Wolf (TransactionDesk / brokerWOLF) | Partner only: Lone Wolf issues RightAlly's client ID. The brokerage creates a separate read-only Lone Wolf user for RightAlly             | Deals, with address, price, status and dates, client and business contacts, external agents and commission tiers; the roster (members, offices)                           | Login gives a 24-hour token. No webhooks: hourly. Documents are not in the Deals API                                                                                                      |

**Files for SkySlope and Lone Wolf:** the brokerage asks the vendor for its full file export, a ZIP of PDFs by
transaction, and uploads it once under **Deals → Switch from another app**. RightAlly matches each folder to the
imported deal by address and files the PDFs into it. This is part of C5a-2.

**How each one maps:**

- **SkySlope:**
    - Status: active and incomplete → pre-contract; pending → under contract; closed → closed; cancelled and expired →
      fell through; archived → closed when it has an actual closing date, otherwise fell through.
    - A sale made from a listing carries the listing's id. The listing and its sale stay **one deal**, and a listing
      that is already pending or closed is left to its sale.
    - Office gross commission is kept as the GCI.
    - First run: users, then every sale, then every listing (10 a page, 10 pages a run). Then hourly: the bulk export of
      files changed since the last pass. Everything again weekly.
- **Lone Wolf:**
    - Lone Wolf does not publish its status and class codes, so the state comes from the deal's dates:
        - finalised, or closed on a past date with a price → closed;
        - firm or offer date → under contract;
        - otherwise pre-contract.

        Only a status that says cancelled, collapsed, void or terminated counts as fallen through.

    - Type: lease classes → lease; two ends → dual; listing classes → listing; otherwise buyer.
    - The agent is the first commission agent, matched through the roster's email.
    - 20 deals a run with their contacts. Then hourly, deals changed since the last pass (`modifiedTimestamp`).
      Everything again weekly, roster included.
    - The codes are checked in Lone Wolf's partner sandbox before go-live.
- **Vendor requests:** neither vendor publishes a partner email address. The ready-written requests on the HQ cards
  have no address, and HQ adds the contact it gets from each vendor.

**Bring over files** (Deals → Switch from another app, for owners, staff and deal reviewers):

- Upload the vendor's ZIP export (up to 500 MB). It is read in the background and deleted once read.
- Each PDF, JPEG or PNG is filed into the deal whose street address its folder names, looking from the innermost
  folder out. "Drive", "Street" and similar words are matched to their short forms.
- A folder that names no deal, or two deals at the same address, is listed for the broker and never guessed.
- Uploading again skips files already filed.
- Limits: 20,000 files, 4 GB unpacked, 25 MB a file. Nothing is written to disk under the ZIP's own paths.

**Credentials:**

- SkySlope's access secret and the Lone Wolf user's password are encrypted and write-only, like every key.
- The Lone Wolf password belongs to a dedicated integration user, never a person's own login.

## C5a-3: close there, paid here (approved 2026-11-15)

A brokerage that keeps dotloop, SkySlope or Lone Wolf for paperwork still runs its money in RightAlly. All
transaction apps follow the same flow, because they share the deal import.

1. **Automatic, with no approval queue.**
    - A deal that closes in the other app on or after the connection's **pay-from date** is paid at once, on the
      agent's own plan: split, fees, cap and revenue share, exactly as for a deal closed here.
    - The pay-from date defaults to the day the app was connected. The owner can change it per connection under
      Deals → Switch from another app.
2. **The other app owns the deal.**
    - If the deal's price, GCI, closing date, agent or type changes there after payment, the payment is reversed and
      made again.
    - If the deal is reopened or cancelled there, the payment is reversed.
    - While a deal is under contract there, a pending calculation keeps forecasts and cap previews current.
3. **The broker can override any closed deal**, in RightAlly only. This is open to the owner or anyone with
   `compensation.calculations.manage`, and needs a reason, which is audited. The options are:
    - the agent's split %;
    - waive the deal's fees (post-split and post-cap);
    - don't count the deal toward the cap;
    - no revenue share on the deal.

    Saving reverses the payment and pays again with the overrides. The overrides travel with the deal's
    calculation, so later automatic re-payments keep them.

4. **Reverse commission** undoes the split, fees, cap progress and revenue share in RightAlly only. Nothing is
   written back to the other app. A reversed deal is not paid again until it changes in the other app, which
   RightAlly reads and then pays again, or until the broker presses **Pay again**.
5. **Nothing is guessed.**
    - A closed deal with no GCI shows as "Needs the GCI". The broker enters it once and the deal is paid.
    - A deal the plan can't pay, for example because the agent has no plan, shows as "Needs a fix" with the reason.
    - Both are listed under Deals → Switch from another app.
    - A sync never fails because of money.
6. **How agents get their own commission on these deals** is chosen by the owner:
    - **Paid outside RightAlly** (the default): at closing by title, or by the old back office. Nothing is
      "on its way" in the agent's balance. Money received later creates no agent payable, so nothing is paid twice.
    - **Paid through RightAlly payouts:** the agent's net becomes payable once the money received is recorded, then
      paid in a payout run, automatically if the owner has turned automatic payouts on.

    Either way, **revenue share and bonuses go to each wallet** and are paid in payout runs or with Get paid now.
    RightAlly never holds the money: a wallet is what the brokerage owes, paid from the brokerage's own bank.

## C5b: Open to Close, Brokermint and DocuSign; partner-only apps marked

Every deal app here uses the shared deal import, so "close there, paid here" and every rule above apply unchanged.

| App           | Access                                                                                                                                   | What comes over                                                                                                                                                                                                                           | Notes                                                                                                                                                                                                                                                                                                                                                                             |
| ------------- | ---------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Open to Close | The brokerage's own API token (Apps & API dashboard), sent as `api_token`                                                                | Properties as deals: address, price, status, client type, contract and closing dates, other `…_date`/`…_deadline` fields as key dates; contacts by role (our agent by email, buyers, sellers, lender, title, escrow, attorney, inspector) | 50 a page, newest change first. Hourly runs stop at properties already up to date, and everything is read again weekly. Fields are read whether sent flat or as field values (key, value).                                                                                                                                                                                        |
| Brokermint    | The brokerage's API token, which Brokermint support issues on request (`api_key`)                                                        | Transactions as deals: address, price, status, side (`representing`), acceptance and closing dates, total gross commission; contacts by role; users matched by email                                                                      | 200 a page. Every hour the list is read in full (there's no "changed since" filter), and contacts are read only for transactions changed since the last pass. Dates are read as YYYY-MM-DD, ISO or Unix milliseconds. An API that ignores the offset is detected, so the run stops instead of looping.                                                                            |
| DocuSign      | One click (OAuth authorisation code, scopes `signature extended`). HQ needs an integration key that has passed DocuSign's Go-Live review | Completed envelopes are filed on the right deal as one signed PDF, including the certificate                                                                                                                                              | Every 15 minutes, envelopes completed since the last check; the first run goes back a year. The deal is the one in the envelope's "RightAlly deal" custom field, otherwise the one address the subject names (matched like file exports, never guessed). Envelopes that name no deal yet are retried for 30 days. An agent's connection files only on their own deals. Read only. |

**Partner-only apps** are marked on the HQ card with what to apply for:

- **Paperless Pipeline:** no data API for other apps, only single sign-on and Zapier. Its file export comes over
  with Deals → Switch from another app.
- **Authentisign and zipForm:** Lone Wolf's Transact API covers transactions, forms, signings and signed documents,
  for Lone Wolf partners only. This is the same partner access as the Lone Wolf card.
- **Form Simplicity:** no public API. Signed PDFs can be uploaded or brought over by file export.

## Acceptance (C5b)

- Open to Close connects with a token, refuses a wrong one, and brings properties over with agent, parties and key
  dates, whether fields come flat or as field values.
- Brokermint brings transactions over with dates sent as milliseconds, and doesn't re-read contacts for unchanged
  transactions.
- DocuSign connects in one click and files completed envelopes on the deal the subject or custom field names, once
  each. Unmatched envelopes are filed automatically once their deal exists.

## Acceptance (C5a-3)

- A deal that closes there after the pay-from date is paid on the agent's plan, and its revenue share lands in the
  sponsor's wallet. Running the import again changes nothing; deals that closed earlier are left as history.
- A change there pays again; reopening there reverses.
- Overrides (split, fees, cap, revenue share) and reversals work in RightAlly only, for the owner or a compensation
  manager, with a reason. A reversed deal stays reversed until it changes there or the broker pays it again.
- A deal with no GCI waits for the broker, then is paid. The owner chooses whether agents are paid outside or
  through payouts, and moves the pay-from date.

## Acceptance (C5a-2)

- SkySlope signs in with RightAlly's client pair signed by the brokerage's access secret, and refuses a wrong key.
- Sales and open listings come over with agent, parties, dates and office commission. A listing and its sale are one
  deal. Then only changes arrive, hourly.
- Lone Wolf signs in with the read-only user, matches agents through the roster, and brings deals with client and
  business contacts and other-side agents. Then only changed deals arrive.
- A file export ZIP files each document into the deal its folder names, keeps each file once, lists unmatched
  folders, and is deleted once read. Only reviewers can use it.

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
