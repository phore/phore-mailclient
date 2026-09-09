<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

// API DESIGN ONLY. Choose an ID printed by read-new.php, never an arbitrary email.
$emailId = $argv[1] ?? throw new RuntimeException('Usage: php reply.php <email-id>');

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';
$email = $mailClient->get($emailId);

// reply() is a local transformation returning a new, unsaved Email.
// An explicit From address avoids guessing who is replying (aliases, shared inbox).
$reply = $email->reply(
    from: 'support@example.org',
    markdown: "Thanks for your message.\n\nI will get back to you tomorrow.",
);

// Default: honor Reply-To (otherwise From), prefix Re: once, generate a fresh
// Message-ID, set In-Reply-To to the source Message-ID and extend References.
// If the source has no valid Message-ID, omit thread headers; still quote its body.
// The new answer stays ABOVE the automatically appended Markdown blockquote.
// Original attachments are not copied into replies. Source content is unchanged.
//
// Reply-all alternative (not an additional draft to save):
// $reply = $email->replyAll(
//     from: 'support@example.org',
//     markdown: 'Thanks, everyone.',
//     exclude: ['support-alias@example.org'],
// );
// replyAll() excludes From plus explicit aliases and deduplicates To/Cc.

echo $reply->body()->markdown() . "\n";
$savedDraft = $mailClient->saveDraft($reply);

// Saving a reply draft must NOT mark the original answered or read.
// markAnswered($email) is an explicit server action after an actual reply was sent.
echo 'Reply draft saved as ' . $savedDraft->id() . "\n";
