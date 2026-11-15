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

## Guardrails (as built in M1)

Every tool extends `Core\Mcp\RightAllyTool`, so no tool can skip these checks.

1. **Who.** Every call acts as the person behind the token.
    - The account is looked up inside the brokerage's own data, so a token issued at another brokerage finds no one.
    - The account must be active and must not be HQ staff.
    - AI assistant access must be on at HQ (the `ai_assistants` app) and at the brokerage (the owner's switch).
2. **What.** Each tool's `allows()` and its queries use the same rules as the screens:
    - `ContactVisibility` for contacts (an agent's personal contacts stay theirs, even from owners);
    - `Transactions\DealAccess` for deals (the one deal rule, now shared by the screens and MCP);
    - ownership for tasks and availability.

    A tool a person's role can't use isn't listed for them. Every other refusal answers with the reason in plain
    words.

3. **Writes.** A write tool needs all three of these:
    - the token's `rightally:write` permission, which the person approves;
    - the brokerage allowing changes (the owner's second switch);
    - the module's own services, which only change what that person can change.

    There are no delete tools, and nothing moves money or messages people in bulk.
    - **Future modules:** campaigns, ads and anything else must register their write tools through the toolbox and
      call their own services. They then inherit these checks automatically.
    - **Bulk or costly actions** (for example, starting a campaign or spending credits) must return a preview first
      and only act on a second, confirmed call (M2).

4. **Limits and records.**
    - Each person can make 60 tool calls a minute, and the endpoint takes 120 requests a minute.
    - Every call is audited as `mcp.tool` (tool, AI app, argument names; writes at notice level).
    - Errors come back as plain messages.
5. **Data, not instructions.** The server's instructions, and a note on every result, tell the AI app that text
   inside records is information, not instructions.
6. **Registration.** Only known AI apps may register through dynamic client registration:
    - claude.ai, claude.com, chatgpt.com, chat.openai.com, copilot.microsoft.com and gemini.google.com;
    - localhost, for desktop apps.

    HQ can extend the list (`connect.mcp_redirect_domains`). The consent screen names the app and the site it
    returns to.

7. **Tokens.**
    - Access tokens last 8 hours and refresh tokens 60 days.
    - They are stored in the brokerage's own data.
    - Disconnecting, or the owner switching access off, revokes the access token and its refresh token straight
      away.

## M1 (built)

- **Endpoint:** `POST /mcp` (Streamable HTTP, `laravel/mcp`), protected by `auth:api` (Laravel Passport).
- **Discovery:** `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`.
- **Registration:** `/oauth/register`.
- **Consent:** `/oauth/authorize`, shown as `mcp/consent`.
- **Tools:**
    - CRM: `search_contacts`, `get_contact`, `my_tasks`, `todays_list`, `open_times`.
    - Transactions: `my_deals`, `deal_details` (key dates and missing paperwork; no commission or bank figures).
- **Apps → AI assistants:**
    - how to connect, with the address and a Copy button;
    - your connected assistants, with Disconnect;
    - for owners: the two switches, and everyone's assistants.
- **Go-live:**
    - run `php artisan passport:keys` once per environment, or set `PASSPORT_PRIVATE_KEY` and
      `PASSPORT_PUBLIC_KEY`;
    - HQ keeps the `ai_assistants` app on.

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
