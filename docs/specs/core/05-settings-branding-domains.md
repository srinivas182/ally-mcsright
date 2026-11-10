# 05 — Settings, branding and domains (Team A, Sprint 8)

## Purpose

Each tenant configures its identity once (name, address, licence, time zone, language, brand) and
points its own domain at RightAlly with automatic SSL. Brand colours generate a complete,
accessible palette so tenants cannot make the product look cheap or unreadable.

## Settings

### `tenant_settings` (cell)

`id, tenant_id, group, key, value (json), updated_by, updated_at`; unique `(tenant_id, group, key)`.

Typed settings classes per group, with defaults, validation and caching
(`t:{tenant}:settings:{group}`, invalidated on save). Every change is audited with old/new values.

| Group           | Settings                                                                                                                                       |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| `general`       | Legal name, display name, address, phone, website, primary state, brokerage licence number(s), default time zone, default language (`en`/`es`) |
| `branding`      | Logo (light, dark), favicon, primary colour, secondary colour, font pairing, generated palette (read-only), back-office accent on/off          |
| `security`      | Two-factor policy (spec 02), session idle timeout (15–480 min, default 120), allowed SSO providers                                             |
| `localization`  | Date format, first day of week, measurement units                                                                                              |
| `notifications` | Sender name, reply-to email, default channels                                                                                                  |
| `compliance`    | Required disclosure text, Equal Housing logo placement, brokerage name display rules (used by Teams C and D)                                   |

Offices (spec 03) can override address, phone, licence number and logo.

## Brand palette generation

1. Tenant picks a primary and optional secondary colour (hex) and a font pairing from a curated
   list of 8 pairings.
2. The system generates 11-step scales (50–950) in OKLCH for primary, secondary and a neutral,
   plus light and dark mode tokens.
3. It checks WCAG 2.2 AA contrast for text on surfaces and buttons; if a combination fails, it
   adjusts lightness automatically and shows the adjusted swatch with a note.
4. Tokens are stored as JSON and exposed as CSS variables; the back office applies the logo and an
   accent only (consistent usability); websites apply the full palette (Team C).
5. Live preview of buttons, links, headers and a sample card before saving.

## Uploads

Logos and favicons: SVG, PNG or WebP, max 2 MB, SVG sanitised; stored on the tenant disk;
favicon sizes generated. Virus scanning applies to all uploads.

## Domains

### Platform subdomain

Created at provisioning: `{slug}.{CORE_PLATFORM_DOMAIN}` (configurable, e.g. `rightally.app`).
Always works, even after a custom domain is added (redirects to the primary).

### Custom domains

1. Tenant enters a host (e.g. `www.joneshomes.com`). Validation: valid hostname, not a platform
   domain, not already used by another tenant.
2. RightAlly registers it as a custom hostname with Cloudflare for SaaS and shows DNS instructions:
    - `CNAME www → customers.{CORE_PLATFORM_DOMAIN}`
    - For an apex domain (`joneshomes.com`): CNAME flattening/ALIAS if the DNS provider supports it;
      otherwise instructions to redirect the apex to `www` at the registrar.
    - A TXT record if ownership verification is required.
3. A scheduled job polls verification and SSL status every 5 minutes for 72 hours, then hourly for
   7 days; status shown on the Domains page with plain-English guidance.
4. When verified and SSL is active, the tenant can **set it as primary**: the platform subdomain
   and any other domains 301-redirect to it; canonical URLs and emails use it.
5. Removing a domain deletes the Cloudflare hostname and falls back to the platform subdomain.
6. The number of custom domains is limited by package (spec 07); agent domains come later (Team C).

### `tenant_domains` additions

See spec 01. Status changes emit `DomainVerified`, `DomainFailed`, `PrimaryDomainChanged`,
`DomainRemoved` and invalidate the resolution cache.

## Screens

- **Settings → General**, **Localization**, **Notifications**, **Compliance**: forms with
  inline validation and save confirmation.
- **Settings → Branding**: uploads, colour pickers, font pairing, contrast results, live preview.
- **Settings → Security**: 2FA policy (with role picker), session timeout, SSO providers.
- **Settings → Domains**: list with status badges, add domain wizard with copyable DNS records,
  "check now", set primary, remove.
- **Offices**: override fields (spec 03 screen).

## Permissions

`core.settings.view`, `core.settings.manage`, `core.branding.manage`, `core.domains.manage`,
`core.security.manage`.

## Acceptance criteria

1. Settings are tenant-scoped, typed, validated, cached and audited.
2. Any primary colour produces a palette whose text/background pairs meet WCAG 2.2 AA; failing
   combinations are adjusted and flagged.
3. Uploads reject wrong types and oversized files; SVGs are sanitised.
4. Adding a custom domain shows correct DNS records; status updates automatically; setting it
   primary redirects all other hosts with 301.
5. A host can belong to only one tenant; removing a domain falls back cleanly.
6. Domain changes take effect for routing within 5 minutes (cache invalidated immediately).

> **Extended by [08 — Brand Kit and Brand Voice](08-brand-kit-and-voice.md):** brand guide upload, Brand Voice, branded templates and videos, and a brand and quality gate on every AI output.
