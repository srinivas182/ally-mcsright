# 19 · IDX coverage, connectors and MLS onboarding (IDX1–IDX10)

Agreed with Srini on 21 Nov 2026 from the brokers' documents (Repliers & Spark workflow, Super Admin & broker
onboarding, connector blueprint, multi-MLS PRD), adjusted to RightAlly.

## Decisions

- **Launch states:** Alabama, Florida, Louisiana, Mississippi, Texas (all Texas boards; HAR first). HQ can open any state.
- **Connectors:** Repliers (main for Texas and Florida boards it carries) and Spark API (main for MLS United). MLS Grid,
  Trestle, Bridge and direct RESO stay available as backups. Keys are set in HQ › Outside services like other
  connectors (write-only, Off/Test/Live). Brokers and agents never see connector names.
- **One board = one winning connector = one signed agreement.** HQ can move one brokerage to another connector.
- **Price:** $499 includes one board. Each further board (any state) adds that board's fee: our connector cost plus the
  connector's margin (default 30%), or the fee HQ sets. Defaults for HQ to review.
- **Repliers:** copy listings into RightAlly if Repliers allows it (one search path, same MLS rules, duplicates and AI);
  otherwise a live-search engine with a short cache, behind the same search.
- **Disclaimers are read-only for brokerages:** the MLS logo, disclaimer and attribution come from the board's IDX
  display template, set by HQ only; brokerages can never edit or remove them.

## Build order

| Sprint | What                                                                                                                                                                                                    |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| IDX1   | States (all 50, launch 5 open), MLS board directory (main/backup connector, our cost, client fee, IDX template), connectors (status, margin)                                                            |
| IDX2   | Spark connector (RESO replication endpoint), tested with Spark's test key                                                                                                                               |
| IDX3   | Repliers connector, tested with Repliers' test key                                                                                                                                                      |
| IDX4   | Per-board status (demo → awaiting signature → sent → MLS review → live / needs fixing / cancelled), HQ approval queue, per-brokerage connector override, broker-supplied Spark keys; approval reminders |
| IDX5   | Demo listings on every new site (brokerage can switch off), each board going live on its own                                                                                                            |
| IDX6   | IDX agreement per board: template, signing order, send-to and CC/BCC, keep a copy, site check first; nightly compliance watch                                                                           |
| IDX7   | Sign-up board picker by state, $499 with one board plus further boards, add or remove boards later                                                                                                      |
| IDX8   | "My board isn't listed" requests; agents inheriting the brokerage's boards                                                                                                                              |
| IDX9   | Automatic area pages (city, neighbourhood, school) from live listings                                                                                                                                   |
| IDX10  | Plain-English home search through Ailly; instant new-listing alerts (opt-in, 8am–9pm)                                                                                                                   |

## IDX2 · Spark API (built)

- **Key:** HQ › Outside services › _MLS listings: Spark API_. Test uses Spark's demo data (address
  `https://sparkapi.com/Reso/OData`); Live uses RightAlly's replication key (`https://replication.sparkapi.com/Reso/OData`,
  ask api-support@sparkplatform.com for replication permission). "Check connection" reads one listing.
- **Each board's feed:** HQ › MLS connections → add a connection with provider _Spark API_. Leave the token empty to use
  RightAlly's key, or paste a key that board issued. Spark is read at most every 10 minutes (enforced).
- **How it reads:** a closed time window (`ModificationTimestamp gt <last> and lt <now − 1 min>`, as Spark asks because of
  its caching), up to 1,000 listings a page with photos, following Spark's next-page links.

## IDX3 · Repliers (built)

- **Key:** HQ › Outside services › _MLS listings: Repliers_ (`REPLIERS-API-KEY`). A test key reads Repliers' sample data
  (about 1 request a second). "Check connection" reads one listing.
- **Each board's feed:** HQ › MLS connections → provider _Repliers_, with the Repliers board ID in "originating system"
  (empty: all boards the key can read, such as the sample data). Leave the token empty to use RightAlly's key.
- **How it reads:** Repliers' listings search, oldest change first (`sortBy=updatedOnAsc`, `minUpdatedOn`, both on and off
  the market), 100 a page; each listing becomes a RESO record (status from A/U and the last status, address, price,
  rooms, map point, agent and office, photos from `cdn.repliers.io`). From there it is the same sync as every feed.
- **Open question for Repliers:** we copy listings into RightAlly (the recommended way). If Repliers' agreement asks for
  live searching instead, a live-search engine is added behind the same search; nothing else changes.

## IDX4 · Each brokerage's boards (built)

