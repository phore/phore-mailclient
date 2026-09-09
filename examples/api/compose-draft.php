<?php

declare(strict_types=1);

use Phore\MailClient\Attachment;
use Phore\MailClient\Email;
use Phore\MailClient\MailClient;

/** @var MailClient $mailClient */
$mailClient = require __DIR__ . '/connect-mail-client.php';

// Creating an Email is independent of IMAP. The connected client is needed only
// when the finished message is stored in a remote folder.
$email = new Email(
    to: ['recipient@example.org'],
    subject: 'Project update',
    markdown: "# Project update\n\nThe first milestone is **complete**.",
);

$email = $email->attach(
    Attachment::fromPath(__DIR__ . '/files/project-plan.pdf'),
);

// This stores the email as a draft; it does not send it.
$savedDraft = $mailClient->drafts()->save($email);

echo 'Draft saved as ' . $savedDraft->id() . "\n";
