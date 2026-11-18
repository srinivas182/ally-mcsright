# Websites 02 — MLS ingestion and display rules (Team C, Sprints 4–6)

## Delivered in Sprint 17A

- Module `modules/Listings`: `mls_sources`, `mls_rules_profiles`, `listings`, `listing_payloads`, `mls_sync_runs` on the
  `listings` connection (`CORE_LISTINGS_CONNECTION`, falls back to control); `mls_authorizations` on the control
  connection (licences are platform records HQ decides).
- RESO Web API client for MLS Grid (OriginatingSystemName filter, `$expand=Media`), Trestle and direct servers
  (OAuth client credentials, token cached) and Bridge (bearer token). Next-page links are followed only on the
  feed's own host, so credentials never leave it.
- Full replication (resumable from the committed page), incremental with a 2-minute overlap (already-held versions
  skipped), cursor moved only with its page, per-MLS hourly request limit, 429 back-off, failures back off
  exponentially and set the feed to `error` after five.
- Daily removal check (refuses to act on an empty answer), licence expiry, and a stale-feed email to HQ when a
  feed is 30+ minutes behind (at most hourly).
- `ListingPresenter::forDisplay` applies internet-display, sold-data delay, suppressed fields, address flags,
  attribution, disclaimer with last-update time, freshness and indexability.
- Licences: brokerage requests (Settings → MLS), HQ approves with scopes and expiry (HQ → MLS connections);
  `Listing::visibleTo($tenantId, $scope)` checks the licence on every query (one-minute cache).
- **Decision:** feed credentials are stored encrypted in `mls_sources.credentials` (write-only, password confirm,
  audited) like the Twilio account, instead of a secrets-manager reference; moving to the secrets manager later is
  a storage change only.
- Media (CDN variants), search indexing and the Member/Office/OpenHouse resources follow in 17B.

## Delivered in Sprint 17B-1

- Photos: the record's Media list (MLS Grid `$expand=Media`) is mirrored per listing; new or changed photos are
  downloaded (public https only, images only, 25 MB cap), saved as WebP at 400, 800 and 1600 px on the media disk
  behind the CDN (`LISTINGS_MEDIA_DISK`, `LISTINGS_CDN_URL`), retried with back-off for 24 hours, then marked failed;
  removed photos are deleted. The first stored photo is the cover.
- Price history (`listing_price_changes`, `listings.price_changed_at`) for price-drop alerts and badges.
- `ListingSearch`: licensed MLSs only, every result through `ListingPresenter`; filters for place (city, ZIP,
  address, MLS number), price, beds, baths, size, type, status, map bounds, listing agent and "changed since";
  sorts newest, price, size, price reduced; at most 48 per page. Back office → Listings (search and detail).
- Feeds that do not expand Media (Trestle, Bridge) will fetch the Media resource in a later sprint.

## Purpose

Bring each MLS's listings into RightAlly once, keep them fresh within minutes, store photos on our
CDN, and make every listing display obey that MLS's rules automatically. Brokerages authorise
access; RightAlly holds the feed credentials and never shows them to tenants.

Module: `modules/Listings`. Listing data is **shared per MLS** (not tenant-owned) and lives on the
`listings` connection (shared service, see architecture v2 section 1). Tenant authorisation records
are tenant-owned.

## MLS sources (HQ)

### `mls_sources` (listings connection)

| Column                                                      | Notes                                                          |
| ----------------------------------------------------------- | -------------------------------------------------------------- |
| id, ulid, key                                               | e.g. `ms-mls`                                                  |
| name, states (json)                                         |                                                                |
| provider                                                    | `mls_grid`, `bridge`, `trestle`, `spark`, `direct_reso`        |
| base_url, resource_paths (json)                             | Property, Member, Office, Media, OpenHouse                     |
| credential_secret_ref                                       | Reference to the secrets manager; never stored in the database |
| status                                                      | `setup`, `active`, `paused`, `error`                           |
| sync_interval_minutes                                       | Default 5; never more frequent than the provider allows        |
| rate_limit_per_hour                                         | Provider limit                                                 |
| rules_profile_id                                            | Display rules                                                  |
| timezone                                                    | MLS local time for timestamps                                  |
| last_full_sync_at, last_incremental_cursor, last_success_at |                                                                |

