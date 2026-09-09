<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';

$email = $mailClient->inbox()->listNew(limit: 1)[0]
    ?? throw new RuntimeException('No new email found.');

$reply = $email->reply(
    markdown: "Thanks for your message.\n\nI will get back to you tomorrow.",
);

// reply() sets recipients and thread headers and appends the original email as
// a Markdown blockquote. replyAll() follows the same contract.
$savedDraft = $mailClient->drafts()->save($reply);

echo 'Reply draft saved as ' . $savedDraft->id() . "\n";
