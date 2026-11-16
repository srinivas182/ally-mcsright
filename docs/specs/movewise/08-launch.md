# Movewise MW8: launch hardening and go-live

Status: **Built** (sprint MW8). Follows [00-overview](00-overview.md) §12, §15 and §16. This is the last Movewise sprint. After it, launching is an operations task, not a build task. The runbook is in section 6.

## 1. Launch order

- **Texas goes first.** Each later state can be switched on only after the first state (`movewise.launch_order[0]`, Texas) has been live for `movewise.launch_gap_days` (30 days by default). The order is Texas, then Louisiana, Mississippi and Alabama.
- **This is part of the legal gate** (`Movewise::missing`), so the switch simply refuses with the reason.
- **Three new checklist items** must be signed off in HQ for each state:
    - **State counsel sign-off**: the counsel's name, the date, and where the opinion is kept go in the note.
    - **A2P 10DLC texting registration** approved for the relay numbers.
    - **Launch review done**: load test passed, launch checks green, support rota set.

## 2. Launch checks (HQ → Movewise launch, and `movewise:launch-check {state}`)

These are green or red for each state:

| Check                           | Green when                                                                  |
| ------------------------------- | --------------------------------------------------------------------------- |
| Legal gate                      | Partner, checklist, MLS feed and launch order are complete                  |
| Pricing                         | At least one model is on and priced                                         |
| Live agents                     | At least 3 live agents with a live package, so people can choose from three |
| Relay numbers                   | At least one active relay number                                            |
| Texting                         | Twilio is set up in HQ                                                      |
| Email sender and postal address | `MOVEWISE_MAIL_FROM` and `MOVEWISE_POSTAL_ADDRESS` are set (CAN-SPAM)       |

The command exits non-zero when the named state isn't ready, so it can gate a deploy pipeline.

## 3. Audit packs (HQ → Movewise launch)

The packs are CSV downloads for a date range. They are password-confirmed, every download is logged, and **phone numbers are never included**.

- **Fair Housing pack:**
    - every set of three shown, with what it was based on (state, ZIP code, city, price, lead type, language);
    - for each agent shown: their position, whether they took the place kept for an agent not shown lately, and why they were chosen;
    - every AIlly reply replaced for Fair Housing, with the phrases caught;
    - a header stating that ranking reads nothing else about the person.
- **Consent pack.** For every introduction, the consent:
    - **the consent itself:** the wording, word for word; the time and IP; the agent, licence, brokerage and account; the way of contact;
    - **how it went:** the status; when it was accepted and when it ended, and how; the relay number; the hand-over time; the referral agreement time.

## 4. Calls through the relay (finishing MW5)

- **Webhook.** `POST /webhooks/movewise/voice` is Twilio-signed. It is the voice webhook for every relay number.
- **The person calls their Movewise number** and reaches their agent's phone, from their profile. The agent sees the relay number.
- **The agent calls the same number from their own phone** and reaches the person. The person sees the relay number.
- **Quiet hours.** Calls to the person keep to 8am–9pm in the person's time. Outside that window the agent hears a message.
- **Pauses and stops.** A pause, stop, change or hand-over means the call doesn't connect, and the caller hears why.
- **One pair per number.** A relay number is never shared by two of the same person's introductions, nor by two of the same agent's live clients. So a number always means one pair, for both texts and calls.

## 5. Performance

- **Header and footer.** The site's header and footer (states and brokers of record) are cached for 60 seconds. The cache is dropped the moment a state or partner changes.
- **Place pages.** Market facts on city, ZIP code and neighbourhood pages are cached for 15 minutes.
- **Listings index.** A new index on listings `(state_or_province, standard_status, internet_display_allowed)` serves the state, place and sitemap queries.
- **Edge caching.** Pages stay cacheable at the edge (`s-maxage`). Signed-in pages and every write are `no-store`.
- **Load test.** `tests/load/movewise.js` (k6). The launch pass mark: **1,000 visitors at once for 10 minutes, against the origin with the CDN bypassed, with the 95th percentile under 800 ms and under 1% errors.**

## 6. Go-live runbook: Texas

### Before

1. Register the domains and set DNS. `movewisehomes.com` is the main host; set the redirect hosts through `MOVEWISE_REDIRECT_HOSTS`. Put the CDN in front and respect `Cache-Control`.
2. Set `MOVEWISE_HOST`, `MOVEWISE_MAIL_FROM` and `MOVEWISE_POSTAL_ADDRESS`. Set up the sending domain (SPF, DKIM, DMARC) for `hello@movewisehomes.com`.
3. Sign the managing broker partner. Enter the partner in HQ → Movewise with the licence, IABS link, contract date and HQ's referral share.
4. Request the Texas MLS feeds under the partner's broker MLS id, and approve them in HQ → MLS once licensed.
5. Twilio:
    - buy local relay numbers;
    - set each number's **messaging** webhook to `/webhooks/twilio/sms` and its **voice** webhook to `/webhooks/movewise/voice`;
    - get the A2P 10DLC campaign approved;
    - add the numbers in HQ → Movewise agents → Relay numbers.
6. Set prices in HQ → Movewise agents.
7. Recruit agents. Each one goes through the five checks, and needs at least three live packages covering the launch area.
8. Run the k6 load test on staging and keep the report.
9. Counsel signs off. In HQ, tick every checklist item with notes (counsel, A2P, launch review).
10. `php artisan movewise:launch-check TX` must be all green.

### Launch

11. Switch Texas on in HQ → Movewise.
12. Submit `https://movewisehomes.com/sitemap.xml` in Google Search Console and Bing Webmaster Tools.

### The first 30 days

13. **Daily:**
    - introductions waiting and expired;
    - refund requests;
    - the ads status (top-up held or OK);
    - reviews to moderate.
14. **Weekly:** the scorecards (Mondays), and the Fair Housing pack reviewed by the compliance lead.
15. **Monthly:** partner settlements (2nd of the month); the consent pack is archived.

### Next states

16. After 30 days, repeat steps 3–10 for Louisiana, then Mississippi, then Alabama.

## 7. Scheduled jobs (all `onOneServer`)

| Job                              | When                   | What it does                                            |
| -------------------------------- | ---------------------- | ------------------------------------------------------- |
| `movewise:expire-offers`         | Every minute           | Ends offers not accepted in time                        |
| `movewise:alerts`                | Daily 13:17            | Saved-search emails                                     |
| `movewise:recheck-licences`      | Daily 04:41            | Re-checks each licence every 30 days                    |
| `movewise:outcomes`              | Daily 15:07            | Status reminders, pauses, share fallback, ads stop-loss |
| `movewise:outcomes --scorecards` | Mondays 06:13          | Scorecards and tiers                                    |
| `movewise:outcomes --settle=`    | 2nd of the month 07:19 | Last month's partner settlements                        |

## 8. Tests

The MW8 section of `tests/Feature/Movewise/MovewiseMatchingTest.php` covers:

- the launch order and the launch checks, through the command and the HQ page;
- both audit packs: password-confirmed, with their contents, and without the person's number;
- relay calls both ways, quiet hours, pauses, unknown callers and unsigned requests;
- the cache being dropped when a partner or state changes.
