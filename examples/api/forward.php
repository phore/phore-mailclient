<?php

declare(strict_types=1);

use Phore\MailClient\EmailAddress;
use Phore\MailClient\MailClient;

// API DESIGN ONLY. Choose an ID printed by read-new.php.
$emailId = $argv[1] ?? throw new RuntimeException('Usage: php forward.php <email-id>');

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$email = $mailClient->get($emailId);

// Client convenience, still a LOCAL transformation: the configured from identity
// supplies both address and name without repeating them for each forward.
// The new Email has no connection. No network request is made by forward().
// New Email/Message-ID, Fwd: subject, original From/To/Cc/Date/Subject and
// full Markdown body quoted below the new text. The source remains unchanged.
// Source Bcc is never copied into the quote or the new recipients.
// No reply-thread headers: forwarding is not replying to the source sender.
$forward = $mailClient->forward(
    $email,
    to: ['colleague@example.org'],
    markdown: 'For your information.',
    includeAttachments: true,
);

// Override alternative (create instead of the forward above):
// $forward = $mailClient->forward(
//     $email,
//     to: ['colleague@example.org'],
//     markdown: 'For your information.',
//     from: new EmailAddress('office@example.org', 'Office Team'),
// );
//
// Sender resolution:
// - No explicit from: use the configured EmailAddress.
// - Explicit from: replace the WHOLE identity, never merge in the default name.
//   A bare 'office@example.org' therefore deliberately has no display name.
// - To change just the name, supply a new EmailAddress with the same address.
// - No address from either source: throw before creating the forward.
// Never infer the sender from the original email or the IMAP username.
// Email::forward(from: new EmailAddress(...), ...) works fully offline.
// Once resolved, saveDraft() preserves the chosen sender; it does not replace it.
foreach ($forward->from() as $author) {
    echo 'Forward author: ' . $author->toString() . "\n";
}

// Attachment descriptors are retained locally. saveDraft() resolves remote
// attachment content through the client with size limits, not through Email.
// Set includeAttachments: false to forward just the quoted text.
$savedDraft = $mailClient->saveDraft($forward);

// A draft is not a sent forward. No $Forwarded flag is set automatically.
// Only after actual forwarding (e.g. via another client), explicitly call:
// $mailClient->markForwarded($email);
echo 'Forward draft saved as ' . $savedDraft->id() . "\n";
