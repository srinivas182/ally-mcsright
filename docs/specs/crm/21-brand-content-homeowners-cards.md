# Brand signature, monthly content, homeowners dashboard, handwritten cards (MK2)

Status: Done. Tracker: A30 Done; C22 and C23 Partly.

## What it does, in plain words

All of these are under Grow → Outreach.

- **Email signature from the brand kit.**
    - Name, title, brokerage, phone, email, website, licence line, headshot and logo, in the brand colour.
    - It is added automatically to every email the agent sends from RightAlly (once, never doubled).
    - **Copy signature** puts it on the clipboard ready to paste into Gmail or Outlook, with the steps written underneath.
- **Monthly content.**
    - Pick an area (from the MLS feed's busiest cities and ZIPs) to get this month's real numbers: homes for sale, typical asking price, new listings, price cuts, typical days on market and price range.
    - They come written up as **four social posts** and **a short newsletter**, in **English and Spanish**, each with a Copy button.
    - Every post names its source and date. There is no AI cost and nothing invented.
- **Homeowners.**
    - The monthly home-value email now links to a private **home dashboard** with:
        - today's likely range;
        - a month-by-month chart;
        - an equity calculator (the balance they type stays in their browser, never sent or stored);
        - homes for sale nearby;
        - one button: **Ask for a market analysis**, which gives the agent a high-priority task and adds a note on the timeline.
    - Agents see who gets the report and who opened their dashboard.
    - **Start it for them** enrolls every past client with an address and email who hasn't opted out of email.
- **Handwritten cards.**
    - The agent writes a note of up to 320 characters for a birthday, home anniversary, thank-you or "thinking of you".
    - The page suggests this week's birthdays and home anniversaries.
    - The card is queued with the mailing address as it was. HQ downloads the queue as a CSV in the handwriting partner's upload format and marks the cards mailed, and the agent sees "Mailed".

## Market leaders

| Leader                  | What they do                            | Where they fall short                                               | What we do                                                                       |
| ----------------------- | --------------------------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| Homebot                 | Homeowner dashboards and monthly emails | Separate subscription per agent; the dashboard is on Homebot's site | On the brokerage's own address, free, with "ask for a market analysis" as a task |
| Lofty / Ylopo           | AI social posts                         | Generic AI copy with no source; English only                        | Real MLS numbers with source and date, in English and Spanish                    |
| WiseStamp / HubSpot     | Email signature generators              | Separate tool that drifts from the brand                            | Built from the brand kit and used on every RightAlly email automatically         |
| Handwrytten / Thankster | Handwritten cards                       | Separate app; agents must re-type addresses                         | One click from the CRM, with birthday and anniversary prompts                    |

## Privacy and compliance

- The dashboard link is signed and never expires. Pages are `noindex`, `no-store` and `no-referrer`. The equity balance never leaves the browser.
- Content posts describe homes and prices only (Fair Housing). Opted-out contacts are never enrolled. The CSV export is protected against spreadsheet formulas.

## Not in this sprint (honest)

- Branded video intros, outros and captions (C22).
- Equity reports from public records and "homes like yours sold" (C23). These need sold data (a VOW or back-office licence).
- A direct API connection to the handwriting partner; it goes in once a partner is signed. **Review:** partner choice and price per card (HQ).

## Settings and review

- Post templates, the 320-character card limit, and the 36-month value history are defaults to **review**.
