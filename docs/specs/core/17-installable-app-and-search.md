# 17 — Installable app, universal search and Spanish menu (PW1)

Status: **delivered (Sprint PW1, 2026-11-18)**. Closes A18 (mobile web app, universal search, simple mode, English and
Spanish).

## Market check

- kvCORE/BoldTrail, Lofty and Follow Up Boss ship native phone apps; Real's reZEN is a web app plus a phone app. Most
  agents use the phone app for contacts, tasks and deals.
- Parity: RightAlly installs on the home screen from the browser (no app store), opens on each person's own home
  page, with shortcuts to Today, Contacts and Deals.
- Add-ons: one search box for people, contacts, deals, listings and pages that respects every visibility rule;
  the whole menu and search in Spanish; a friendly bilingual offline page; nothing private is ever stored on the
  phone.

## Installable app

- `GET /manifest.webmanifest` (public, holds nothing private): the brokerage's name ("Acme Realty · RightAlly"), its
  brand colour, icons (192, 512 and a maskable 512), standalone display, shortcuts. Starts at `/app`, which sends each
  person to their own home (owners and staff `/dashboard`, agents and assistants `/agent`, simple view `/simple`, HQ
  `/hq/tenants`, signed out `/login`).
- `public/sw.js`: **never stores pages or data**. It keeps only built app files (`/build/…`, `/icons/…`) and
  `offline.html`. Page visits always go to the network; with no connection the bilingual offline page shows with a
  "Try again" button. Registered in production builds only.
- User menu → "Add to home screen": uses the browser's install prompt where there is one; on iPhone and iPad it shows
  the two taps (Share, then Add to Home Screen). Hidden once opened from the home screen.

## Universal search

- `GET /search?q=` (signed in, brokerage host, 60 a minute): at least 2 letters; up to 5 results per kind.
- Modules register providers in `Modules\Core\Search\SearchRegistry` with a Spanish and English heading:
  contacts (CRM visibility rules: own, team, office, company; never someone else's personal sphere), deals (deal
  access: reviewers see all, agents their own and those they assist), listings (MLS number or street, licensed MLSs
  only), people (only for those who may see the brokerage's people).
- Results whose page the person cannot open (an area switched off, no seat; spec 16) are dropped. One provider
  failing never breaks the others. `%` and `_` are taken literally.
- The command bar (Ctrl/⌘ K, or the search button) shows records first, then matching pages from the menu.

## Spanish

- People who choose Spanish now get the **whole** menu (sidebar, phone bar, search) in Spanish, not only the simple
  view (`Modules\Core\Navigation\NavWords`); a test keeps every brokerage menu item translated. The page's language
  is set to Spanish for screen readers. HQ stays in English.
- The search box, install help and offline page are in both languages.
- Page bodies: client-facing pages (signing, deal portal, guides, Movewise) and the simple view were already in
  Spanish; other back-office pages remain English for now.
