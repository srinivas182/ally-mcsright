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