HQ console screen "MLS connections": add/edit source, test connection, enter credentials
(write-only field), start full sync, pause/resume, view freshness and errors.

## Listing storage

### `listings`

Normalised to the RESO Data Dictionary 2.x:
`id, ulid, mls_source_id, listing_key (unique with source), listing_id, standard_status,
mls_status, property_type, property_sub_type, list_price_cents, original_list_price_cents,
close_price_cents, close_date, beds_total, baths_full, baths_half, living_area_sqft,
lot_size_sqft, year_built, street_number, street_name, unit_number, city, state_or_province,
postal_code, county, subdivision, latitude, longitude, public_remarks, list_agent_mls_id,
list_office_mls_id, co_list_agent_mls_id, buyer_agent_mls_id, buyer_office_mls_id,
virtual_tour_url_unbranded, virtual_tour_url_branded, photos_count, listing_contract_date,
on_market_date, days_on_market, modification_timestamp, photos_change_timestamp,
off_market_at, internet_display_allowed, address_display_allowed, avm_allowed,
comments_allowed, created_at, updated_at`.

`listing_payloads (listing_id, payload json, received_at)` keeps the full raw record (latest only)
so fields we did not map are never lost.

`listing_media (id, listing_id, media_key, order, media_url_source, storage_key, width, height,
caption, modification_timestamp, status (pending, stored, failed))` with variants
(thumb 400, card 800, hero 1600; WebP and AVIF) on S3 behind the image CDN.

