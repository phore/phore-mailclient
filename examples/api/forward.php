<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

// API DESIGN ONLY. Choose an ID printed by read-new.php.
$emailId = $argv[1] ?? throw new RuntimeException('Usage: php forward.php <email-id>');

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$email = $mailClient->get($emailId);

// Client convenience, still a LOCAL transformation: the configured fromAddress
// and fromName supply the sender without having to repeat them for each forward.
// The new Email has no connection. No network request is made by forward().
// New Email/Message-ID, Fwd: subject, source envelope and
// full Markdown body quoted below the new text. The source remains unchanged.
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
//     from: 'office@example.org',
//     fromName: 'Office Team',
// );
//
// Sender resolution:
// - No explicit from: use configured fromAddress and fromName.
// - Explicit from: use that address and ONLY the explicit fromName (or no name).
//   Never attach the default identity's name to a different sender address.
// - Explicit fromName with default address: replace only the display name;
//   an empty string deliberately suppresses the name.
// - No address from either source: throw before creating the forward.
// Never infer the sender from the original email or the IMAP username.
// Email::forward(from: ..., fromName: ...) remains available for fully offline use.
// Once resolved, saveDraft() preserves the chosen sender; it does not replace it.
echo 'Forward sender: ' . $forward->from() . "\n";

// Attachment descriptors are retained locally. saveDraft() resolves remote
// attachment content through the client with size limits, not through Email.
// Set includeAttachments: false to forward just the quoted text.
$savedDraft = $mailClient->saveDraft($forward);

// A draft is not a sent forward. No $Forwarded flag is set automatically.
// Only after actual forwarding (e.g. via another client), explicitly call:
// $mailClient->markForwarded($email);
echo 'Forward draft saved as ' . $savedDraft->id() . "\n";
