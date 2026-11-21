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

## IDX8: "My board isn't listed" and agents inherit boards

- **Asking for a board.** Settings › MLS has "My board isn't listed": board name, state, website and a note. One
  request per board name and state per brokerage, at most 10 open at once. The brokerage sees only its own requests and
  their status. The sign-up page tells brokers they can ask for it after sign-up.
- **HQ › IDX coverage › Asked for.** Each request shows the brokerage and how many brokerages asked for the same board.
  HQ either adds it to the directory with a short code (it starts hidden, and every open request for the same board is
  linked to it) or closes it with a reason, which is emailed to the brokerage.
- **The day it opens.** When HQ makes a linked board public, everyone who asked is emailed once ("… is ready for your
  website") with a link to Settings › MLS.
- **Agents inherit boards.** An agent's own website uses the brokerage's boards, with the same notices; there is nothing
  for the agent to sign. My website shows the brokerage's boards and their status, read-only. Solo agents sign up as
  their own brokerage and add boards themselves.
- Table `idx_board_requests` (control database, listed in `ControlTables`).

## IDX9: automatic area pages

- Every **city, neighborhood and ZIP code** with at least three homes for sale gets its own page at `/market/...`:
  live figures (homes for sale, median price, price per square foot, days on market, new and reduced in 30 days), the
  newest homes, common questions with FAQ markup, breadcrumbs, and links between a city and its neighborhoods and
  ZIP codes. Neighborhoods come from the MLS subdivision (`river-oaks--houston-tx`); names in capitals are shown in title
  case. They are listed in `sitemap.xml` and `llms.txt` and on `/market`.
- **Your own words.** Settings › Website › Area pages lists every area. The brokerage can add a few paragraphs of local
  knowledge to any area (shown under the summary; plain text; checked against Fair Housing words like ads and review
  replies) or switch a page off (it then returns "not found" and leaves the sitemap).
- **Sample listings stay out of search engines.** While the website shows sample (demo) listings only, area pages are
  still shown, marked "These figures come from sample listings…", but are `noindex`, and the sitemap and llms.txt list
  no area pages and no listings.
- Table `site_areas` (cell database, scoped to the brokerage).

## IDX10: plain-words search with home facts, and new-home texts

- **Home facts from the MLS.** Listings now keep `pool`, `garage_spaces`, `waterfront` and `new_construction` (RESO
  `PoolPrivateYN`, `GarageSpaces`, `WaterfrontYN`, `NewConstructionYN`; Repliers `swimmingPool`, `numGarageSpaces`,
  `waterfront`). Null when the MLS does not say. Homes already loaded get them on their next update.
- **Plain-words search** (rules, no AI cost) now turns these into real filters instead of listing them back: "with a
  pool", "2 car garage", "waterfront", "new construction", "5 acres", "condo", "townhome", in English and Spanish.
  "Lake Charles" stays a place. A pool also matches homes whose photos show one. Only "view", "yard" and "basement" are
  still listed back as not filtered. The same filters work in links (`pool=1`, `garage=2`, `waterfront=1`,
  `new_build=1`, `acres=5`, `sub=condo|townhome`), in saved searches and in AIlly's home suggestions.
- **New-home texts** (opt-in only). After saving a search on the website, the visitor can ask for texts: their mobile
  number and the exact consent words (shown next to an unticked box) are recorded as written consent (web form). Texts
  go only with that consent on file, only between 8am and 9pm where the person is, at most 3 a day and one an hour per
  search, only for new homes (price drops stay in the email), never the same home twice, and STOP ends them. The agent
  sees "Texts new homes" on the contact's saved search. The brokerage needs texts switched on by HQ.

For review: the consent wording (`SavedSearches::TEXT_CONSENT`) should be checked by counsel before launch.

## IDX11: checking signed agreements, and watching each board's form

- **Nothing goes to the MLS unchecked.** When everyone has signed, the copy is kept and the board moves to "Signed:
  RightAlly is checking it" (`hq_check`). A queued check (`Idx\AgreementCheck`) runs:
    - **Rules:** the signed copy is a PDF; every signer signed; MLS member ID present and only letters and numbers;
      license number present and plausible; managing broker's full name and email (when the board needs the managing
      broker); the website opens over https without a password; the board has a "send to" address (a RightAlly problem,
      shown as "For RightAlly").
    - **AI** (`idx.agreement_check`, HQ pays, no brokerage credits): reads the same details as data and adds anything an
      MLS would send back, then writes a short note to the brokerage listing every problem. If AI is not set up or fails,
      the rules' result and a plain note stand.
- **HQ decides.** The queue shows "Check: clear to send" or the list, with the note in an editable box. **Send to the
  MLS** emails the board (send to, CC, BCC; password confirmed). **Send back to the brokerage** moves it to "Needs
  fixing" with the note plus anything HQ adds (up to 1,500 characters) and emails the owner. **Check again** reruns it.
- **The board's own form.** HQ can save the web address where the board publishes its IDX agreement. Every Monday
  (`idx:agreement-watch`) RightAlly downloads it: the first download becomes the form; a different file is kept aside
  and HQ is emailed ("Use the new version" or "Keep current"). Each upload or switch is a new version; agreements
  record the version they were signed on, and those already signed are never changed. A form that cannot be
  downloaded (a member sign-in page) gets "Confirm this form is current" every 90 days.
- Filling in the board's own PDF fields (not only our signature page) is planned as IDX12 and needs a PDF form library;
  it will be proposed separately.

## IDX12: the board's own agreement, filled in

- **Fields found automatically.** When HQ uploads a board's agreement (or the form watch adopts a new version), its
  fillable fields are read with qpdf (the same reader every imported form uses, already in the server image) and
  placed exactly where the board put them. Each field is matched by its name to what RightAlly knows: firm name,
  website, MLS member ID, office ID, license number, managing broker name and email, owner name and email, technology
  provider, today's date. Anything else is "the brokerage fills it in". Signature, initials and date-signed lines are
  assigned to the brokerage owner or the managing broker.
- **HQ checks once per form version** in HQ › IDX coverage › MLS boards (rename a field, change what fills it, who
  signs it, whether it is required; "Find fields again"). Until HQ saves the check, brokerages keep signing on
  RightAlly's summary page. A new form version needs a new check.
- **The brokerage reviews before signing.** After entering its MLS details, the board shows "Check the form": the
  fields RightAlly filled in (editable) and the ones to complete. **Save** then **Open the preview (PDF)** shows the
  board's form exactly as the MLS will see it. **Looks right: send for signature** (password confirmed) prepares the
  packet: the board's own pages with the values, the board's signature lines for the right people, and RightAlly's
  summary page only when someone has no signature line on the board's form.
- Changing MLS details always starts a fresh signature (an earlier signed copy no longer matches). The check before
  sending (IDX11) also flags any required field left empty.
- Forms that are scans (no fillable fields) keep working as before, with RightAlly's summary and signature page.
