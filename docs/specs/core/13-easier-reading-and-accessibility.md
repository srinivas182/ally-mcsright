# Easier reading and accessibility (AX1, AX2)

Status: Done (AX1 and AX2). Tracker: C18 Done.

## What it does, in plain words

- **Easier reading** (Settings → Appearance, and the **AA** button at the top of every page). Each person can choose:
    - **Text size**: Standard, Large or Largest. Everything grows, including buttons, menus and tables, because every size is relative to the text size.
    - **Stronger contrast**: darker grey text, clearer borders, underlined links.
    - **Underline links**: so every link is easy to spot.
    - **Less movement**: no sliding or fading. This is also automatic when the device asks for reduced motion.
- The choices are saved to the person's account, so they follow them to any device. They apply before the page draws, so text never flashes small first.
- **Skip to main content** is the first thing a keyboard user reaches on every back-office page. Brokerage websites already had "Skip to content".
- **Clear keyboard focus**: a thick outline shows where you are on every page (WCAG 2.2 "focus visible").
- **Accessibility statement** on every brokerage website at `/accessibility`. It covers:
    - the goal (WCAG 2.2 AA);
    - what the website does;
    - what may fall short (MLS photo descriptions, maps, other people's documents);
    - how to get help or the same information another way, with a reply within two business days.

    It is linked from every page's footer and listed in the sitemap.

## The audit (AX2)

- **Accessibility rules in every build.** The linter now checks every page and component against the accessibility rules below. A change that breaks one can't be merged.
    - Images have alt text.
    - Labels are tied to their fields, including our own input components.
    - Links and headings have content.
    - ARIA roles and properties are valid.
    - Clicks have keyboard equivalents.
    - Nothing gets focus on its own when a page opens (no autofocus).
    - There is no positive tab order.
    - Videos have captions.
- **What we fixed.**
    - Removed autofocus from 10 screens (sign-in pages, dialogs, contact search) so screen readers start at the top.
    - Removed a forced tab order on the sign-in page.
    - Gave empty table headers a spoken name ("Actions", "Offer").
    - Fixed the contact picker's list structure for screen readers.
    - Rewrote image descriptions that said "image of" or "photo".
    - Named the form editor and its drawing surface.
- **Deliberate exceptions**, each documented where it occurs:
    - A scrolling agreement is reachable by keyboard, as WCAG requires.
    - Drag and drop on the contact board has a "Move to" list for keyboards.
    - The agent's own recording previews have no captions.
    - Reels have their captions burned into the video.
- **Contrast is tested.** A test checks every text colour against its background, in light and dark, at 4.5:1 or better. It also checks the focus outline at 3:1, and the stronger-contrast colours at 7:1.
- **Public pages are tested.** The home page, home search, home value, privacy and accessibility pages are all checked for:
    - a language;
    - one main heading;
    - a working skip link;
    - alt text on every image;
    - a label on every form field;
    - a name on every button and link;
    - no positive tab order.

## Market leaders

| Leader                      | What they do                                                   | Where they fall short                                                            | What we do                                                                          |
| --------------------------- | -------------------------------------------------------------- | -------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| kvCORE / BoldTrail, Lofty   | Website templates; some accessibility overlays sold as add-ons | Overlays don't fix the underlying pages; the back office has no reading settings | Accessible pages built in, plus easier reading inside the back office for agents    |
| Luxury Presence, Real Geeks | Accessibility statements on some sites                         | Statements are often generic and not linked from every page                      | A statement on every site, in plain words, with the brokerage's own contact details |
| Follow Up Boss, SkySlope    | Clean back offices                                             | Small grey text, no text-size setting                                            | Three text sizes, stronger contrast, underlined links, less movement                |

## Why it matters

- ADA website lawsuits continue in 2026, and real estate is a frequent target.
- WCAG 2.2 AA is the standard courts and buyers point to.
- Many agents are over 50 and benefit from larger text and higher contrast.

## For agents and brokers aged 50+

- One **AA** button makes everything bigger, with no settings page needed.
- Settings use big On/Off buttons, each with a one-line explanation.

## SEO and AEO

- The accessibility statement is an indexable page with its own title, description, canonical link and breadcrumb structured data.
- It is listed in the sitemap.

## Security and privacy

- Reading choices are saved per person. Each change is logged as low-severity activity.
- Nothing else about the person is collected.

## Review

- The statement promises a reply within two business days. Brokerages that can't meet that should change it.
