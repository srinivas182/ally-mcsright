# Connect 17 — Phone apps: RingCentral, Dialpad, Aircall (C7b)

Sprint **C7b** (Team A with D). Part of the C7 block (specs/connect/15). Mojo Dialer is partner-only (no public
API); agents can export a smart list to CSV for it meanwhile.

## What comes over

| App         | Connect                                                    | Read every hour                                              |
| ----------- | ---------------------------------------------------------- | ------------------------------------------------------------ |
| RingCentral | One click (OAuth; brokerage: a RingCentral admin signs in) | Call log (whole account, or the agent's extension) and texts |
| Dialpad     | Company API key (an admin makes it)                        | Calls that ended since the last run                          |
| Aircall     | API ID and token (an admin makes them)                     | Calls that finished since the last run                       |

Each call becomes a call on the matching contact's timeline: direction, length, outcome (connected, voicemail, no
answer) and who handled it (matched by the agent's email where the app gives it). RingCentral texts become text
entries. "Sync now" (on the app, or through Your AI Assistant) reads straight away.

## Rules

- **Matching:** by phone number to contacts the call's agent can see; if the agent isn't known, the brokerage's
  company contacts. **Unknown numbers create nothing** (no contacts from phone logs).
- **Once each:** every call and text is remembered by the app's id.
- **Speed to lead:** an outbound call or text to an open lead counts as the agent's response, through the usual
  timeline event.
- **Recordings:** a link only, and only when the brokerage ticks "Show recording links on contacts" while
  connecting, confirming every call plays a recording notice (all-party-consent states such as California and
  Florida). Recordings stay in the phone app; nothing is transcribed.
- Do-not-call flags from phone apps follow when their APIs expose them (not in this sprint).

## Notes for HQ

- RingCentral: register a REST API app (3-legged OAuth, Read Call Log and Read Messages) and pass RingCentral's
  production review.
- Dialpad's call list and Aircall's calls endpoint are polled hourly; real-time webhooks can follow if agents need
  calls on the timeline within seconds.

## Acceptance (C7b)

- Calls from each app land once on the right contact, with the agent; unknown numbers add nothing.
- An outbound call answers an open lead; recording links appear only when switched on.
