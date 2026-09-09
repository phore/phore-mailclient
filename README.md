# Phore Mail Client

A small, typed PHP 8.5+ API for reading IMAP mailboxes and creating reviewable email drafts. Webklex PHP-IMAP provides protocol access and `phore/markdown` provides body conversion; `ext-imap` is not required.

## Quick start

```php
use Phore\MailClient\Credential\StaticCredential;
use Phore\MailClient\Domain\MailAccount;
use Phore\MailClient\MailClient;

$mail = MailClient::connect(
    MailAccount::imap(
        id: 'support',
        host: 'imap.example.org',
        username: 'support@example.org',
        credential: new StaticCredential($_ENV['IMAP_PASSWORD']),
    ),
);

$report = $mail->testConnection();
$batch = $mail->listNew(limit: 25);

foreach ($batch->messages as $message) {
    echo $message->body->asText();
    echo $message->body->asMarkdown();
    $saved = $message
        ->reply()
        ->withMarkdown('Danke für Ihre Nachricht.')
        ->save();
}
```

`MailBody::asText()` returns the plain alternative or a text fallback, `asMarkdown()` returns the canonical editable body, and `asHtml()` exposes the original HTML alternative explicitly as untrusted source. [`phore/markdown`](https://github.com/phore/phore-markdown) handles common HTML structure and falls back to stripped text.

`draft()`, `reply()`, `replyAll()`, and `forward()` accept Markdown and save through IMAP APPEND without sending. Replies and forwards preserve the source relation and append the original message as a Markdown blockquote; forwards carry recognized attachments.

## Examples

- [`examples/read-new.php`](examples/read-new.php): retrieve new mail and inspect every body representation.
- [`examples/compose-draft.php`](examples/compose-draft.php): compose Markdown and save a new draft.
- [`examples/reply-draft.php`](examples/reply-draft.php): reply and save the quoted result as a draft.
- [`examples/forward-draft.php`](examples/forward-draft.php): forward a message and its attachments as a draft.

All examples read credentials from `MAIL_IMAP_HOST`, `MAIL_IMAP_USERNAME`, and `MAIL_IMAP_PASSWORD`. `MAIL_IMAP_PORT`, `MAIL_IMAP_DRAFTS`, and `MAIL_ACCOUNT_ID` are optional.

## First implementation slice

- Webklex-backed, read-only IMAP connection, incremental UID reads, typed search, and message lookup.
- Immutable account, message, body, address, attachment, reference, and sync types.
- Fluent Markdown drafts for new messages, replies, reply-all, and forwards, persisted to the IMAP draft folder.
- Connector, draft-store, and state-store contracts for replaceable integrations.
- No send, delete, move, or automatic remote-content loading API.

Provider interoperability still needs validation with a dedicated test account.