- **Brokerage (Settings › MLS):** add a board it belongs to (open state, public, priced; one of each). It starts on demo
  listings. The brokerage gives its MLS member ID, office ID, licence number, managing broker and email (password
  confirmed), and may paste its own feed key from that MLS (encrypted, never shown again). A step bar shows: Your details
  → Signatures → Sent to the MLS → MLS review → Live. "Needs fixing" shows HQ's note; the brokerage fixes and sends again.
  Removing a board hides its listings at once.
- **HQ (IDX coverage › Brokerage boards):** move a board along (signed and sent, waiting on the MLS), send it back with a
  note, change the connector for this brokerage only (not while live), **Turn on live** (password confirmed) with the
  board's linked feed, another feed, or the brokerage's own key, and cancel.
- **Going live** turns on the brokerage's MLS licence for that feed, which every search already checks. A feed made from
  a brokerage's own key is locked to that brokerage; no other brokerage can be licensed on it.
- **Emails** (platform email): needs fixing, live, and signing reminders 1 and 3 days on (`idx:remind`, daily), to the
  owners and the managing broker. Brokerages never see connector names.

## IDX5 · Demo listings (built)

- **HQ:** in MLS connections, "Use as demo data" on a feed (for example Repliers' sample data, or Spark's demo data).
- **Brokerage websites:** a brokerage that has added a board but has none live shows the demo feeds' listings, each marked
  **Sample listing** (a note on the listing page), never indexed by search engines, with the demo feed's own notice. The
  first board that goes live replaces them; the back office never shows demo listings.
- **Brokerage switch:** Settings › MLS › "Show demo listings until my first board is live" (on by default).
- **MLS notices are read-only for brokerages:** the logo, disclaimer and attribution come only from HQ's display
  templates, are printed on every listing and in the footer of every page with listings, and no brokerage screen or
  setting can change or hide them.

## IDX6 · The IDX agreement and the compliance watch (built)

- **HQ, per board (IDX coverage › board › IDX agreement):** "Send the signed agreement to" (for example Repliers'
  onboarding desk or the MLS), CC, BCC, the terms printed on the signature page (with `{brokerage}`, `{mls_id}`,
  `{office_id}`, `{license}`, `{website}`, `{managing_broker}`, `{board}`, `{date}`), whether the managing broker signs
  too, and the MLS's own agreement as a PDF (optional). With no "send to", HQ handles the paperwork by hand.
- **When the brokerage sends its details:** RightAlly builds the agreement (the MLS's PDF, then a signature page with the
  brokerage's facts and terms) and sends it through its own e-signature, as RightAlly: the brokerage owner signs first,
  then the managing broker. Signing reminders (IDX4) keep going until it is signed.
- **When everyone has signed:** the sealed agreement (with its signature certificate) is kept, the brokerage's website is
  checked (it must open over https and not ask for a password), and the agreement is emailed to the board's addresses
  with the brokerage's details; the board shows "Sent to the MLS". If the website fails, the board goes to "Needs fixing"
  with the reason; HQ can "Send signed agreement to the MLS now" anyway. HQ and the brokerage can download the signed copy.
- **Nightly compliance watch (`idx:compliance`):** each live board's website home search must open and show the board's
  MLS notice (its fixed words). Problems are shown in the queue and emailed to HQ's Watchtower recipients.

## IDX7: boards at sign-up and extra-board fees

- The brokerage sign-up page (`/start`) and the agent sign-up page (`/agents`) show a "Your MLS boards" step when HQ has
  opened at least one state with a public, priced board. The broker ticks the states where they hold a licence, then
  the boards they belong to. Connector names are never shown. Without JavaScript every board is listed; the small
  script only hides boards outside the ticked states and adds up the total.
- Only boards offered at that moment are kept (open state, public, priced), at most 20. They are stored on the
  `signups.idx_boards` column and, once the payment goes through, added to the new brokerage on demo listings
  (`Subscriptions::add` with no user). A board that closed in the meantime is skipped and reported; the brokerage can
  add another from Settings › MLS.
- **Fees** (`Idx\BoardFees`): the package includes one board. The included board is the dearest one the brokerage has,
  so it always pays the least. Each other board adds its monthly fee to the invoice on the 1st, in the same run as the
  other extras (`billing:bill-addons`), once a month (`idx-boards:{tenant}:{Y-m}` in `billing_gateway_events`).
- Settings › MLS shows "Included in your plan" on the included board and "+$X a month on your invoice" on the others,
  with a one-line total.

Defaults for HQ to review: extra boards are billed monthly even on yearly plans; no proration (a board added on the
15th is billed in full on the next 1st, a board cancelled before the 1st is not billed); one-off setup fees are not
charged yet.
