# Core 12: Data safeguards (DS1 and DS2)

Status: DS1 built (tracker A14); DS2 built (A15 dedicated databases and moves, A16 per-brokerage keys and move drills).

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

## What RightAlly does (DS2)

### HQ › Tenants › a brokerage › Data and backups › Database (A15)

A brokerage can move to or from a dedicated database.

1. **Target.**
    - **A new dedicated database:** enter the engine (MySQL 8 or MariaDB), host, port, database, user and password.
      These details are stored encrypted, **never shown again**, and hidden from every API response.
    - **Or an existing shared cell.**
2. **Test.** The target must be reachable, on a supported version, and **empty for this brokerage**. Failed
   checks are listed in plain words.
3. **Plan.** Rows per table, estimated time, and the read-only window.
4. **A second HQ person approves.** The owners are emailed before the move starts.
5. **Run** (a background job, with progress on the page):
    - **Structure:** missing tables and columns are created from the main cell. A dedicated database's new ids start
      at `cell id × 10¹²`, so moving back to a shared cell never clashes with another brokerage's ids.
    - **Copy** while the brokerage keeps working.
    - **Final copy** in a short **read-only window**: pages open, but saving returns "try again in a few minutes"
      (503, Retry-After).
    - **Verify** every table: row counts and every `*_cents` total.
    - **Switch** the brokerage's cell in the control plane.
    - The owners are emailed afterwards.
6. **Switch-back.** The old copy is kept **14 days** with **one-click switch-back**. Switching back copies the
   current data home and switches back. After 14 days, `safeguards:daily` removes the old copy.

**Drill (A16).** The same copy-and-verify into a target, after which the copy is removed and nothing is switched:
regular proof that a move would work.

**Releases.** After a release, `safeguards:daily` brings each dedicated database's structure in step: new tables,
new columns.

**Audit trail.** Audit tables are append-only. They are copied by adding missing rows and are never deleted by a move.

**Control-plane tables** are never part of an export, restore or move. They are listed in `ControlTables`, kept in
step with the migrations by an architecture test.

### Per-brokerage encryption keys (A16)

**What is covered.** Tax ids (W-9 TINs, the payer EIN), agents' bank routing and account numbers, and the
brokerage's operating account.

**How they are sealed.**

- Each brokerage has its own 256-bit data key, wrapped by the platform key (envelope encryption). Sealed values read
  `bk1:{version}:…`.
- Values sealed before this change still open with the platform key. `safeguards:rekey` (or **Make a new key and
  re-seal** on the HQ page) moves them to the brokerage key.
- Rotating makes a new version. Old versions stay readable.
- Another brokerage's key cannot open them.

**Registration.** Modules register the columns they seal (`BrokerageKeys::register`). The HQ page shows how many
values are sealed with the current key and how many are waiting.
