# Phore Mail Client

PHP 8.5+ IMAP library with immutable `Email` content and explicit server operations.
Install with `composer require phore/mail-client`. Requires `phore/markdown` and
Webklex 6.2.0's pure PHP transport; `ext-imap` is not used.

## Mailbox configuration from a file

Use `MailboxConfig::fromFile()` with one local YAML file as the standard setup. YAML
files are parsed with PHP's `yaml_parse()` function; if that function is unavailable,
loading `.yaml` or `.yml` fails with a `RuntimeException`. JSON remains supported for
installations without the YAML extension.

The [complete JSON reference tree](mailbox-config.reference.json) documents every
field, its type, required status, default (where defined), example and meaning.
It also documents the credential exclusivity rule. This is documentation, not a
loadable mailbox file: actual mailbox files use the flat structure below and
contain exactly one credential option.

Maintain the JSON reference manually whenever mailbox fields, types, defaults,
validation or credential resolution change, as required by [AGENTS.md](AGENTS.md).
Update it in the same change as the implementation.

Store one mailbox in a local `mailbox.yaml`:

```yaml
host: imap.example.org
username: support@example.org
passwordFromSecretName: SUPPORT_MAIL_PASSWORD
from: Support Team <support@example.org>
sentFolder: Sent
junkFolder: Junk
mode: manual
signature: |
  Viele Grüße

  **Support Team**
```

```php
use Phore\MailClient\MailboxConfig;

$config = MailboxConfig::fromFile('/etc/my-app/mailbox.yaml'); // offline validation
$client = $config->connect(); // resolve password and connect with verified TLS
```

The optional `signature` field is Markdown. When present, it becomes the default
signature for new messages, replies/reply-all and forwards. Explicit per-message
signatures still win, and `false` still disables the signature for one message.
This keeps a mailbox identity and its normal signature in one centrally managed
configuration file.

Provide `SUPPORT_MAIL_PASSWORD` in the process environment or mount a file named
`/var/run/secrets/SUPPORT_MAIL_PASSWORD`. With `passwordFromSecretName`, the configuration stores only the name.
The environment takes precedence; only an **unset** variable falls back to the
file. An empty variable or file raises an exception. One trailing LF or CRLF is
removed from file values; all other whitespace and environment values are preserved.
Missing/unreadable secrets fail before any connection. Named secrets are resolved anew
on every `connect()` and are never cached in the config object.

Alternatively, supply the literal password directly:

```yaml
host: imap.example.org
username: support@example.org
password: example-literal-password
mode: manual
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
| `incomingFolder` / `sentFolder` / `junkFolder` | Exact nonempty names; default `INBOX` / `Sent` / `Junk` |
| `from` | Optional address string, default `null` |
| `signature` | Optional nonempty Markdown string, default `null`; used for new/reply/forward |
| `mode` | `automatic` (default, same as `MailClient::connect`) or `manual` |

Exactly one of `password` or `passwordFromSecretName` must be present. Both, neither,
null/empty credentials, unknown fields, invalid types and path-like secret names
are rejected. The previous draft field `passwordSecret` is no longer accepted. Config files cannot disable TLS or certificate verification.
The example explicitly uses `manual` to avoid automatic flag changes.

For other secret mount locations, use
`$config->connect(secretsDirectory: '/run/secrets')`. This is an application option,
not a path supplied by the config file. Mounted secret-file symlinks are supported.
`MailboxConfig::fromArray($settings)` remains available for applications that already
own a parsed settings array, but file-based configuration should normally use
`MailboxConfig::fromFile()` directly. Advanced quote templates or type-specific
signature overrides can still be supplied through `connect(messageDefaults: ...)`;
when a central config signature exists it fills only signature types not explicitly
provided there. Direct `MailClient::connect(...)` remains available.

The [connection example](examples/api/connect-mail-client.php) loads
[examples/api/mailbox.yaml](examples/api/mailbox.yaml) by default. Set
`MAIL_CONFIG_FILE=/etc/my-app/mailbox.yaml` to use another file.

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

## Stateless mailbox primitives

Consumers that build their own durable processing layer can use the configured
mailbox without accessing internal transport classes. `accountId()` returns the stable
connection identity, `fromAddress()` returns the configured sender, and
`folder(MailboxFolder::Inbox|Sent|Drafts|Trash|Junk)` resolves configured standard
folders. `peek($id)` reads a message without automatic flag changes. `moveTo($email,
$folder)` performs a verified same-account IMAP move into an existing exact folder.
Pass `createFolder: true` to create a missing target folder before moving; the default
is `false`. Missing-folder, folder-creation and MOVE errors identify the operation,
source and target folders, Message-ID, sender, date and subject while retaining the
original exception as the previous exception. These APIs keep persistence and consumer
cursors outside the mail client.

## Synchronize changes in any folder

Use `syncFolder()` for additions, persistent flag changes and removals, including
changes made by Thunderbird or another process. Unlike `listNew()` (new configured
incoming-folder UIDs only), this observes previously known messages and accepts any
selectable folder.

```php
// Application-owned functions below persist one cursor per consumer/account/folder.
$folder = 'INBOX';
$cursor = loadCursor($consumerId, $accountId, $folder); // null on first use

