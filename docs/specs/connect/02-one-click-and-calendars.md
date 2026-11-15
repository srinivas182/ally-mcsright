# 02 — One-click connect and calendars (Sprint C1b, Team A)

## One-click connect (OAuth, for every app that offers it)

1. **Brokerage host.** The person presses **Connect** (`POST /apps/{app}/oauth`). The server seals a 10-minute
   state, encrypted with the app key, holding:
    - the brokerage, host, app and person;
    - a nonce stored in this browser's session;
    - the PKCE verifier.

    The browser goes to the provider with `redirect_uri = https://{core.sso_host}/connect/oauth/callback`, the PKCE
    S256 challenge, and the app's scopes plus `openid email`.

2. **Central host** (`/connect/oauth/callback`):
    - The state is checked.
    - The code is exchanged for tokens on the server, with the client secret and the verifier. The secret never
      reaches a browser.
    - The account email is read.
    - The encrypted tokens are put under a single-use ticket that lasts 2 minutes, and the browser goes back to
      `https://{brokerage host}/apps/oauth/complete`.
3. **Brokerage host.** The same signed-in person, in the same browser (the nonce must match), redeems the ticket.
   The connection is saved with `credentials = {access_token, refresh_token, expires_at, scope}` (encrypted) and
   `label =` the account email. This is audited as `one_click: true`.

**When things go wrong:**

- If the person cancels at the provider, they see "You didn't approve the connection, so nothing changed."
- Any other failure tells them to "press Connect again".

**Tokens after connecting:**

- They are refreshed a minute before they expire.
- If the provider refuses the refresh (400/401), the connection shows **Connect again**, and the person gets an
  email at once.
- Disconnecting tells Google to forget the sign-in (revoke), as a best effort.

**Client IDs and secrets:**

- They are `services.google.*` and `services.microsoft.*`, the same apps as "Sign in with Google/Microsoft".
- Until HQ sets them up, the app's page says "will be ready to connect soon" and the Connect button is hidden.

**Business steps for HQ:**

- **Google:** enable the Calendar API, add `https://{sso host}/connect/oauth/callback` as a redirect URI, and pass
  OAuth verification for the sensitive scopes `calendar.events` and `calendar.freebusy`.
- **Microsoft:** add the same redirect URI to the Entra app registration, grant the delegated permission
  `Calendars.ReadWrite`, and complete publisher verification.

## Calendars

- **Google Calendar and Microsoft 365 Calendar:**
    - Agent scope: each person connects their own.
    - Available from the Apps page, and from **My availability** ("Your calendar").
- **Busy times:**
    - Google: `freeBusy` on the primary calendar.
    - Microsoft: `calendarView`, where events marked "free" don't count.
    - RightAlly reads only start and end times, never what the events are.
    - Results are kept for 5 minutes for each connection and range.
    - Busy times are subtracted from the agent's open slots, together with their RightAlly tasks
      (specs/websites/13).
- **Bookings into the calendar:**
    - Every booking (`Availability::book`) is queued into the calendar: the title, the time, and the contact's phone
      and email (it is the agent's own calendar). The calendar's default reminders apply.
    - Notes and chat transcripts are never copied in.
- **It never blocks booking.** If the calendar can't be reached, slots come from RightAlly tasks only and the
  connection's health records the failure. After 3 failures the agent gets an email.
- **Contract:** `Core\Connect\Calendars` (`busy`, `addEvent`, `connected`). The default is `NoCalendars`; the
  Connect module supplies `ConnectCalendars`.

## Tests

`tests/Feature/Connect/CalendarConnectTest.php`
