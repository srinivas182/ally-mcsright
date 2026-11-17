# Bring your old files (SU1)

Status: Done. Tracker: A21 Partly (SU2 guided setup and SU3 ready-made library follow).

## What it does, in plain words

**Settings → Bring your old files** (owners).

1. **Drop in the files.** Use the spreadsheets your old systems let you download: up to 10 at once, CSV or Excel (.xlsx), under 10 MB each. You can say what's in them, or let RightAlly work it out.
2. **RightAlly recognises each file.**
    - It works out whether the file holds **people**, **agents** or **deals**, and often which system it came from: Follow Up Boss people, kvCORE/BoldTrail contacts, Lofty leads, Brokermint agents and transactions, Lone Wolf agents, SkySlope transactions or dotloop loops.
    - It then matches each column to RightAlly's fields using the words other systems use ("Escrow Closing Date" is the closing date, "Representing: Seller" is a listing).
    - When a column needed to bring the file in still isn't matched, AI suggests a match from the **column names and the kind of value in each column** (an email, a date, a number, a ZIP code). **It never sees the values themselves.** Columns matched by AI are flagged so the owner checks them.
3. **What will happen, before anything is saved**:
    - "**41** deals will be added under their agent. **2** are already here, so they are left as they are. **3** rows need a look and will be skipped."
    - A few examples (addresses and names only, never contact details).
    - The rows that need a look, each with the reason (for example "No two-letter state (for example TX)" or "The status 'Thinking about it' isn't one we know").
    - The owner can change what the file holds or which column is which, then check again.
4. **Bring it in** with one button:
    - **People** go through the CRM import, with the columns already matched. They are tagged "from Follow Up Boss people" (or wherever they came from), and duplicates are skipped as usual.
    - **Agents** each get an invitation email. Anyone already here or already invited is left alone.
    - **Deals** are filed under their agent, matched by email or name; deals with no match go to the owner. Open deals get the brokerage's checklist, closed deals are locked as history, and each deal shows which system it came from. Statuses and sides are translated (Pending is under contract, Sold is closed, Withdrawn is fell through, Seller is a listing).
5. **Undo for 24 hours** for agents and deals: deals are removed and invitations not yet accepted are taken back. People are managed through the CRM import as before.

Bringing in the same file twice adds nothing: deals already here are matched by address, state and closing date.

## Market leaders

| Leader                            | What they do                                      | Where they fall short                                                       | What we do                                           |
| --------------------------------- | ------------------------------------------------- | --------------------------------------------------------------------------- | ---------------------------------------------------- |
| Lofty, kvCORE / BoldTrail         | Onboarding specialists move contacts and pipeline | Sales-led; often a setup fee or outside freelancers; days of back and forth | Self-serve in minutes, with a preview in plain words |
| Follow Up Boss                    | Free contact import with column matching          | People only; agents and deals are separate projects                         | People, agents and deals from the same screen        |
| Brokermint / Lone Wolf / SkySlope | Back-office data migration by their team          | Weeks of setup calls; nothing to check before it happens                    | Every row checked first; undo for 24 hours           |

## For agents and brokers aged 50+

- One page, three steps, big buttons.
- "Look at my files", "Bring in my deals", "Undo".
- Every problem is a sentence.

## Security and privacy

- Owners only.
- Files are kept in the brokerage's own storage.
- The AI sees column names and value kinds only, never values. It is tested by an AI test set (`setup.map_columns`) that includes a column name trying to give it instructions.
- Uploads, imports and undos are audited.
- Invitations follow the normal invitation rules.

## Settings and review

- **Review:** the recognised export layouts are based on each system's standard export. Systems change their exports, and any file still works through column matching.