`listing_open_houses`, `mls_members (member_key, mls_id, name, office_mls_id, status, licence
fields where provided)` and `mls_offices` stored similarly (members and offices feed agent
matching now and recruiting data later, subject to each MLS's licence).

## Sync

1. **Full replication** (new source): pages through the Property resource ordered by
   `ModificationTimestamp`, using the provider's paging (next links or `$skip`); resumable from the
   last page on failure.
2. **Incremental** every `sync_interval_minutes`: records with `ModificationTimestamp` greater
   than the cursor minus a 2-minute overlap; upserts by `listing_key`; cursor advanced only after
   a page is committed.
3. **Removals:** provider delete/purge feeds where available; plus a daily key reconciliation
   (all active keys from the provider vs ours) marking missing records off-market per the rules
   profile.
4. **Media:** queued separately; fetched only when `photos_change_timestamp` changes; deduplicated
   by `media_key`; failures retried with backoff for 24 hours.
5. **Rate limits:** a per-source token bucket under the provider's limit; HTTP 429 → exponential
   backoff; quotas shown on the HQ screen.
6. Each committed change emits `ListingChanged` (outbox) for search indexing (Sprint 7–8),
   alerts, marketing and cache purges.

Per-MLS field mapping overrides live in code (`modules/Listings/mappings/{key}.php`) and are
covered by contract tests using recorded sample payloads.

## Display rules engine

### `mls_rules_profiles`

| Rule                          | Example                                                              |
| ----------------------------- | -------------------------------------------------------------------- |
| Attribution template          | "Listing courtesy of {ListOfficeName}"                               |
| Disclaimer text and placement | Footer of listing pages and search results                           |
| Logo requirement              | MLS logo or IDX icon, size and placement                             |
| Sold data                     | Allowed, delay in days, fields hidden                                |
| Field suppression             | e.g. hide `days_on_market`, seller concessions                       |
| Address/AVM/comments flags    | Respect `address_display_allowed`, `avm_allowed`, `comments_allowed` |
| Freshness                     | Maximum data age before pages show a "data updating" notice          |
| Indexing                      | Whether sold/off-market pages may be indexed by search engines       |
| Program type                  | IDX or VOW                                                           |

`ListingPresenter::forDisplay(Listing $listing, DisplayContext $context)` returns only the fields
and labels the rules allow, plus required attribution and disclaimers. Website templates
(Sprints 9+) must render listings only through this presenter (architecture test).

## Brokerage authorisation (tenant-owned)

### `mls_authorizations` (cell)

`id, ulid, tenant_id, mls_source_id, broker_mls_id, office_mls_ids (json), agreement_document_id,
status (pending, active, revoked), requested_by, approved_by (HQ), activated_at, revoked_at`.

Flow: brokerage selects its MLS, enters broker and office MLS IDs, e-signs the MLS authorisation
(workflow agreement step) → HQ verifies with the MLS → activates. Tenants see status, last sync
time and the rules that apply, never credentials. Listings from an MLS are visible to a tenant
only while its authorisation is active.

Agent matching: an agent's `mls_member_key` (captured in onboarding) links their profile to their
listings.

## Monitoring

Freshness per source (time since last successful incremental), records per hour, media backlog,
error rate. Alert when freshness exceeds 30 minutes or errors exceed 5% for 15 minutes.

## Permissions

HQ: `hq.mls.manage`, `hq.mls.authorize`. Tenant: `listings.mls.view`, `listings.mls.request`.

## Acceptance criteria

1. A full sync of a sample MLS completes and is resumable after interruption.
2. Incremental sync picks up changes within one interval; no records are missed across page
   boundaries (overlap test).
3. Removed listings go off-market per the rules; media variants are on the CDN.
4. Rate limits are never exceeded; 429s back off.
5. Unmapped fields remain available in the payload table.
6. The presenter hides suppressed fields and adds attribution and disclaimers per profile.
7. Tenants see only MLSs they are actively authorised for; credentials are never exposed.
8. Freshness alerts fire when a feed stalls.

## MLS licence approvals and enforcement (ADR 0012)

Storage and display are separate: each MLS feed is stored once (RightAlly as approved vendor), but a
brokerage **sees an MLS's listings only with an approved licence**.

| Step                | Behaviour                                                                                                                                 |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Request             | During setup the broker chooses MLSs and uploads or e-signs the IDX agreement                                                             |
| Approval            | HQ records per MLS: status, agreement reference, approved date, expiry, scope (IDX display, VOW, back-office data, agent production data) |
| Enforcement         | Websites, search, alerts, AI and reports query **only approved MLSs and scopes**, checked on every query (not only at setup)              |
| Revocation / expiry | Listings disappear from that brokerage automatically; broker alerted; HQ dashboard                                                        |
| Display rules       | Each MLS's logo, disclaimer, update frequency and sold-data limits applied automatically                                                  |
| Audit               | Report of which brokerage accessed which MLS data, when, under which approval                                                             |

Agent production data (AI Networking Assistant, Recruiting) requires its own licence scope. If an MLS
vendor contract requires per-customer feeds, the store is keyed per feed. Built by Team C right after
MLS ingestion.

## Approved additions (version 1.0; leader review: iHomefinder, Showcase IDX, Sierra, Luxury Presence)

1. **MLS Grid and Trestle** support alongside direct RESO Web API feeds.
2. **Freshness and display-compliance monitor** per MLS (minutes behind; logo, disclaimer and field rules).
3. **AI photo tagging** (kitchen island, pool, single level) for AIlly and search.
4. **Precomputed distances** to schools, transit, groceries and coffee per listing (faster, cheaper AIlly).
5. **Cross-MLS de-duplication** of the same property listed in overlapping MLSs.

## Fast search and one home per listing (SR1: C05, part of C03)

**Cross-MLS de-duplication (C03 item 5).**

- Each listing has an address key: the street number, the street name with common words shortened (Street → st, North → n …), the unit and the 5-digit ZIP (`Sync\Duplicates`).
- When on-market copies with the same key exist in two or more MLSs, one copy is shown: the one with the most photos, then the latest update. The others point to it (`duplicate_of_id`, `duplicate_of_source_id`).
- A copy is hidden only from brokerages also licensed for the MLS of the shown copy. A brokerage licensed for just one MLS still sees that MLS's copy.
- When the shown copy leaves the market, the next copy takes its place.
- Links are refreshed after every sync (`RefreshListingSearch`). For a full pass, run `php artisan listings:search-rebuild --duplicates`.

**Near a point.**

- Searches take `near=lat,lng,miles` (0.1 to 100 miles). Results can be sorted closest first, and each result shows how many miles away it is.
- On the back-office Listings page, **Near me: 1, 3 or 10 miles** uses the browser's location after the browser asks the agent.
- Only listings whose address may be shown are included.

**Fast search server (C05).** HQ connects it in Outside services → Fast listing search. The options are **Typesense (recommended, on our servers)**, Meilisearch and Amazon OpenSearch.

- **Off:** the database answers, as before.
- **Test:** the database still answers. After the page is sent, the server answers the same first-page question, and the two are compared: how many results match, how many each found, and how fast each was. HQ sees this under HQ → MLS → Fast search.
- **Live:** the server answers with typo tolerance ("Ostin" finds Austin), map box, radius and every filter. If it fails, the database answers.
- The server holds only on-market listings that may be shown on the internet, and returns ids only. Listings are then loaded through the MLS licence and display rules, so nothing those rules hide can appear.
- The index follows every sync. HQ can **Rebuild the index** (queued), or run `php artisan listings:search-rebuild`.
- A drawn area (polygon) is always answered by the database.

Tests: `tests/Feature/Listings/FastSearchTest.php`.

## Photo tagging and nearby places (C03 items 3 and 4)

Both are outside services, set up by HQ in **Outside services** with Off, Test and Live, and checked on **HQ → MLS → Photo tags and nearby places**. That page shows the latest results and has a **Check 10 more now** button. Both run every day at 11:20 UTC, after the night's MLS sync (`php artisan listings:photos-and-places`). Listing data is shared by every brokerage, so HQ pays and brokerages use no credits.

**Photo tagging.** The providers are **Restb.ai (recommended, made for real estate)**, Azure AI Vision and Google Cloud Vision (`Media\PhotoTags`).

- Each stored photo of a for-sale listing (the first 25, in MLS order) gets:
    - a room: kitchen, living room, dining room, bedroom, bathroom, home office, laundry room, garage, front of the home, backyard or view;
    - the home features seen in it, from a fixed list of 14: pool, hot tub, fireplace, kitchen island, stainless appliances, stone countertops, hardwood floors, high ceilings, walk-in closet, deck or patio, fenced yard, water view, solar panels, garage;
    - plain-words alt text, such as "Kitchen with kitchen island and stainless appliances".
- **Only the home is described.** Anything outside the list (people, pets, cars, brands) is dropped, and a "pool table" is not a pool. Answers below 60% confidence are ignored.
- **Alt text:** an MLS caption always wins. Otherwise the tagged description is used on the website and back-office photo galleries. This helps screen-reader users and image search.
- **Features:** a listing's features are gathered once every photo is tagged, and kept one per row (`listing_features`). They are used in four places:
    - "Seen in the photos" on the listing page, with "confirm features with us";
    - the listing's structured data (`amenityFeature`);
    - a **Has…** filter in website and back-office search, which the database answers;
    - AIlly's facts on a listing page.
- **Test mode** tags up to 50 photos a day, which only HQ sees. **Live** tags up to 2,000 a day. Switching to Live reuses photos already tagged in Test, so they are not paid for twice.
- A changed or removed photo is tagged again and the features are gathered again.

**Nearby schools, transit, groceries and coffee.** The providers are **OpenStreetMap through an Overpass server (recommended, free)**, Geoapify Places and Google Places (`Places\NearbyPlaces`).

| Kept per listing         | Within  |
| ------------------------ | ------- |
| 5 nearest schools        | 2 miles |
| Nearest bus stop         | 1 mile  |
| 2 nearest rail stations  | 3 miles |
| 2 nearest grocery stores | 2 miles |
| 2 nearest coffee shops   | 1 mile  |

- Rail stations include train, subway, and light rail or tram stops.
- Places are measured in a straight line and de-duplicated by name (a bus stop on each side of the road is one stop). Schools are named elementary, middle or high when the name or the map data says so.
- Only listings whose address the MLS lets us show are looked up. Each is looked up again every 30 days, which is also Google's limit on keeping results.
- **Fair housing:** schools get a name and a distance only, never a rating or a rank. Pages say: "Straight-line distances. School attendance depends on the school district; check with the district before you decide."
- The listing page has a "What's nearby" section. It starts with an answer-first sentence ("The nearest school is …, 0.3 miles away."), which suits AI answers, then lists each kind with its distance. The provider's credit is shown, as OpenStreetMap and Google require.
- AIlly uses the same list on a listing page, so "how far is the school?" needs no look-up.
- **Test mode** looks up 20 listings a day, which only HQ sees. **Live** looks up up to 1,000 a day.
- Mapbox was not chosen: its terms let search results be used only for a short time, not kept.

Tests: `tests/Feature/Listings/PhotosAndPlacesTest.php`.
