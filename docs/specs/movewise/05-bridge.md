# Movewise MW5: the bridge

Status: **Built** (sprint MW5). Follows [00-overview](00-overview.md) §4.4 and §10.

## 1. What the person gets

- **One Movewise number.** When the agent accepts, the introduction gets a relay number from HQ's pool. The agent texts from their normal RightAlly inbox; the text goes out from the relay number, and the person's replies come back into the same inbox thread.
    - Replies route through the existing reply table (`sms_threads`), which records the person's number, the relay number and the agent's account. The table is filled at accept time, so the person can text first.
    - Each of a person's live introductions gets a different relay number, so a buyer and a seller introduction never share one.
- **Quiet hours.** The inbox's existing guard holds texts outside 8am–9pm in the person's local time. The time zone comes from the area code; both coasts are used when it is unknown.
- **Personal messages only.** While bridged, the contact can't receive any of these:
    - automated texts or emails (AI first responder, long follow-up);
    - follow-up plans;
    - GoHighLevel campaigns.

    The agent's own personal messages still go.

- **Pause, stop, change agent.** These work from My Movewise, and by text from any message:

    | Text                               | My Movewise               | Effect                                                                                                                    |
    | ---------------------------------- | ------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
    | PAUSE                              | Pause for 2 weeks         | Nothing at all can be sent to them for 14 days. RESUME undoes it.                                                         |
    | STOP (and the usual opt-out words) | I'm done: no more contact | The introduction ends; the CRM's opt-out runs as usual                                                                    |
    | CHANGE                             | Change agent              | The introduction ends and three agents are shown again, without this one. Consent does not carry over: they choose again. |

    The agent is told politely, with no reasons the person didn't give. After a stop or a change, nothing more can be sent to them through RightAlly about it.

- **Handing over the number.**
    1. After a signed agreement or a closing, the agent ticks that box and asks.
    2. Movewise texts the person from the relay: "Reply YES to agree. If you don't, nothing changes."
    3. On YES, the relay says goodbye, texts go from the agent's own number, and the holds lift. Movewise steps back.

## 2. How it is built

**CRM hook (`Modules\Crm\Inbox\ContactBridge`, tag `crm.contact_bridges`).** Any module can stand between an agent and a contact. With no bridge registered, nothing changes. The CRM asks the bridges:

- `smsFrom(contact)`: which number to text from. Used by `Inbox::send` and `sendAutomated`.
- `hold(contact)`: `bulk` allows personal messages only; `all` allows nothing. Used by:
    - `SendGuard`, which blocks on `all`;
    - `Inbox::sendAutomated`, `FollowUpPlans::enroll`, `GhlCampaigns::run` and `LongNurture::enrol`, which block on any hold.
- `keyword(contact, word)`: text keywords. The bridge sees them before the CRM's own STOP and START handling. STOP still runs the usual opt-out afterwards.

**Movewise side:**

- **`Bridge\MovewiseBridge`** is the bridge. It finds the contact's latest introduction in the current account through `movewise_connections.contact_id`, which is set at accept time.
- **`Bridge\Relay`** does the work:
    - assigns numbers, preferring the least-used free one;
    - pause, resume, stop and change;
    - asks for and completes the hand-over;
    - sends polite notices to the agent;
    - audits everything.
- **HQ** adds relay numbers and turns them on or off in HQ → Movewise agents → Relay numbers (password confirmed). Each number shows how many live introductions use it.
- **No free number?** The agent texts from the brokerage's number, and the error is reported to HQ. Everything else (the holds, pause, stop) still works.

## 3. HQ set-up before go-live

- **Buy local numbers** in HQ's Twilio account (Houston, Dallas, Austin, San Antonio, New Orleans, Jackson, Birmingham to start). Set each one's messaging webhook to `https://<hq>/webhooks/twilio/sms`.
- **Register the A2P 10DLC campaign** for Movewise relay texting.

## 4. Not in MW5

- **Calls through the relay.** Today the person sees the agent's calling number; masked calling is part of MW8 hardening.
- **Email.** It already goes through per-conversation reply addresses (`reply-…@inbound…`), so the agent's personal address is never needed. A movewisehomes.com reply domain is a configuration change once the mail provider routes it.
- **Contact export.** RightAlly has no bulk contact export today. When one is added, it must ask `Bridges::allowsBulk()` too.

## 5. Tests

`tests/Feature/Movewise/MovewiseMatchingTest.php` (MW5 section) covers:

- **Relay set-up:** the relay assigned at accept, and replies routed back to the agent's account.
- **Outgoing messages:** the agent's text sent from the relay; automated texts and follow-up plans refused.
- **Text keywords:** PAUSE blocks every message and RESUME allows them again; STOP ends the introduction and opts them out.
- **Change agent:** ends the introduction, blocks messages, and shows three new agents without the current one.
- **Hand-over:** needs the agreement or closing box ticked, a YES by text, then texts go from the agent's own number and the holds lift.
