# 03 — RightAlly MCP server: connect your AI assistant (Sprints M1, M2; Team A with D)

## Why

Many brokers and agents now use an AI assistant every day (Claude, ChatGPT, Microsoft Copilot, Gemini). The Model
Context Protocol (MCP) is the standard way to connect those assistants to a business app. With it, an agent can ask
their own assistant things like:

- "Who should I call today?"
- "Add Maria Lopez as a buyer lead and set a follow-up for Friday."
- "When does the inspection period end on 12 Oak St?"

Each request runs against RightAlly, with the same permissions they have in RightAlly. **This is on by default for
every brokerage**, and the owner can switch it off.

## What it is

- **One remote MCP server** at `https://{brokerage domain}/mcp`, using Streamable HTTP. It also works at the
  brokerage's own custom domain.
- **Connecting is one click, the same as the Apps page:**
    - In their AI app, the person adds the RightAlly connector (we list it in the Claude and ChatGPT connector
      directories when possible). Otherwise they paste the address once.
    - They sign in to their brokerage's RightAlly and see a plain consent screen: "Claude wants to: see your
      contacts and deals, add notes and tasks". They press **Allow**.
- **Authorisation follows the MCP authorization spec in force at build time.** Today that means:
    - OAuth 2.1-style authorisation with PKCE;
    - protected resource metadata;
    - Client ID Metadata Documents, with dynamic client registration as a fallback;
    - the token audience checked on every request.

    RightAlly is its own authorisation server for each brokerage.

- **Scopes:**
    - `read`: contacts, leads, tasks, deals, key dates, the agent's own listings, calendar availability, AIlly
      chats.
    - `write`: add a lead or contact, a note, a task, a booking, or a stage change.

    Nothing can be deleted through MCP. Bulk messaging, payouts and money movements are never exposed.

## Tools (first set)

| Tool                                                   | What it does                                                                                      | Scope       |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------- | ----------- |
| `todays_list`                                          | Today's 10 and the daily brief: who to call and why                                               | read        |
| `search_contacts`, `get_contact`                       | Contacts the person may see (ContactVisibility applies; an agent's personal contacts stay theirs) | read        |
| `create_lead`, `add_note`, `create_task`, `move_stage` | Writes, each with a plain summary of what changed                                                 | write       |
| `my_deals`, `deal_key_dates`, `deal_missing_items`     | The person's deals, deadlines and missing paperwork                                               | read        |
| `my_listings`                                          | Their own listings and their activity                                                             | read        |
| `open_times`, `book_time`                              | Their availability, and booking a call or showing                                                 | read, write |
| `website_chats`                                        | Recent AIlly chats and closing briefs for their leads                                             | read        |

**MLS data:** MCP doesn't search or show MLS listings beyond the agent's own. MLS display rules don't allow IDX
data inside third-party AI apps without the MLS's permission. HQ can widen this per MLS when a licence allows it.

## Safety and control

- **Owners:** a brokerage switch for MCP (on by default), a list of every connected AI app per person, and
  **Disconnect** for any of them.
- **Each person:** Settings → Apps → AI assistants shows their connected assistants and a Disconnect button.
- **HQ:** a global switch, and the connector directory listings.
- **Every tool call is audited** (who, which AI app, tool, records touched) and rate limited per person.
- **Sensitive fields are never returned:** SSNs, bank details and TINs. Consent rules still apply: MCP can't send
  texts.
- **Tool results are data.** The server never lets text inside records act as instructions; this follows the AI
  data-protection rules (specs/ai).
- **Usage:** MCP calls don't use RightAlly credits, because the person's own AI app does the thinking.

## Sprints

- **M1:**
    - the MCP server and one-click authorisation;
    - read tools;
    - the owner switch and the connected-apps list;
    - auditing and rate limits;
    - a guide for Claude and ChatGPT.
- **M2:**
    - write tools;
    - the daily brief tool;
    - directory listings;
    - per-MLS widening.
- **Later (optional):** an MCP _client_ in the RightAlly AI assistant, so a brokerage can plug in its own MCP
  tools.
