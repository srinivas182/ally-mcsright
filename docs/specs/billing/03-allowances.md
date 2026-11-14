# Billing 03: texts, calls and website maps, as built (Sprint 18A-2b)

RightAlly pays for texts and calls (Twilio) and for website maps (Mapbox) from its own HQ accounts, and brokers
never set up accounts with those vendors. Each package includes a monthly amount of each. Use beyond that draws
RightAlly credits from the brokerage pool, so the brokerage tops up one balance.

## Allowances per package

The package planner (HQ → RightAlly credits) has three monthly fields per package:

- **Texts:** counted in carrier segments. A message of up to 160 GSM-7 characters is one segment; longer ones are
  153 characters per segment. Unicode messages use 70 characters per segment, or 67 when split.
- **Call minutes:** Twilio bills whole minutes.
- **Website map views:** one view each time a visitor opens the interactive map.

Each field shows its cost to RightAlly from the cost list, for example "costs you up to $6.65 a month". The
package's margins include these costs, and saving below the minimum margin still needs a reason. Map views are
priced as if past Mapbox's free 50,000 a month, to stay on the safe side.

## Beyond the allowance

These are rate-card actions. All are automatic and charged to the brokerage pool, and HQ can change their prices.
The suggested prices come from the cost list and the target margin.

| Action               | Default    | Charged per                       |
| -------------------- | ---------- | --------------------------------- |
| `usage.text_segment` | 4 credits  | segment beyond the allowance      |
| `usage.call_minute`  | 4 credits  | minute beyond the allowance       |
| `usage.map_views_10` | 13 credits | 10 map views beyond the allowance |

- **System messages are never held back:** security codes, deadline alerts, lead alerts and calls. When credits
  are short they overdraw the pool, because the message has already gone out.
- **Messages a person sends from the inbox** are refused with a plain message when the allowance is used, credits
  are enforced and the pool can't cover them.
- **Website maps:**
    - The interactive map on the search page starts only when the visitor clicks "Show the map". On a wide screen
      it also starts by itself if the visitor opened it earlier in the same visit.
    - Each start calls `POST /_map` on the website. That counts the view and returns the public map token.
    - When the allowance and credits are used up while credits are enforced, the site keeps the list and shows "The
      map is resting for now". Nothing else on the website changes.
    - HQ can tick **"RightAlly covers extra map views"** for a brokerage. Its views are then still counted but never
      charged.
- **Listing pages** keep their static map image.

## Counting calls

A call is counted when it's placed, at an estimate: the message read twice at about 150 words a minute, rounded up
to whole minutes. Twilio then posts the call's real length to `POST https://{HQ host}/billing/twilio/call-status`,
or to `TWILIO_STATUS_CALLBACK` if set. That request is checked against the Twilio signature, and the difference is
settled once per call. Calls that connect an agent to a new lead are counted at their real length this way.

## Alerts

- **Owners** are emailed at 80% and 100% of each allowance, once each per month. The email says everything keeps
  working and gives the price beyond the allowance.
- **HQ staff** are emailed when a brokerage reaches its map allowance, so HQ can choose to cover it or suggest a
  bigger package.

## Visibility

- **Menu meter** (owners and staff): a line under credits, for example "Texts 120/500 · Call min 14/100 · Map views
  2,300/5,000".
- **Settings → RightAlly credits:** "Included in your package this month", with a bar for each allowance.
- **HQ → RightAlly credits:** map views per brokerage this month, with the "covers extra map views" option.

## Tables

- `billing_packages`: `included_text_segments`, `included_call_minutes`, `included_map_views`.
- `usage_meters` (cell): one row per brokerage, month and meter, with `used` and `over`.
- `usage_calls` (control): calls waiting for their real length from Twilio.
- `usage_tenant_policies` (control): HQ's per-brokerage choices (`maps_covered`).

## Tests

`tests/Feature/Billing/AllowancesTest.php` covers:

- segment counting
- texts beyond the allowance, and the owner emails
- inbox texts held back while system alerts still go out
- calls settled from Twilio once
- maps within the allowance, on credits, resting, and covered by HQ, plus the HQ email
- the planner pricing allowances
- the menu meter
