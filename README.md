# Phore Mail Client

PHP 8.5+ IMAP library with immutable `Email` content and explicit server operations.
Install with `composer require phore/mail-client`. Requires `phore/markdown` and
Webklex 6.2.0's pure PHP transport; `ext-imap` is not used.

## Automatic and manual flags

`MailClient::connect(...)` defaults to `mode: 'automatic'`. Use `mode: 'manual'`
for explicit flag management. Mode changes affect future operations only:

```php
$client->setAutomaticMode(false);              // all automatic flags off
$client->setAutomaticMode(true);               // all on; resets overrides
$client->setAutomaticMode(false, 'seen');      // only automatic Seen off
$client->setAutomaticMode(false, 'answered');
$client->setAutomaticMode(true, 'forwarded');  // can also enable one in manual mode
$enabled = $client->isAutomatic('seen');
```

| Action | Automatic behavior |
|---|---|
| `get()` / `listNew()` | Mark successfully read messages `\Seen` |
| Save a derived reply/reply-all draft | Mark its server-side source `\Answered` after successful storage |
| Save a derived forward draft | Mark its server-side source `$Forwarded` after successful storage |
| Local composition, internal reads, attachment access | No automatic flags |

Explicit `markRead`, `markUnread`, `markAnswered`, `markForwarded`, `addFlag`,
`removeFlag` remain available in both modes. Automatic Answered/Forwarded means
**a draft has been saved**, not that mail was sent. Disable these actions if your
workflow reserves the flags for actual sending. Saving and setting the source flag
are separate IMAP operations: if the latter fails, the exception names the saved
draft ID; retry the same Email to finish without duplicating the draft.

## Examples

- [Connection and mode](examples/api/connect-mail-client.php)
- [Address values](examples/api/addresses.php)
- [Compose a draft](examples/api/compose-draft.php)
- [Templates and signatures](examples/api/create-message-defaults.php)
- [Incremental reading](examples/api/read-new.php)
- [Reply/reply-all](examples/api/reply.php)
- [Forward](examples/api/forward.php)
- [Flags and trash](examples/api/message-actions.php)

Set the `MAIL_IMAP_*` environment variables shown in the connection example.
`MAIL_MODE=manual` makes the examples read-only until an explicit write is requested.
No sending API is exposed. Trash requires native MOVE plus UIDPLUS, with no delete
or EXPUNGE fallback. Inbox is `INBOX`; configure the exact Drafts and Trash names.
TLS is always implicit and certificate-verified (default port 993).

`Email` values retain their Message-ID through local edits. Server IDs and cursors
encode account, folder, UIDVALIDITY and UID; stale/foreign references fail. Store
`nextCursor` only after processing a whole batch. New means after cursor, not unread.
Attachment metadata is fetched without binary content; access is explicit and bounded.
Source HTML stays untrusted. Markdown rendering does not fetch remote content.

Repeated sequential `saveDraft()` calls with identical resolved content reuse the
same draft; changed content under that Message-ID is a conflict. The library verifies
stored content, not a claimed hash header. IMAP has no atomic unique-Message-ID
constraint: serialize concurrent writes of the same Email in your application.
Missing/ambiguous APPEND results fail explicitly; retry searches before appending.
Attachment/body limits are 25 MB, headers 256 KB, and MIME nesting 20 levels/200 parts.
Group/obsolete address syntax is rejected. Quoted signatures are retained as content;
complex incoming HTML is quoted through its plain-text view, without active markup.

## Tests

`composer test` runs local unit tests. `PHORE_TEST_IMAP=1 composer test` additionally
runs integration tests against the disposable local Dovecot created by
`bash test/Integration/dovecot.sh` (Linux, sudo). CI checks PHP syntax, the offline
address example, MIME round trips, retries/conflicts, cursors, automatic/manual flags,
trash and TLS hostname rejection. No personal mailbox is used by these tests.

The separate **WEB.DE read-only integration** workflow uses GitHub secrets `EMAIL`
and `EMAIL_PASSWD`. It verifies TLS/authentication, opens INBOX with EXAMINE, searches
UIDs and reads at most the three newest messages through `get()` and cursor pagination.
It checks manual mode, per-action Seen suppression and unchanged persistent flags.
Attachment descriptors are checked without downloading attachment content. A test
transport guard rejects all write operations. No mail content, addresses, identifiers,
server responses or exception traces are logged or uploaded as artifacts. An empty
INBOX passes connection/cursor checks and explicitly skips message-dependent checks.
Concurrent deletion or flag changes by another client can fail the live assertions.

The same read-only probe is exercised against seeded Dovecot in normal CI, which also
covers attachment byte limits and failed APPEND without automatic source flags.
The provider workflow runs on relevant source/dependency/test changes on main and the
`test/expand-imap-provider-coverage` branch, and supports manual dispatch. It is separate
from ordinary PR tests and never exposes secrets to fork pull requests.
WEB.DE must have IMAP access enabled; two-factor accounts may need an app password.
See [WEB.DE's server settings](https://hilfe.web.de/pop-imap/imap/imap-serverdaten.html).

Provider rendering in Thunderbird/Outlook and arbitrary provider interoperability
are not claimed by the local Dovecot tests.
