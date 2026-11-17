# Listings board, per-deal email-in, cash forecast and royalty report (LM1)

Status: Done. Tracker: C26.

## What it does, in plain words

- **Email documents straight to a deal.** Every deal page shows its own address (`deal-…@inbound.rightally.app`) with a Copy button.
    - Agents give it to the lender, title company or the other agent.
    - PDFs and photos sent to it land in the deal's **Unsorted** documents for the agent or broker to file.
    - Anything else in the email is ignored, and the same file sent twice is kept once.
    - It works on closed deals too, because the file is the deal's history.
    - AI does not read the files on arrival (that would cost credits); "read" stays one click.
- **Listings board** (Deals, Listings board) has four columns:
    - **Getting ready**: no MLS number yet.
    - **On the market**.
    - **Under contract**: with the closing date.
    - **Sold**: the last 30 days.
    - Each card shows the price, the agent and the days listed. The agent can add the listing agreement's end date (and the MLS number) right on the card.
    - Agreements ending within 30 days, or already ended, are flagged in red, with a banner: "Talk to the seller about extending".
    - Agents see their own listings; brokers see the whole brokerage, with a "Show only mine" switch.
- **Money coming in** (Deals, Money coming in and royalties; brokers only):
    - Company dollar expected in the next 30, 60 and 90 days.
    - **Week by week** for 13 weeks, listing the deals behind each figure. Deals with overdue key dates are marked "Dates overdue".
    - Download as CSV.
- **Franchise and royalty report.** Pick a month to see every fee your commission plans mark as **payable to the franchisor** (royalty, franchise fee, marketing fund) on deals closed that month.
    - It shows totals by fee and deal by deal, with a CSV to send with the royalty payment.
    - Older calculations fall back to fee codes containing "royalty" or "franchise".

## Market leaders

| Leader                             | What they do                     | Where they fall short                                            | What we do                                                                      |
| ---------------------------------- | -------------------------------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| SkySlope / Dotloop                 | Email-in per transaction         | Files arrive mixed into the checklist; duplicates pile up        | Into "Unsorted" only, deduplicated by fingerprint, never auto-read with paid AI |
| Brokermint / Lone Wolf             | Listing inventory reports        | Table-only reports; no warning before listing agreements expire  | A visual board with agreement-ending warnings                                   |
| BoldTrail BackOffice               | Pending-income forecast          | Monthly buckets only; you can't see which deals carry the number | Week by week, every deal named, overdue dates flagged                           |
| Lone Wolf (franchise) / Real / eXp | Franchise and royalty statements | Built for the franchisor, not the franchisee's own check         | The franchisee's report from its own plans, as a CSV in the franchisor's format |

## Security

- Email-in uses the existing inbound webhook, which needs a basic-auth secret. Each deal's token is 40 random characters, registered per brokerage, and the handler runs as that brokerage only.
- At most 20 attachments per email. Only real PDF, JPEG or PNG files are accepted (checked by their bytes, not their names), up to the existing size cap.
- The money and royalty pages and CSVs are for reviewers who may see commission. CSV cells are protected against spreadsheet formulas, and every royalty export is audited.

## Easy for agents aged 50+

- One address to copy, and one board with four plain columns. Warnings are written in words ("Listing agreement ends in 14 days").

## Settings and review

- The 30-day warning window and the 13-week forecast are defaults to **review**.
