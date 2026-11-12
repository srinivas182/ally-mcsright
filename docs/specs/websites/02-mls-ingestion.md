# Websites 02 — MLS ingestion and display rules (Team C, Sprints 4–6)

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
