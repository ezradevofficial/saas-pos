# ADR 009: Notifications, system messages and one-time codes

Status: Accepted (Phase 3 review, NOT-02)

## Context

NOT-02 says every module sends notifications through one service, the Notifier (`App\Core\Notifications\Notifier`). The Notifier provides tenant templates (NOT-03), preferences and mandatory channels (NOT-04), digests (NOT-05) and a delivery log with retries (NOT-06).

Identity still sent three kinds of message with Laravel notifications of its own:

- invitation links (AUTH-05)
- new-device sign-in alerts (AUTH-10)
- one-time codes: sign-up verification, two-step sign-in and password reset (AUTH-01, AUTH-03, AUTH-04)

Those messages had no templates and no delivery log. Three things kept them out of the Notifier:

- An invitation goes to a contact that is not a user yet. The Notifier only addressed users.
- An invitation link is a credential. The Notifier stores every message body in `notification_deliveries`.
- A phone-only user needs SMS. The Notifier's SMS channel has no provider yet, while Identity's SMS sender does.

## Decision

### Invitations and sign-in alerts go through the Notifier

`App\Core\Identity\IdentityNotices` registers two **system** event types:

| Event type | Sent to | Channels |
| --- | --- | --- |
| `core.identity.invited` | the invited email address or phone number (`NotificationAddress`) | email or SMS, whichever the invitation names |
| `core.identity.new_device` | the user | email; SMS when the user has no verified email |

Both are mandatory: they are never switched off and never digested. Tenants can edit their texts like any other template. Every message gets a delivery row and the usual retries.

An `EventType` with `system: true` gets the following:

- **Mandatory default channels.** `Preferences::mandatoryChannels()` returns the type's default channels for every tenant. The preferences API refuses to switch them off or to digest them.
- **SMS fallback.** A user without a verified email gets the message by SMS instead.
- **Contacts that are not users yet.** `NotificationEvent::$addresses` takes a list of `NotificationAddress` (email or SMS). Each delivery row has `user_id` null and the address in `recipient`. A check constraint allows such a row only for email or SMS, with no digest and no inbox entry. The row is still a tenant row under the same row-level security. Only system types may send to addresses, so a module cannot mail arbitrary addresses. `contactsOnly` types (invitations) are left out of users' preference lists.
- **Platform SMS.** When `notifications.drivers.sms` has no driver, the type's SMS goes through the platform `SmsSender`, the same one the codes use (`SmsSenderDriver`). Account messages therefore work wherever codes work.

### Secrets are never stored

An event type declares `secrets`, the placeholders whose values must not be stored. For an invitation these are `invitation_token` and `invitation_url`. The Notifier works in three steps:

1. It renders and stores the text with the placeholder kept as `{invitation_url}`. The stored link is `/invitations/{invitation_token}`.
2. It passes the values to `SendDelivery` in the job itself. The job implements `ShouldBeEncrypted`, so Redis and `failed_jobs` hold them only encrypted with `APP_KEY`.
3. It fills them in at hand-over, for the mail or the SMS only. A retry job carries them on. A job that lacks them skips the delivery with the reason `secret_missing` instead of sending a broken link.

Neither `notification_deliveries` nor `notifications` ever holds a token. The invitation keeps only its sha256 hash, as before. `InvitationTest` scans the tables for the token.

### One-time codes stay outside the Notifier

Sign-up, two-step and password-reset codes keep their own path: `Identity\Notifications\VerificationCode`, sent at once by mail or by the platform SMS sender. This is a recorded exception to NOT-02, for four reasons:

- **Immediate.** A code is useful for minutes. It must never wait on a queue behind notification bursts, be held for a digest, or follow a backoff schedule.
- **Never switchable.** A user, or a tenant template, must never be able to turn off or reword the message that lets them sign in or reset a password.
- **Never stored.** The Notifier's value is its stored message and delivery log. A code's message must not be stored anywhere: only its HMAC lives in `verification_challenges` (ADR 002). The log would also be visible to tenant admins.
- **Before a tenant.** Sign-up codes are sent before the user, and sometimes the tenant, fully exists. The Notifier always works inside a tenant.

`PasswordResetTest::test_one_time_codes_never_reach_the_notification_tables` asserts that no code ever lands in a notification table.

## Consequences

- Invitation and alert texts follow tenant templates, and both appear in the delivery log (NOT-06). Invitations show without a user.
- Any new account or security message is a system event type, unless it carries a one-time code. A one-time code stays out of the Notifier and is added to this ADR.
- `SendDelivery` jobs are encrypted. Reading a job payload in Horizon shows ciphertext.
- `notification_deliveries.user_id` is nullable. Code that reads deliveries must handle a delivery that has no user.
