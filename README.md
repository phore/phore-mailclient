# Phore Mail Client

PHP 8.5+ IMAP library with immutable `Email` content and explicit server operations.
Install with `composer require phore/mail-client`. Requires `phore/markdown` and
Webklex 6.2.0's pure PHP transport; `ext-imap` is not used.

## Mailbox configuration from a file

Store one mailbox in a local `mailbox.json`. JSON works with the existing PHP
requirements, without an additional parser dependency:

```json
{
  "host": "imap.example.org",
  "username": "support@example.org",
  "passwordFromSecretName": "SUPPORT_MAIL_PASSWORD",
  "from": "Support <support@example.org>",
  "mode": "manual"
}
```

```php
use Phore\MailClient\MailboxConfig;

$config = MailboxConfig::fromFile('/etc/my-app/mailbox.json'); // offline validation
$client = $config->connect(); // resolve password and connect with verified TLS
```

Provide `SUPPORT_MAIL_PASSWORD` in the process environment or mount a file named
`/var/run/secrets/SUPPORT_MAIL_PASSWORD`. With `passwordFromSecretName`, the configuration stores only the name.
The environment takes precedence; only an **unset** variable falls back to the
file. An empty variable or file raises an exception. One trailing LF or CRLF is
removed from file values; all other whitespace and environment values are preserved.
Missing/unreadable secrets fail before any connection. Named secrets are resolved anew
on every `connect()` and are never cached in the config object.

Alternatively, supply the literal password directly:

```json
{
  "host": "imap.example.org",
  "username": "support@example.org",
  "password": "example-literal-password",
  "mode": "manual"
}
```

`password` is used exactly as supplied, without trimming or environment lookup.
Literal passwords are held privately in PHP's `SensitiveParameterValue`, which
redacts ordinary object dumps and prevents serialization of that credential.
This does not encrypt the original config file: it contains the supplied plaintext.

| Setting | Required / default |
|---|---|
| `host` | Required, IMAP hostname without a URL scheme |
| `username` | Required, nonempty login name |
| `password` | Literal nonempty password string; mutually exclusive with `passwordFromSecretName` |
| `passwordFromSecretName` | Alternative to `password`; name beginning with a letter or underscore, followed by letters, digits, `_`, `-` or `.` |
| `port` | Integer, 1–65535; defaults to `993` |
| `draftsFolder` / `trashFolder` | Exact nonempty names; default `Drafts` / `Trash` |
| `from` | Optional address string, default `null` |
| `mode` | `automatic` (default, same as `MailClient::connect`) or `manual` |

Exactly one of `password` or `passwordFromSecretName` must be present. Both, neither,
null/empty credentials, unknown fields, invalid types and path-like secret names
are rejected. The previous draft field `passwordSecret` is no longer accepted. Config files cannot disable TLS or certificate verification.
The example explicitly uses `manual` to avoid automatic flag changes.

For other secret mount locations, use
`$config->connect(secretsDirectory: '/run/secrets')`. This is an application option,
not a path supplied by the config file. Mounted secret-file symlinks are supported.
For existing YAML-based applications, pass your parser's associative array to
`MailboxConfig::fromArray($settings)`; `fromFile()` itself accepts JSON only.
The config's properties are read-only. Existing template/signature objects can be
passed through `$config->connect(messageDefaults: $defaults)`; they are not stored
in the file. Direct `MailClient::connect(...)` remains available.

The [connection example](examples/api/connect-mail-client.php) supports
`MAIL_CONFIG_FILE=/etc/my-app/mailbox.json`; when set, the file supplies all mailbox
settings instead of the example's individual `MAIL_IMAP_*`, `MAIL_FROM_*` and
`MAIL_MODE` variables.

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

The separate **WEB.DE connection** workflow uses GitHub secrets `EMAIL` and
`EMAIL_PASSWD`. It tests only verified TLS and authentication; it never selects a
mailbox or reads/writes messages. It runs on changes to its workflow/test on the PR
branch and main, and supports manual dispatch once available on the default branch.
WEB.DE must have IMAP access enabled; two-factor accounts may need an app password.
See [WEB.DE's server settings](https://hilfe.web.de/pop-imap/imap/imap-serverdaten.html).

Provider rendering in Thunderbird/Outlook and arbitrary provider interoperability
are not claimed by the local Dovecot tests.
