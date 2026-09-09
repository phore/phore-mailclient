<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

// API DESIGN ONLY. Choose an ID printed by read-new.php.
$emailId = $argv[1] ?? throw new RuntimeException('Usage: php forward.php <email-id>');

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$email = $mailClient->get($emailId);

// Local transformation: new Email/Message-ID, Fwd: subject, source envelope and
// full Markdown body quoted below the new text. The source remains unchanged.
// No reply-thread headers: forwarding is not replying to the source sender.
$forward = $email->forward(
    from: 'support@example.org',
    to: ['colleague@example.org'],
    markdown: 'For your information.',
    includeAttachments: true,
);

// Attachment descriptors are retained locally. saveDraft() resolves remote
// attachment content through the client with size limits, not through Email.
// Set includeAttachments: false to forward just the quoted text.
$savedDraft = $mailClient->saveDraft($forward);

// A draft is not a sent forward. No $Forwarded flag is set automatically.
// Only after actual forwarding (e.g. via another client), explicitly call:
// $mailClient->markForwarded($email);
echo 'Forward draft saved as ' . $savedDraft->id() . "\n";
