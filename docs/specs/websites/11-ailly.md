# Websites 11: AIlly, the website assistant (Sprint 17D-1)

AIlly is a chat on the brokerage's website and on its agents' websites. It answers questions, shows matching homes
from the brokerage's licensed MLS listings, and turns visitors into leads. It does all this while spending
RightAlly credits only on real people.

## What visitors see

- A **"Chat with Ally"** button, bottom right. The brokerage can rename the assistant.
- The chat only starts when the visitor opens it, and it carries on across pages in the same browser tab.
- The panel is a dialog: Escape closes it and focus returns to the button. Messages are read out to screen
  readers as they arrive (`aria-live`).
- The panel shows "AI assistant · an agent can take over any time" at the top, and a note at the bottom that
  answers may be wrong and that visitors shouldn't share financial details.
- **Matching homes:**
    - When the visitor describes what they want, the AI only returns search criteria.
    - The website runs the MLS search itself (licensed listings only, MLS display rules, attribution) and shows up
      to 3 cards, each with "Ask to see it".
    - The AI never writes listings or prices.
- **Every answer ends with a next step:** "Book a call with an agent", "See all homes", "Save a search and get
  alerts" or "What is my home worth?", plus "Talk to an agent".
- **Contact form:**
    - name, plus an email or a phone number
    - the optional SMS consent, worded the same as the site's other forms
    - when booking a call, "Best time"
    - a hidden honeypot field

## Contact gate (Settings → AIlly assistant)

| Setting         | When AIlly asks for name, email and phone                         |
| --------------- | ----------------------------------------------------------------- |
| before          | Before the first answer                                           |
| after (default) | After N answers (default 2). Further answers wait for the details |
| booking         | Only when the visitor books a call or a showing                   |

The details become a **CRM lead** (`SiteLeads::capture`, source "AIlly chat"):

- On an agent's website, the lead is that agent's.
- The lead's message includes the request (a call and its time, or a showing with its MLS#) and the chat
  transcript.
- The visitor's website journey is linked to the lead.
- The usual speed-to-lead alerts follow.
- Each conversation records its outcome: `lead`, `showing` or `call`. The AI's lead score (0–100) is stored with
  it.

## Keeping credits for real people

Each AI answer is one `ai.ailly` call, an automatic action charged to the brokerage pool. Several things stop
credits being spent on the wrong visitors:

- **Bots:** no conversation for known bot user agents, an empty user agent, or a filled honeypot.
- **Rate limits:**
    - per IP address: 10 chat starts, 20 messages and 6 contact submissions a minute
    - per visitor: most messages a day (default 20; set by the brokerage)
    - 30 messages per conversation
- **Caps:** new conversations a day (default 300, set by the brokerage) and a month. The monthly cap is the
  package's `ailly_conversations`, or 500 without a package.
- **Free repeats:** a common first question already answered on this website today is answered again from
  `ailly_answer_cache` (normalised text, kept 24 hours) at no cost.
- **Off-topic:** the AI flags messages that aren't about real estate. A second off-topic message ends the chat
  politely.
- **No credits, or AI unavailable:** AIlly says it can't answer right now and asks for details, so an agent
  replies.

## Safety

- The prompt tells AIlly not to give legal, tax or lending advice, not to promise values or outcomes, not to
  invent listings or figures, and to follow Fair Housing.
- Visitor text is passed as untrusted content.
- Answers go through the content guard: a sentence is removed if it has a figure that isn't in the chat, the
  website facts or the homes shown, or if it has Fair Housing risk wording. "Safe area", "safe community" and
  "safe place to live" were added to the risk list.
- Transcripts are kept for the brokerage. The IP address is stored only as a salted hash.

## Settings and reporting

Settings → **AIlly assistant** is for owners and staff. It has:

- on or off, the name, the greeting, the contact gate, and the daily limits
- this month's chats, leads, calls booked and lead rate
- AI answers versus free answers
- the latest 30 chats, with their transcripts and lead scores

The full dashboard follows in 17D-2: cost per lead and per booked call, conversion by page, and filters.

## Tests

`tests/Feature/Sites/AillyTest.php` covers:

- on/off, and bots
- homes from the MLS, and the guard
- the contact gate
- a lead and a booked call
- free repeated questions
- off-topic chats ending
- limits
- running out of credits
- settings
