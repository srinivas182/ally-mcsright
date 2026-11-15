# 14 — AIlly: agents join live chats, text follow-up, A/B tests (Sprint 17D-4)

## Agent joins a live chat

- **Live** means the visitor wrote in the last 10 minutes and the chat hasn't ended.
- **Who can see a chat:**
    - owners and staff see every chat;
    - an agent sees chats on their own website and chats with their own leads.
- **Screens:**
    - `/ailly/chats` lists the chats happening now (refreshes every 10 s).
    - `/ailly/chats/{ulid}` shows one chat (refreshes every 3 s).
    - Settings → AIlly → Chats shows a "Live now" list.
- **Joining a chat** (`take`):
    - AIlly stops answering.
    - The visitor sees a note: "Jane from the team joined the chat."
    - Only the agent's first name is shown.
    - Owners and staff can take a chat from another agent. Other agents can't.
- **While an agent has the chat:**
    - The visitor's messages are saved.
    - The agent gets an alert (`LeadAlert`, linking to the chat) at most every 2 minutes.
    - The widget gets the agent's messages by checking `POST /_ailly/poll {conversation, after}` every 3 s, or every 8 s while no agent is in the chat. It checks only while the chat is open. Rate limit: 40 checks a minute per IP.
- **Agent messages:**
    - tags are stripped, and messages are capped at 1,000 characters;
    - stored with `role = agent` and `user_id`;
    - AIlly sees them as "Agent (a person on the team)" when it answers again;
    - the closing brief sees them as "Agent".
- **Handing back:** a note says AIlly is back, and AIlly answers again.
- **Hot-lead alerts** (text and email) now link to the live chat ("Join the chat") instead of the leads list.
- Joining and handing back are both audited.

## Text follow-up after a chat

- `LeadIntake` passes `ai_handoff {skip, origin, assistant}` to `AiResponder::leadReceived`.
- **A chat lead who agreed to texts and booked no time:**
    - The AI first responder (when the brokerage has it on) texts in AIlly's name: "Hi, it's Ask Ally, the AI assistant from the … website chat."
    - The prompt (`crm.ai_responder` v2, `{{continuing}}`) picks up where the chat left off and doesn't repeat questions the visitor already answered.
    - It replies in the visitor's language.
    - The thread stores `origin = website_chat` and `assistant_name`.
- **A visitor who booked a time:**
    - The AI first responder doesn't start, so there are no qualifying texts.
    - `BookingTexts::confirm` sends a confirmation text now.
    - `RemindBooking` sends a reminder 2 hours before. The reminder is skipped if the task is done or its time has passed.
- All of these texts go through `Inbox::sendAutomated`: written consent, quiet hours (8am–9pm), opt-outs, and "Reply STOP to opt out." They appear in the agent's inbox, and replies come back there.

## A/B tests (greeting and when AIlly asks for details)

- **Storage:** `settings.ailly_test {on, greeting_b, gate_b, started_at}` on the brokerage website, kept apart from the main settings so saving settings never ends a test.
- **Variant:**
    - chosen by `crc32(visitor id)` (by chat when there is no visitor id), so a returning visitor keeps their version;
    - stored on `ailly_conversations.variant`;
    - applied to the greeting (`start`) and the gate (`start` and `reply`).
- **Results:** chats, leads, lead rate and bookings for each version since the test started.
- **Verdict:**
    - a two-proportion z-test at 95%;
    - needs at least 100 chats per version;
    - written in plain words ("Version B turns 12.5 more chats in every 100 into leads…").
- **Ending a test:**
    - "Use B for everyone" copies B into the settings and ends the test;
    - "End the test, keep A" just ends it.
- Version B's greeting is checked by the Fair Housing check. Version B must differ from A.

## Data

Migration `2027_01_23_000001_add_ailly_live`:

- `ailly_conversations`: `taken_by`, `taken_at`, `agent_alerted_at`, `variant`
- `ailly_messages`: `user_id`
- `crm_ai_threads`: `origin`, `assistant_name`

## Tests

`tests/Feature/Sites/AillyLiveTest.php`.
