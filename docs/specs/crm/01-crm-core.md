# CRM 01 — CRM core (Team D, Sprints 4–7)

## Purpose

The single place where agents and brokerages keep every person they work with: leads, buyers,
sellers, past clients, sphere and recruits. Everything else in the Grow suite (instant AI lead
response, unified inbox, lead intelligence, enrichment and scoring, lead distribution, the daily
game plan) builds on these records. The CRM must be fast, permission-scoped, compliant with
contact-consent rules, and private by design.

Module: `modules/Crm`, namespace `Modules\Crm`. Tenant-owned.

## Sprint split

| Sprint | Delivers                                                                                            |
| ------ | --------------------------------------------------------------------------------------------------- |
| 4      | Contacts and households, ownership and visibility, contact list and detail, quick add               |
| 5      | Timeline (notes, calls, emails, texts, meetings), tasks and reminders                               |
| 6      | Pipelines and stages (board view), tags, smart lists (saved filters), bulk actions                  |
| 7      | CSV import with mapping, duplicate detection and merge, consent and Do Not Call fields, AI indexing |

## Delivery

| Sprint | Delivered                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 4D     | Module `modules/Crm`; contacts (person or company, emails and phones with a primary, normalised email/phone/name for search and duplicates, address, birthday, origin, source, lifecycle, owner; consent columns ready for Sprint 7), households and relationships; **`ContactVisibility`, the one rule** (agents: own; assistants: their agent's; owners and staff: company contacts plus their own; personal sphere private unless the brokerage opens it to owners; HQ: none); quick add (name plus email or phone), duplicate detection that never reveals hidden contacts, reassignment of company contacts by the brokerage, contacts list (search by name, email or phone digits; origin and stage filters) and detail (tap to call, text and email; do-not-call and texting-consent badges; details; owner; households and relationships); invisible contacts are "not found" |

## Additions from the best-in-class review (approved)

Reviewed: Follow Up Boss (follow-up discipline: Smart Lists, Action Plans, 250+ integrations; weak
mobile app, AI only on higher plans), Lofty (AI assistant, Homeowner Agent), Fello (database seller
intelligence), BoomTown, kvCORE/BoldTrail, Sierra Interactive and Real Geeks.

| #   | Addition                    | Team                       | How it works                                                                                                                                                       |
| --- | --------------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 1   | **Follow-up plans**         | D                          | Automatic texts, emails and tasks triggered by source, stage or behaviour, with a library of proven templates; edited in plain English; consent rules always apply |
| 2   | **Work my list**            | D                          | Open any smart list and move through it one contact at a time: tap to call or text, log the outcome, next                                                          |
| 3   | **Ready-made daily lists**  | D                          | New leads, no contact in 7 days, hot website activity, birthdays and home anniversaries, past clients due a check-in                                               |
| 4   | **Connect any lead source** | A                          | Portal lead emails (Zillow, Realtor.com, Homes.com) read automatically, webhooks, Zapier and a public CRM API with the same visibility rules                       |
| 5   | **Homeowner intelligence**  | C (dashboard), D (signals) | Past clients enriched with value, equity and time in home from licensed data; seller-intent alerts; a branded homeowner dashboard and monthly home-value email     |
| 6   | **AI contact brief**        | D                          | On every record for every user: who they are, what they want, last conversation, suggested next step and a drafted message                                         |
| 7   | **Relationship rhythm**     | D                          | A/B/C sphere ranking with keep-in-touch cadences, birthdays and closing anniversaries                                                                              |
| 8   | **Property matches**        | D                          | Each contact's saved searches and new matching listings, with "send to client"                                                                                     |
| 9   | **Full CRM on the phone**   | D                          | Every CRM feature works in the mobile web app (usability-tested on phones)                                                                                         |

Sprint split becomes Team D Sprints 4–9 (core 4–7, additions 8–9), with Team A (lead sources and
API) and Team C (homeowner dashboard) in parallel.

## Database intelligence (approved additions 10–16)

Goal: turn the dormant 50–80% of a brokerage's database into today's conversations. Reviewed:
Fello (enrichment, AI lead scoring, Ask AI), Ylopo AI² (AI text and voice reactivation), Revaluate,
SmartZip, Likely.AI, Luxury Presence AI CRM.

| #   | Addition                                                 | How it works                                                                                                                                                                                                                                                 |
| --- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 10  | **Nightly AI database research and opportunity scoring** | Every night (half-price batch) each contact is reviewed: likely buyer / likely seller / timeframe, **always with the reasons**; scores are signals, not certainties (independent audits of mover scoring show roughly 20–36% accuracy), so the agent decides |
| 11  | **Today's 10**                                           | A daily ranked list of who to call and why (hot behaviour, dated intents, listing changes, overdue follow-ups), each with brief and draft, worked with Work my list; on the phone home screen; askable by voice                                              |
| 12  | **Intent extraction from notes**                         | Old and new notes are read for intents with dates ("relocating for hospital job in March", "lease ends June", "retiring next year") which become reminders and score inputs                                                                                  |
| 13  | **Database health**                                      | Email and phone validation, licensed fill-in of missing details, "moved" flags from licensed change-of-address data; a health score per database                                                                                                             |
| 14  | **Reactivate my database**                               | One click: AI groups dormant contacts and drafts personal emails per group (agent approves); replies and clicks raise scores; email first (CAN-SPAM with unsubscribe), texts and AI calls only with written consent                                          |
| 15  | **Opportunity alerts**                                   | "Your past client's home just listed with another agent", "listing expired", "Sam is looking at homes again", "checked home value twice"                                                                                                                     |
| 16  | **Pipeline forecast**                                    | Expected commission by month from pipeline stages, for agents and brokers                                                                                                                                                                                    |

Signal sources: the contact's own notes and timeline; licensed property, MLS, change-of-address and
public-records data (Homeowner intelligence); our websites, IDX and email engagement as those ship.
Guardrails: consent rules (`canAutoText`), Do Not Call scrubbing, sensitive life events (probate,
divorce) worded with care and never mentioned to the client, no protected characteristics (Fair
Housing), licensed-data costs passed through as credits. Delivery: Team D (research, intents,
scoring, alerts), Team E (Today's 10, reactivation), Team B (health, forecast).

## Ownership and privacy (decision recorded in AI data protection)

| Field                  | Meaning                                                                                                           |
| ---------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `owner_user_id`        | The agent responsible for the contact                                                                             |
| `origin`               | `personal` (the agent's own sphere) or `company` (provided by the brokerage: website, portals, ads, distribution) |
| `team_id`, `office_id` | For team and office scopes                                                                                        |

- **Company contacts** are visible to the brokerage according to role scopes (specs/core/03).
- **Personal-sphere contacts** are private to the agent (and their assistants) by default. Each
  brokerage can change this in settings; agents are shown which policy applies to them.
- Data portability: when an agent leaves, personal-sphere contacts can be exported by the agent;
  company contacts stay with the brokerage (solo plan and Ask my broker rules).
- Every list, search, export, AI retrieval and API response applies the same visibility rules
  through one query scope (`ContactVisibility`), never ad hoc.

## Data model (cell)

### `crm_contacts`

`id, ulid, tenant_id, type (person, company), first_name, last_name, company_name, emails (json:
[{address, label, primary}]), phones (json: [{e164, label, primary, can_text}]), address fields,
birthday nullable, origin (personal, company), source (website, zillow, realtor_com, facebook,
google, referral, open_house, sphere, import, manual, other), source_detail, owner_user_id,
team_id, office_id, stage_id nullable, pipeline_id nullable, last_activity_at, next_task_at,
email_opt_in, email_opt_in_at, sms_opt_in, sms_consent_text, sms_opt_in_at, sms_opt_out_at,
do_not_call, do_not_call_checked_at, lifecycle (lead, active, under_contract, past_client, sphere,
archived), created_by, timestamps, deleted_at`.

Indexes start with `tenant_id`; searchable name, email and phone columns are indexed (normalised
lower-case emails, E.164 phones).

### `crm_households`

`id, ulid, tenant_id, name` and `crm_household_members (household_id, contact_id, role)` so partners
buying together are one household with two contacts.

### `crm_relationships`

`contact_id, related_contact_id, relation (spouse, referrer, attorney, lender, other)`.

### `crm_activities` (timeline)

`id, ulid, tenant_id, contact_id, type (note, call, email, text, meeting, showing, system),
direction (inbound, outbound, null), subject, body (text), occurred_at, duration_seconds,
user_id, related_type, related_id, meta (json), visibility (inherits contact), timestamps`.
Integrations (inbox, dialer, showings) write here through `ActivityRecorder`.

### `crm_tasks`

`id, ulid, tenant_id, contact_id nullable, title, notes, due_at, remind_at, assignee_user_id,
priority (low, normal, high), status (open, done, cancelled), completed_at, created_by, timestamps`.

### `crm_pipelines` and `crm_stages`

Pipelines per tenant (defaults seeded: Buyers, Sellers, Recruits); ordered stages with
`probability_percent` and `is_closed_won` / `is_closed_lost`. Stage changes are activities.

### `crm_tags` and `crm_contact_tag`

### `crm_smart_lists`

`id, ulid, tenant_id, owner_user_id, name, filters (json, validated), shared (bool), sort`.
Filters: source, origin, stage, lifecycle, tags, owner, last activity before/after, has open task,
created between, consent flags, location. Smart lists always run through `ContactVisibility`.

## Behaviour

- **Quick add:** name plus one of email or phone; duplicates checked as you type.
- **Duplicate detection:** exact normalised email or phone match in the tenant (within the user's
  visibility plus a "possible duplicate exists" hint without revealing hidden records).
- **Merge:** pick the surviving contact; timeline, tasks, tags, relationships and pipeline history
  move to it; the merged contact is soft-deleted with a pointer; merges are audited and reversible
  for 30 days.
- **Import (CSV):** upload → map columns (saved mappings) → preview with validation errors →
  dedupe strategy (skip, update, create) → background import with progress and an error file.
  Imported contacts default to `origin = personal` for agents and `company` for brokerage imports.
- **Consent:** SMS sending is blocked unless `sms_opt_in` is set with consent text and time (TCPA
  and A2P 10DLC); email marketing respects `email_opt_in` and unsubscribes (CAN-SPAM); Do Not Call
  blocks calls from the dialer unless an exemption is recorded. Consent changes are activities.
- **Bulk actions:** assign owner, add tags, move stage, add to smart list, export (permission
  `crm.contacts.export`, audited, visibility-limited).
- **AI indexing:** contact notes and activities are indexed for retrieval with the contact's
  access metadata (owner, team, office, sensitive for consent-restricted fields) through
  `VectorIndexer`; personal data is redacted before embedding (specs/ai/01).

## Screens

- **Contacts list:** data table with search, filters, smart lists, bulk actions; phone card layout.
- **Contact detail:** header (name, stage, owner, contact methods with consent badges), timeline
  with composer (note, log call), tasks, households and relationships, tags; later tabs for deals,
  showings and lead intelligence.
- **Pipeline board:** kanban by stage with drag and drop (keyboard accessible alternative).
- **Tasks:** today, overdue, upcoming; complete inline.
- **Import wizard** and **merge** dialog.

## Permissions

`crm.contacts.view|create|edit|delete|export` (scoped own/team/office/tenant),
`crm.contacts.view_personal` (brokerage policy), `crm.pipelines.manage`, `crm.import`,
`crm.smart_lists.share`.

## Events

`ContactCreated`, `ContactUpdated`, `ContactMerged`, `ContactOwnerChanged`, `ContactStageChanged`,
`ActivityRecorded`, `TaskDue`, `ConsentChanged`. Lead distribution, the daily game plan, lead
scoring and the audit log listen to these.

## Performance

Contact list p95 under 300 ms for a brokerage with 500,000 contacts (indexed filters, keyset
pagination for exports); timeline loads the latest 50 activities with infinite scroll.

## Acceptance criteria

1. Every list, search, smart list, export and AI retrieval returns only contacts the user may see;
   personal-sphere privacy follows the brokerage setting (cross-agent and cross-tenant tests).
2. Duplicate detection catches exact email and phone matches without revealing hidden contacts.
3. Merge moves every related record and can be undone within 30 days.
4. Import handles 50,000 rows in the background with progress, validation and an error file.
5. SMS cannot be sent without recorded opt-in; Do Not Call blocks dialing; consent changes are on
   the timeline and in the audit log.
6. Notes are searchable by the AI only within the same visibility rules.
7. Performance targets are met with seeded large tenants.
