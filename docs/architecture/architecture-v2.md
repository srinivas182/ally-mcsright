# RightAlly Platform Architecture — v2

Status: **Approved baseline for build** · Supersedes the v1.0 PDF (kept in this folder for history)

RightAlly is one Laravel codebase made of independent modules, deployed as identical **cells**
behind a global **control plane**. Every brokerage is a tenant inside a cell. Growth is handled by
adding cells, not by rewriting software. This document is the technical reference; the product
scope is in `docs/product/`.

## 1. System shape

| Layer                        | Responsibility                                                                                                                                                                                             |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Edge (Cloudflare)            | TLS for every brokerage domain (CNAME + automatic SSL), CDN and full-page cache, WAF, rate limits                                                                                                          |
| Control plane (rightally.io) | Brokerage registry, domains, packages and credits, billing, cell placement, HQ console, release orchestration                                                                                              |
| Cells                        | App (Octane) and worker pods, MySQL 8.4 primary + replicas, Redis, OpenSearch, S3 prefix per tenant. Each cell serves ~2,000 brokerages or ~50,000 agents; enterprise brokerages can have a dedicated cell |
| Shared services              | MLS ingestion and listing search (one feed per MLS), AI gateway, analytics, messaging                                                                                                                      |

Routing data (domain → tenant → cell) is cached at the edge and in Redis, so the control plane is
not on the request path.

## 2. Modules

Every capability is a module under `modules/<Name>/` with a `module.json` manifest
(id, version, dependencies, entitlement key), its own migrations, routes, React pages and tests.

Rules (enforced by `tests/Architecture`):

1. A module owns its tables; no other module queries them.
2. Modules communicate through public interfaces and domain events.
3. Each module registers its permissions, settings, menus and widgets with Core, so a new module
   appears in every brokerage's role editor the day it ships, with no access until granted.
4. A module is switched on per brokerage by entitlement (package or add-on) and per agent by seat.

The 38 product modules are listed in `docs/product/module-catalogue.md`.

## 3. Multi-tenancy and isolation

- Tenant types: **brokerage**, **solo agent workspace**, and **RightAlly HQ**.
- Every tenant-owned table has `tenant_id`; a `BelongsToTenant` trait applies a global scope and
  fills `tenant_id` on write. Composite indexes start with `tenant_id`.
- Tenant is resolved from the domain or API token before any code runs; no tenant, no request.
- Cache keys, queue payloads, S3 prefixes and search indexes all carry the tenant.
- Sensitive fields (SSN, bank details, tax IDs) use envelope encryption with a key per tenant.
- A cross-tenant test suite runs on every build and fails it if any read crosses tenants.

## 4. Identity, roles and audit

- Login on the brokerage's own domain (Fortify): email/password, Google/Microsoft SSO, optional
  2FA (off by default, can be required per brokerage or role), passkeys.
- One permission engine for HQ and every brokerage: role × module × action
  (view, create, edit, delete, approve, export) × scope (own, team, office, brokerage).
- Sensitive data masked unless permitted; approval limits; temporary access with expiry.
- Audit log: append-only, hash-chained, 7-year default retention; records user, role, time, IP,
  device, record and before/after values, including views of sensitive data and the source records
  behind every AI answer.

## 5. AI platform

- **Gateway:** one provider-neutral gateway. Claude is primary (Haiku for high-volume
  classification and extraction, Sonnet as the default workhorse, Opus for the hardest reasoning);
  OpenAI GPT, Google Gemini and self-hosted open-source models are alternatives and fallbacks;
  specialist services for embeddings, speech-to-text and voice-over.
- **Model registry (HQ):** providers and models with real costs, model and fallback chain per
  feature, evaluation against quality checks before switching, cost and quality per model.
