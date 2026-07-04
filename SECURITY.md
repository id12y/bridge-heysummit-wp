# Security policy

This plugin talks to a live event platform (HeySummit) and — through its
bridges — creates real attendee records from checkout, form and account
data. Security reports are taken seriously and handled quickly.

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately instead:

- Use GitHub's **"Report a vulnerability"** (Security tab → Advisories →
  Report a vulnerability) on this repository, or
- Email **security@emailexpert.com** with the details.

Include what you can: affected version, operating mode (Lite/Full), steps
to reproduce, and impact as you understand it. You will get an
acknowledgement within a few days; a fix or mitigation plan follows as fast
as severity warrants. Please allow a reasonable disclosure window before
publishing.

## Scope notes for researchers

Things this plugin deliberately promises, which make good test targets:

- **Write allowlist**: the API client can only ever POST to
  `events/<id>/attendees/` and `events/<id>/attendees/<pk>/tickets/`
  (anchored patterns in `src/Api/WriteEndpoints.php`). Any path that
  escapes it is a vulnerability.
- **The API key never leaves the server**: no front-end page load calls
  HeySummit from the browser; keys are write-only in the UI (last 4 shown)
  and never logged or exposed over REST.
- **Consent and suppression gates**: no bridge pushes a person without a
  satisfied consent source, and the suppression list is re-checked at
  delivery. A bypass is a vulnerability.
- **Email addresses never reach the log** (hashes only) and visitor-typed
  input (search strings, filter parameters) must never mint unbounded
  cache/transient rows.
- Public endpoints (`/eex/v1/register`, the webhook receiver, the calendar
  feed) are rate-limited and treat all input as untrusted.

## Supported versions

BETA: only the latest release receives fixes.
