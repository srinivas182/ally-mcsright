# Connect 21 — Property data, and the partner-only apps (C8b)

Sprint **C8b** finishes Connect C8. Property data is built now. Showing services, lender and title partners, and
homeowner platforms only share data with approved partners, so they stay **partner cards** until access is issued.
Each card explains how to apply.

## Property data (built)

Grow → **Property lookup** (`/property-lookup`), for one address at a time, on RightAlly's accounts:

| App             | What                                                                                                                                                     | Credits (rate card)  |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------- |
| `property_data` | ATTOM `expandedprofile` and `attomavm/detail` (header `apikey`): owners, owner-occupied, beds, baths, size, year, last sale, assessed value, value range | `data.property` (40) |
| `batchdata`     | BatchData `property/lookup/all-attributes` (Bearer token): owners' names and mailing address                                                             | `data.owner` (15)    |

- **Paying.** A lookup charges credits up front. If the service has no record or fails, the credits are refunded.
- **Reuse.** Answers are kept per brokerage for 30 days in `crm_property_lookups`, keyed by the normalised address.
  Looking again in that time is free.
- **No phones or emails, ever.** Phones and emails are never taken, even when the vendor returns them. People who never
  asked to hear from an agent can't be texted (TCPA), so these are for letters and postcards.
- **Private.** Results are for the brokerage's own use and are not published on websites, as the data licences
  require. The page says so.
- **Before go-live.** The ATTOM and BatchData response fields used are checked against each vendor's sandbox. HQ
  enters the real per-call costs (`data.property`, `data.owner`) so credits keep a margin.

**PropStream** (`propstream`, link card): there is no public API. Agents export a list as CSV and import it with CSV
import, which now recognises PropStream's columns:

- Owner 1 First and Last Name map to the first and last name.
- Mailing Address, City, State and Zip map to the contact's address.

Rows need an email or phone, and consent is never imported.

## Partner cards (not built until access is issued)

| App                                      | Why it waits                                                                                     |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------ |
| ShowingTime, BrokerBay, Aligned Showings | Showings are shared only with approved partners and MLSs                                         |
| Spacio                                   | No direct partnership yet. Its sign-in emails can be forwarded to the RightAlly lead address now |
| Homebot, Total Expert, Qualia, Updater   | Partner programmes                                                                               |
| Lender partners, Title and escrow        | Need client consent and RESPA review (no payment for referrals); designed with counsel first     |
| Zillow reviews, Testimonial Tree         | Partner access (specs/connect/20)                                                                |

## Tests

`tests/Feature/Crm/PropertyDataTest.php`