- **Cost controls:** prompt caching, batch processing for overnight work, per-tenant caps.
- **AI data protection:**
    1. Retrieval is filtered by tenant, user and permission **before** the model sees anything.
    2. Every request is built fresh; nothing carries over between users.
    3. No training or fine-tuning on customer data; providers with zero data retention only.
    4. Personal data stripped before calls where possible.
    5. Documents, emails and forms are data, never instructions (prompt-injection defence);
       actions limited to the user's permissions and confirmed before sending or deleting.
    6. Cross-tenant learning uses anonymised, aggregated patterns only.
    7. Automated leak tests on every release block the release if any succeed.
    8. Per-tenant controls: AI on/off per module, retention, export and deletion.
- Agent personal-sphere contacts are private by default; brokerage business data (deals,
  documents, compliance, company leads) is visible to the brokerage. Configurable per brokerage.

## 6. RightAlly Credits

- One currency, priced **per action** (e.g. listing description, enrichment lookup, SMS, call
  minute, video tour), from an HQ rate card.
- Everyday AI included in seats with fair-use limits; heavy or variable usage consumes credits.
- Packages include monthly credits (tenant pool and/or per seat); HQ sees provider cost and margin
  when building packages.
- Credit packs, auto-recharge, low-balance alerts; included credits reset monthly, purchased
  credits roll over 12 months.
- Brokerages give each agent plan a monthly allowance and caps; assistants draw from their agent.
- Every credit movement is a ledger entry and audit event.

## 7. Data standards

| Standard      | Rule                                                                                    |
| ------------- | --------------------------------------------------------------------------------------- |
| Keys          | bigint internally; ULID public IDs in URLs and APIs                                     |
| Integrity     | Foreign keys everywhere; restrict deletes on financial records                          |
| Money         | `decimal(15,2)` with currency, or integer cents in the ledger; never float or text      |
| Time          | UTC storage; tenant time zone in presentation                                           |
| Audit columns | `created_at`, `updated_at`, `created_by`, `updated_by`; soft deletes where users delete |
| Concurrency   | Optimistic locking on transactions, plans and documents                                 |
| History       | Versioned tables for comp plans, workflows, agreements, deductions, MLS rules           |
| Charset       | utf8mb4, InnoDB only                                                                    |
| Migrations    | Expand → migrate → contract; online schema change for large tables                      |

Money movements are immutable double-entry journal entries with idempotency keys; corrections are
reversals.

## 8. Performance targets

| Measure            | Target                  |
| ------------------ | ----------------------- |
| Cached public page | < 100 ms TTFB worldwide |
| Logged-in API p95  | < 200 ms                |
| Listing search p95 | < 300 ms                |
| Mobile LCP on 4G   | < 2.5 s                 |
| Availability       | 99.95% monthly          |

## 9. Release management

Code is deployed centrally; brokerages never install updates. Pipeline: PR with green CI →
signed image → staging → canary cell (RightAlly + pilot brokerages) → waves of cells → all cells,
with automatic rollback on health gates (error rate, latency, queue lag). Feature flags separate
deploy from release (per brokerage, package or percentage). API v1 never breaks; breaking changes
go to v2 with a 12-month deprecation window.

## 10. Reliability and security

- Multi-AZ cells; PITR 35 days; daily cross-region snapshots; RPO < 5 min, RTO < 1 h per cell.
- Monthly automated restore tests; per-brokerage point-in-time restore.
- OWASP ASVS level 2; MFA required for HQ admins; secrets in AWS Secrets Manager; SOC 2 Type II
  readiness from day one.
- Real estate and marketing compliance: MLS IDX rules, fair housing, state licence disclosure,
  ESIGN/UETA, CAN-SPAM, TCPA, A2P 10DLC, Meta special ad categories, US state privacy laws.

## 11. Stack

Laravel 13 (PHP 8.5), Octane · React 19, TypeScript, Inertia 3, Tailwind 4 · MySQL 8.4 LTS
(Aurora) · Redis, Horizon, Reverb · OpenSearch · S3 + Cloudflare · Stripe Billing + Connect ·
Twilio, SES · Pest, PHPStan level 8, Pint · Docker, Terraform, GitHub Actions · Flutter (after 1.0).
