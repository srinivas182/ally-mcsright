# Guided listing appointments, buyer consultations, and "agreement needed" (AP1)

Status: Done. Tracker: B27 Partly (consented recording of the appointment is left).

## What it does, in plain words

**Grow → Appointments.** Choose "Listing appointment" or "Buyer consultation" and the client, then go step by step together on a laptop or tablet. Every step saves as you go.

- **Listing appointment** (six steps):
    1. **The home**: prefilled from the contact's address.
    2. **The price**: the seller's CMAs with their likely range, and the list price you agree.
    3. **What you walk away with**: mortgage payoff, listing commission, any buyer's-agent offer, and the state's typical closing costs give "You walk away with about $…". This is the same net sheet as CM1.
    4. **How we'll market it**: a checklist (photos, staging, MLS, its own web page and QR code, open house, social, showing feedback).
    5. **The listing agreement**: commission % (marked negotiable) and term. Buyer's-agent compensation is optional and the seller's choice, and never put on the MLS.
    6. **Next steps**: **Create the listing** makes the listing deal, with its agreement end date for the Listings board, and opens it. **Email the seller** sends the summary.
- **Buyer consultation** (five steps):
    1. **What you're looking for**.
    2. **Budget and financing**: pre-approval and lender.
    3. **How I'm paid**: the buyer agreement, as a percent of the price or a dollar amount.
        - It's refused if open-ended or zero (2024 NAR settlement: "objectively ascertainable").
        - It also records the term in months, whether it's exclusive, and the area.
    4. **Signing**: record the date and the signed PDF. The agreement end date is worked out from the term.
    5. **Next steps**: the buyer guide email, which explains the agreement in plain words, including that the buyer never pays more than agreed.
- **"Agreement needed" guard.**
    - Scheduling a **showing** task for a buyer (a contact with a buyer consultation or a "buy" lead) is stopped until a signed, unexpired buyer agreement is recorded. The stop explains why in plain words.
    - Sellers and other contacts are never stopped.
    - The guard is a Core contract, so other modules can ask the same question.

## Market leaders

| Leader                    | What they do          | Where they fall short                                                  | What we do                                                                       |
| ------------------------- | --------------------- | ---------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Cloud CMA / Remine        | Listing presentations | A slide deck, not a guided conversation; nothing is created at the end | A guided appointment that ends with the listing deal and the seller's summary    |
| Follow Up Boss / kvCORE   | CRM tasks for tours   | No check that a buyer agreement exists (post-settlement risk)          | A showing can't be booked for a buyer without a signed, unexpired agreement      |
| Dotloop / Lone Wolf forms | Buyer agreement forms | The form alone; agents still write open-ended compensation             | Compensation must be a specific percent or amount, with plain-words explanations |

## Compliance

- Commissions are shown as negotiable and not set by law. Buyer-agent compensation offers are never placed on the MLS.
- Buyer agreements must state a specific amount or rate, and a term (NAR settlement, effective August 17, 2024). This is a guardrail, not legal advice; use your state's approved form.
- Signed copies are private to the agent and their managers. Timeline entries record each step.

## Not in this sprint (honest)

- Consented audio recording and AI notes of the appointment (B27). These need two-party consent handling by state.

## Settings and review

- Default listing commission (3%), buyer-agent compensation (2.5%) and the default terms (6 months for listings, 3 for buyers) come from Pricing defaults and are marked for **review**.
