# CRM integration surface (loose coupling by design)

This plugin never requires a CRM, and no CRM requires this plugin. They
meet at exactly three seams — `wp_mail()`, four filters, and three
actions. Everything below degrades gracefully when the other side is
absent. Written with the sibling **EmailExpert Newsletter** plugin
(`id12y/newsletter`, `EEN_` prefix — this plugin is `eex_`/`EEX_`, no
collision) in mind, but any CRM can implement the same hooks.

## Transport: wp_mail() only

Every email this plugin originates (confirmation, session-added) is sent
with plain `wp_mail()`. The newsletter plugin's global `phpmailer_init`
takeover routes that through the configured provider (SES etc.), so
transport is configured once, in the CRM, and this plugin ships zero
provider code. No CRM: WordPress default mail.

## Filters this plugin applies (a CRM may answer)

| Filter | Signature | Default | Purpose |
|---|---|---|---|
| `eex_email_is_verified` | `(bool, string $email): bool` | `false` | Standard confirmation mode skips the confirm email for addresses the CRM holds with **confirmed** (double-opt-in) status. Answer `true` only for confirmed contacts. Unknown/unconfirmed → leave `false`; the visitor simply gets one confirmation email. |
| `eex_email_is_suppressed` | `(bool, string $to, string $kind): bool` | `false` | Checked before every send. Answer from the CRM suppression list. |
| `eex_should_send` | `(bool, string $kind, string $to, string $subject): bool` | `true` | Last gate. A CRM may veto, or claim the send (send it itself, return `false`). |
| `eex_register_consent_text` | `(string): string` | settings value | Legacy wording override; prefer the Settings field. |

`$kind` is `'confirm'` or `'session-added'`.

## Actions this plugin fires (a CRM may consume)

| Action | Payload | When |
|---|---|---|
| `eex_registration_pending` | `array $registration` | A submission was held and the confirmation email sent. |
| `eex_registration_confirmed` | `array $registration, string $result` (`registered`\|`already`) | The registration reached HeySummit (instant path or confirmation click). **This is the permanent-record hook**: the payload carries email, name, event, session, marketing choice, and the exact consent wording + timestamp — a CRM can stamp its contact timeline from it. |
| `eex_session_rsvp` | `string $email, string $talk_id, string $event_id, array $registration` | A session landed on a schedule. |

The `$registration` array: `connection_id, event, ticket, price_id,
talk, name, email, marketing (bool), consent { disclosure,
marketing_text, ts }`.

## What the newsletter plugin's side needs (≈10 lines, lives over there)

```php
add_filter( 'eex_email_is_verified', function ( bool $verified, string $email ): bool {
    // Answer true only for status 'confirmed' contacts, using the
    // repository's hashed-email lookup. Guarded + try/catch.
}, 10, 2 );

add_filter( 'eex_email_is_suppressed', function ( bool $suppressed, string $to ): bool {
    // Answer from EEN suppression.
}, 10, 2 );

add_action( 'eex_registration_confirmed', function ( array $reg, string $result ): void {
    // Optional: create/update the contact, store the consent receipt
    // (exact wording is in $reg['consent']), honour $reg['marketing'].
}, 10, 2 );
```

Until those hooks exist on the CRM side, this plugin behaves safely on
its own: only logged-in WordPress users skip the confirmation email.

## Consent data on the HeySummit wire (founder-confirmed 26 Jul 2026)

- `agreed_terms` is **never sent**: HeySummit stamps its own
  terms-agreement timestamp on API creates and ignores the field. Our
  local receipts record *what wording* was agreed — the platform stamp
  and our receipt together are the evidence pair.
- `communication_preferences` **is honoured** on create. The plugin
  sends it only when the stored discovery schema makes the value
  unambiguous: a `boolean` field carries the marketing checkbox
  directly. Any other type stays off the wire (a guessed shape would
  400 every registration) — the diagnostics table now displays the
  field's type and choice vocabulary, and
  `eex_communication_preferences_value` (value, marketing, schema) is
  the one-line mapping seam once the vocabulary is known. Run **Test
  connection** after HeySummit deploys schema changes; the wiring
  follows the snapshot automatically.
- The marketing choice is always in the local receipts
  (`eex_consent_receipts`) and the `eex_registration_confirmed`
  payload regardless of what the wire carries.
