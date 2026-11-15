# Connect 13 — Accounting: QuickBooks Online and Xero

Sprint **C6** (Team A with Team B). Plan item: A54.

## Goal

The brokerage's own books stay right without re-typing. Every journal RightAlly posts is posted to the
brokerage's accounting app automatically, in order, once. That covers commissions recognised, fees, revenue share,
payouts, referral fees and their reversals. Brokers and their bookkeepers keep working in QuickBooks or Xero.

## Rules (both apps)

1. **One way.** RightAlly's ledger is the source (specs/finance/01: balanced, immutable journals, corrections as
   reversals), so the books always match it. Nothing is read back.
2. **From a date.**
    - Journals dated on or after the **post-from** date go to the books. The date defaults to the day the books were
      connected, and the owner can change it under Finance → Books.
    - Earlier history stays in the books as it is.
3. **Accounts mapped once.**
    - Each of RightAlly's top-level accounts (commissions receivable, agent commissions payable, revenue share
      payable, company dollar and so on) is mapped to an account in the books.
    - Agent and deal sub-accounts post to their parent's account, with the agent or deal named on the line.
4. **In order, never twice.**
    - Journals post in the order they were posted in RightAlly. Each carries RightAlly's journal id as the app's
      idempotency key (QuickBooks `requestid`, Xero `Idempotency-Key`), and is remembered.
    - If a journal can't go, posting stops at that journal and the reason is shown on Finance → Books. That happens
      when an account isn't mapped yet, or the books refuse it, for example because of a closed period. The journal
      goes on the next run, which is hourly.
5. **Each entry is labelled** `RA-` plus the end of the journal id, with RightAlly's description and journal type.
   QuickBooks uses the DocNumber and private note; Xero uses the narration.
6. **Who can do what:** seeing Finance → Books needs `finance.ledger.view`. Mapping and the post-from date need
   `finance.ledger.adjust`.

## QuickBooks Online

- One click (OAuth 2). The scope is `com.intuit.quickbooks.accounting`, plus `openid` and `email` for the account
  label.
- Intuit names the company (`realmId`) on the return address. The central OAuth return keeps it with the tokens
  (`realm_id`), and refreshes keep it.
- Posting uses Accounting API v3 `journalentry` at minor version 75 (debit and credit lines with AccountRef).
  Accounts are read with a query on active accounts.
- **HQ setup:** create an Intuit developer app with the Accounting scope and the return address as its redirect
  URI. Complete Intuit's production questionnaire, then paste the production client ID and secret.

## Xero

- One click (OAuth 2 with PKCE). It uses Xero's **granular scopes**, which apps created after 2 March 2026 must
  use: `accounting.manualjournals` and `accounting.settings` (chart of accounts), plus `openid`, `profile`,
  `email` and `offline_access`.
- The organisation is the first one the sign-in allowed, from Xero's connections list. It is remembered, and its
  name is shown.
- Posting uses `ManualJournals` with status POSTED. Line amounts are positive for a debit and negative for a credit,
  by account code.
- **HQ setup:** create a Web app with the return address as its redirect URI, then paste the client ID and secret.
  Connecting more than 25 organisations needs Xero App Partner certification, and Xero's developer pricing applies.

## Acceptance

- QuickBooks connects in one click and keeps the company. Nothing posts until the accounts are mapped. Once they
  are, a commission journal posts as one balanced entry, with the agent named on the payable line. Running again
  posts nothing twice, and journals before the post-from date never post.
- A journal the books refuse is shown with QuickBooks' reason, and posting carries on from that journal on the
  next run.
- Xero connects with the granular scopes, finds the organisation and posts a manual journal with the idempotency
  key.