do {
    $changes = $client->syncFolder(folder: $folder, cursor: $cursor, limit: 50);

    foreach ($changes->added as $email) {
        // Existing on first sync, newly delivered, copied OR moved into this folder.
        upsertMessage($email);
    }
    foreach ($changes->flagsChanged as $change) {
        // Replace the cached flags with newFlags; oldFlags is the previous observation.
        replaceCachedFlags($change->id, $change->newFlags);
    }
    foreach ($changes->removed as $id) {
        // Remove this folder location from the cache. Do NOT delete mail on the server.
        removeCachedLocation($id);
    }

    // Commit processing and cursor together when possible; otherwise make handlers idempotent.
    saveCursor($consumerId, $accountId, $folder, $changes->nextCursor);
    $cursor = $changes->nextCursor;
} while ($changes->hasMore);
```

The functions `loadCursor`, `upsertMessage`, `replaceCachedFlags`, `removeCachedLocation` and `saveCursor`
belong to your application; the package does not create a database or files.
A runnable read-only CLI example is [sync-folder.php](examples/api/sync-folder.php).

| API field / argument | Meaning and common misconception |
|---|---|
| `folder` | One exact IMAP folder, not the whole account and not recursive. INBOX is case-insensitive; other folder names are preserved. |
| `cursor: null` | Start from an empty observation: **all existing messages** are reported as added across batches, including read mail. It does not mean “only future arrivals”. |
| `added` | Read-only `Email` snapshots newly observed in this folder, not proof of new delivery. Bodies are read; attachments remain lazy descriptors. |
| `flagsChanged` | `FlagChange` values with `id`, `oldFlags`, `newFlags`; no body download for these changes. Flag comparison ignores order/case and session-only `\Recent`. |
| `removed` | Previous location IDs now absent from this folder. Could mean moved or deleted; never a command to delete a message. |
| `nextCursor` | Opaque string containing the observed UID/flag state. Save **only after the whole batch is processed**, including empty batches. Not interchangeable with `listNew()` cursors. |
| `limit` | Maximum total observations returned across all three arrays, 1–500, default 50. Not a server scan limit. |
| `hasMore` | More differences were found in this scan. Continue with `nextCursor`; false does not mean no future changes can arrive. |
| `isInitialSync` | True only for the call receiving null, not every batch in the initial drain. |
| Automatic mode | **Sync never sets Seen or any other flag**, even when automatic mode is enabled. A later explicit `get()` follows its normal automatic-mode behavior. |

### Cursor ownership and moves

Store a separate cursor for **each consumer + account + folder**. For example,
INBOX, Invoices and Archive need three cursors. Independent agents need independent
cursor sets; a shared synchronization service can instead own one set and distribute
its own durable events. Do not let concurrent workers overwrite the same cursor:
serialize processing or use application-level compare-and-swap.

A move is observed as removal in the source and addition in the destination.
Synchronize both folders. IDs encode account, folder, UIDVALIDITY and UID, so the
destination ID is different. Do not assume Message-ID is globally unique or that
these two observations can always be paired unambiguously.

A malformed, wrong-account, wrong-folder or incompatible cursor raises
`InvalidArgumentException` before mailbox I/O. A UIDVALIDITY change raises
`SyncResetRequired` with the affected `folder`: invalidate that folder's cached IDs
and deliberately restart with null. Do not reset on every network error. Missing
folders, connection failures and failed reads throw without returning a new cursor;
retain the last committed cursor and retry after resolving the error.
If an added message disappears while its content is read, retry the same cursor.

### Observation guarantees and implementation limits

This is **polling for net state changes, not an audit log, webhook or exactly-once
event stream**. Intermediate flag changes that are undone, or messages arriving and
leaving between polls, can be invisible. There is no cross-folder atomic snapshot.
Concurrent edits after a message was observed are picked up on a subsequent call;
keep polling after draining `hasMore`. Retry processing idempotently: reusing a cursor
may replay observations, and the next result can differ if the mailbox changed.

The current implementation uses standard IMAP SEARCH plus batched UID FETCH of
UID/FLAGS (100 UIDs per request) on every call. It works without CONDSTORE/QRESYNC.
Only newly observed messages in the returned batch have their bodies fetched.
The cursor embeds the baseline; no hidden in-process state, database, or server-side
cursor is required, including across reconnects. Its size grows with the folder.
Limits: 10,000 current messages per folder, 8 MB encoded cursor, 128 persistent/input
flags per message and 256 bytes per flag. Exceeding limits fails explicitly, never
silently truncates. Body limits apply per message; choose a smaller batch for large mail.

Treat cursors as private application state: they contain folder names, identifiers
and tags, are encoded rather than encrypted, and are not authenticated access tokens.
`IDLE`, `NOTIFY`, `CONDSTORE` and `QRESYNC` optimizations are not implemented by this
method. The public contract leaves room for them without changing how callers
process batches. See [IMAP](https://www.rfc-editor.org/rfc/rfc9051.html) and
[CONDSTORE/QRESYNC](https://www.rfc-editor.org/rfc/rfc7162.html).

## Examples

- [Connection and mode](examples/api/connect-mail-client.php)
- [Mailbox YAML](examples/api/mailbox.yaml)
- [Address values](examples/api/addresses.php)
- [Compose a draft](examples/api/compose-draft.php)
- [Advanced templates and signatures](examples/api/create-message-defaults.php)
- [Incremental reading](examples/api/read-new.php)
- [Reply/reply-all](examples/api/reply.php)
- [Forward](examples/api/forward.php)
- [Flags and trash](examples/api/message-actions.php)

Set `MAIL_CONFIG_FILE` when you want to load a mailbox YAML file other than the
example's `examples/api/mailbox.yaml`. The configured `mode: manual` makes the
examples read-only until an explicit write is requested. No sending API is exposed.
Trash requires native MOVE plus UIDPLUS, with no delete or EXPUNGE fallback.
Configure provider-specific incoming, Sent, Drafts, Trash and Junk folder names when
they differ from the defaults. TLS is always implicit and certificate-verified
(default port 993).

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
See [WEB.DE's server settings](https://hilfe.web.de/pop-imap/imap-serverdaten.html).

Provider rendering in Thunderbird/Outlook and arbitrary provider interoperability
are not claimed by the local Dovecot tests.
