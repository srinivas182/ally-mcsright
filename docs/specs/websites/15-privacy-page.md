# Websites 15 — Privacy policy on every brokerage website

**Sprint W15.** Every brokerage website has a privacy policy at `/privacy`, linked from every page's footer and
listed in the sitemap. Instant-form ads (specs/ads/03, 04) use it as the form's privacy link by default.

## What it says

RightAlly's standard policy, written to match what the platform actually does:

- **What we collect:** what people type into forms and their account, the homes they view (under a random id, no
  third-party trackers; Global Privacy Control and Do Not Track are honoured), Ask Ally chats, and Meta instant-form
  answers.
- **How it's used:** to answer them, send the alerts they asked for, and help the brokerage's agents.
- **Texts and calls:** only with consent, 8am–9pm local time, STOP and HELP always work.
- **Sharing:** the brokerage's agents and service providers only. Information is never sold, and never passed to
  other brokerages.
- **Also covered:** their choices (access, correction, deletion), security, children under 16, MLS listing data, and
  how to contact the brokerage.

## The brokerage's own policy

The owner can paste their own policy in Website settings (`info.privacy`, up to 20,000 characters). It is shown as
escaped plain paragraphs, with tags stripped, in place of the standard policy. The contact block always shows.

## SEO and AEO

- Indexable page with its own title, description and canonical URL.
- WebPage and BreadcrumbList structured data.
- Listed in `sitemap.xml`. A privacy page is a trust signal, so it is not hidden from search.

## Tests

`tests/Feature/Sites/PrivacyPageTest.php`
