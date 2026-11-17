# Grow 15 — Lead-IQ Prospector (LQ2)

Plan item E11 (likely sellers and buyers, Do Not Call scrubbing). Likely sellers and buyers already in the database are scored by the CRM (contact scores) and appear in "Who to call today"; LQ2 adds people who are not in the CRM yet, and the Do Not Call Registry check.

## Leader review

| Leader                     | What they do                                     | Where they fall short                                                      |
| -------------------------- | ------------------------------------------------ | -------------------------------------------------------------------------- |
| SmartZip / Offrs           | Predict likely sellers in a farm area            | Hard to see why someone is flagged; priced per area; separate from the CRM |
| Revaluate / Fello          | Score your own database for likely movers        | Usually on top of another CRM                                              |
| Vulcan7 / REDX / Landvoice | Expired and for-sale-by-owner lists with dialers | Cold-call heavy; Do Not Call checking left to the agent                    |
| Remine                     | Property data through the MLS                    | Data without a plan                                                        |

RightAlly adds: every prospect has a plain reason; nobody is cold-called unless the brokerage's registry list says the number is clear; mail for everyone else; prospects feed the daily brief and the ready-made "Expired or for-sale-by-owner" plan.

## Prospects

- **Expired and withdrawn listings** (`Prospector::find`, daily `crm:prospector` at 11:35 UTC): listings that went Expired, Withdrawn or Canceled in the last 30 days, from MLSs the brokerage holds a **back-office** licence for (IDX licences don't allow prospecting with off-market listings), in the ZIP codes the broker chose (or all), shared out to the agents the broker chose, at most their daily number each, never twice. Reason, e.g. "Expired 2 days ago after 120 days on the market at $450,000 (cut from $480,000)."
- **For sale by owner**: the agent adds the address and where they saw it (there is no lawful bulk source).
- **Owner**: "Find the owner" looks up the owner's name and mailing address with property data (credits per lookup; free again for 30 days).
- **Phone**: never bought or scraped. The agent adds a number they found; RightAlly checks it at once.
- **Add to my contacts**: the prospect becomes the agent's lead, tagged "Prospect". With a number clear to call, the "Expired or for-sale-by-owner" plan starts; otherwise it is also tagged "Mail only" for postcards.
- Agents see their own prospects; the broker sees everyone's. The daily brief shows "N new prospects to work".

## Do Not Call Registry

The FTC's Telemarketing Sales Rule requires checking the National Do Not Call Registry before cold calls, with the caller's own subscription, using data no older than 31 days. RightAlly cannot download it for a brokerage, so the broker downloads the files for their area codes at telemarketing.donotcall.gov each month and uploads them on the Prospector page (with the download date). `DncRegistry`:

- replaces the numbers for each area code in the file, keeping only a hash of each number;
- refuses downloads more than 31 days old;
- says for any number: clear, on the registry, marked Do Not Call at the brokerage, list out of date (over 31 days), or area code not uploaded.

`Consent::canCall` now refuses a contact who came from the Prospector and has never answered (no prior relationship) unless the number is clear. Once they call or text back, or a call connects, normal rules apply.

## Tests

`tests/Feature/Crm/ProspectorTest.php`.

## Not in LQ2

Bulk prospect lists from data vendors, and a dialer (D08).
