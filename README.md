# Phore Mail Client

A small, typed PHP 8.5+ API for reading IMAP mailboxes and creating reviewable email drafts. Application content is Markdown; Webklex PHP-IMAP stays behind Phore contracts and `ext-imap` is not required.

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
    $draft = $message
        ->reply()
        ->withMarkdown('Danke für Ihre Nachricht.');
}
```

`reply()`, `replyAll()`, and `forward()` preserve the source relation and append the original message as a Markdown blockquote. Attachments are discovered from the mailbox and opened lazily with an explicit byte limit.

## First implementation slice

- Webklex-backed, read-only IMAP connection, incremental UID reads, typed search, and message lookup.
- Immutable account, message, body, address, attachment, reference, and sync types.
- Fluent Markdown drafts for new messages, replies, reply-all, and forwards.
- Connector, draft-store, and state-store contracts for replaceable integrations.
- No send, delete, move, or automatic remote-content loading API.

Remote IMAP draft persistence and full MIME normalization will follow after provider interoperability tests.
