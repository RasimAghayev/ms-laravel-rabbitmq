# Accessibility Statement

## Scope

This repository is two headless JSON REST APIs (`admin/`, the write side;
`main/`, the read side) with no web user interface. There is no SPA, no
server-rendered application UI, and no frontend asset pipeline wired up —
the only HTML either app serves is Laravel's own stock `welcome.blade.php`
at `/`, which exists only because `laravel new` generates it by default.
Traditional WCAG/assistive-technology testing (screen readers, keyboard
navigation, color contrast, zoom) does not apply to a JSON API in the way
it applies to a web UI, and claiming otherwise here would be fabricated —
no such testing has been performed against this codebase.

## What accessibility means for an API-only project

- **Meaningful HTTP status codes** — `200`/`201`/`202`/`204` for success,
  `404` for a missing resource, `422` with field-level messages for
  validation failures, `403` for a rejected internal-auth header — rather
  than overloading `200` or returning undifferentiated `500`s. See
  `docs/TECHNICAL_DEBT.md` #10/#11 for two gaps of this kind that were
  found and fixed.
- **Structured, machine-readable error bodies** (JSON, not HTML error
  pages) so any client — including assistive-technology-driven tooling
  consuming this API on someone else's behalf — can parse and present an
  error without scraping text.
- **No information conveyed only through color** — not applicable today
  (the API returns no color-coded data), noted here so it stays true if
  that ever changes.

## The one served HTML page

`welcome.blade.php` (served at `/` in both apps) is Laravel's unmodified
default starter page. It has not been audited against WCAG 2.1 AA and no
claim is made that it conforms. It carries no interactive functionality
(no forms, no JS) — the main structural risk of an unaudited static page
(keyboard traps, interactive elements with no accessible name) does not
apply to it, but that is a fact about its structure, not a conformance
claim.

## Known limitations

- No automated or manual accessibility testing has been performed on
  this repository, for the reasons above.
- If either app ever grows a real web UI, this statement is stale the
  moment that happens and must be rewritten against the actual UI before
  any conformance claim is made.

## How to report a barrier

If you believe something in this repository creates an accessibility
barrier — including in the one static page it serves — open an issue
using the "Bug Report" template, or email the maintainer (see
`SECURITY.md` for private-channel reporting if the barrier also has a
security dimension). Include what you encountered and, if applicable,
the assistive technology or configuration used.
