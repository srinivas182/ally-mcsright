# Easier reading and accessibility (AX1, AX2)

Status: AX1 done. Tracker: C18 Partly (AX2 completes the audit and fixes).

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
