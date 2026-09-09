<?php

declare(strict_types=1);

use Phore\MailClient\MailClient;

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';

$email = $mailClient->inbox()->listNew(limit: 1)[0]
    ?? throw new RuntimeException('No new email found.');

$forward = $email->forward(
    to: ['colleague@example.org'],
    markdown: 'For your information.',
);

// forward() quotes the original email and carries its regular attachments by
// default. Creating the draft does not claim that the email was actually sent.
$savedDraft = $mailClient->drafts()->save($forward);

echo 'Forward draft saved as ' . $savedDraft->id() . "\n";
