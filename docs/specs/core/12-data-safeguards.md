# Core 12: Data safeguards (DS1 and DS2)

Status: DS1 built (tracker A14). DS2 adds dedicated databases and per-brokerage keys (A15, A16).

## What the market leaders do

- **SaaS CRMs and transaction tools.** **Follow Up Boss**, **BoldTrail**, **Lone Wolf** and **SkySlope** keep every
  brokerage in shared databases.
    - Exports are partial: contacts as CSV, documents one file at a time.
    - A full export usually means a support ticket.
    - Restoring one brokerage to yesterday is not offered. Backups are platform-wide, so support can only say "we
      can't roll back just you".
- **Enterprise offerings** (**Salesforce**, **Dotloop for enterprise**) offer data residency and dedicated
  instances at a large price premium.
- **Where they fall short:**
    - The brokerage cannot take _all_ of its data with it.
    - One brokerage's mistake (a bulk delete) cannot be undone without affecting others.
    - Moving to dedicated storage is a migration project, not a button.

## What RightAlly does (DS1)

### Per-request cell routing

Whenever the current brokerage changes, the logical `cell` connection is pointed at that brokerage's cell
(`CellRouting`). This covers:

- a web request;
- a queued job, through the job's tenant middleware using push and pop;
- a command running "as" a brokerage.

**Fallback.** A cell whose connection is not defined falls back to the default cell connection, so a brokerage is
never routed to nowhere.

**Audit.** The audit connection follows the cell unless it is configured separately.

**Extension.** DS2 registers dedicated databases through `CellRouting::resolveWith()`.

### Per-brokerage export

One **AES-256 protected ZIP** holding:

- every cell table with a `tenant_id`, with that brokerage's rows only, as JSON lines;
- its files: everything under its own folders (`forms/{id}/…`, `studio/{ulid}/…`) plus any file a row points to;
- `manifest.json`, with the tables, row counts, file count and format version.

There are three kinds:

| Kind         | Who                                                                 | What                                                                        |
| ------------ | ------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| **portable** | Owner, at **Settings › Your data** (password confirmed, once a day) | Everything except passwords, sign-in secrets, API keys and voice references |
| **backup**   | HQ, at **HQ › Tenants › a brokerage › Data and backups**            | Complete; restorable                                                        |
| **safety**   | Taken automatically before every restore                            | Complete; restorable, so a restore can itself be undone                     |

**Password.** The ZIP password is stored encrypted. It is shown only on request, and only to the owner (portable)
or HQ (backup).

**Keeping.** Downloads and password views are audited. Exports are deleted after 7 days
(`safeguards:expire-exports`, daily).

### Per-brokerage restore (HQ only, two people)

1. One HQ person asks. They choose a backup, give a reason, and type the brokerage's short name to confirm.
2. **A different HQ person approves.** The asker cannot approve their own request.
3. A **safety export** is taken first. If it fails, nothing is changed.
4. In **one database transaction**, every exported table is put back to the backup's rows for that brokerage only:
    - rows that still exist are updated in place by id, so nothing that points to them is lost;
    - rows made after the backup are removed;
    - missing rows come back.

    Links between tables are checked at the end. A column added since the backup keeps its default. A row in the
    backup that belongs to another brokerage stops the restore.

5. **Row counts are checked** against the manifest. Any difference rolls everything back.
6. Files are written back.

**Audit trail.** The audit tables are never rewritten. Every step of the restore is itself audited.

### Limits

- **Same key needed.** Encrypted fields stay encrypted in a backup (they are not readable in a portable export
  either). A backup can only be restored with the same application key; DS2 adds per-brokerage keys.
- **Size.** Very large brokerages (millions of rows) export in the background; the job has a one-hour limit.
